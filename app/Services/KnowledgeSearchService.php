<?php

namespace App\Services;

use App\Models\CodeExample;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeResource;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Collection;
use Laravel\Ai\Reranking;

class KnowledgeSearchService
{
    public function search(
        string $query,
        int $limit = 5,
        ?string $category = null,
        array $tags = [],
        bool $includeDrafts = false,
        ?int $userId = null
    ): array {
        $limit = max(1, min($limit, (int) config('knowledge.limits.items')));

        $denseK = (int) config('knowledge.hybrid.dense_k');
        $sparseK = (int) config('knowledge.hybrid.sparse_k');
        $fusedK = (int) config('knowledge.hybrid.fused_k');
        $rrfK = (int) config('knowledge.hybrid.rrf_k');
        $rerankK = (int) config('knowledge.hybrid.rerank_k');
        $fts = (string) config('knowledge.hybrid.fts_config');
        $minSim = (float) config('knowledge.defaults.min_similarity');

        $denseIds = $this->dense($query, $denseK, $minSim, $category, $tags, $includeDrafts, $userId);
        $sparseIds = $this->sparse($query, $sparseK, $fts, $category, $tags, $includeDrafts, $userId);

        $fusedIds = $this->rrf($denseIds, $sparseIds, $rrfK, $fusedK);

        if ($fusedIds === []) {
            return [];
        }

        $chunks = KnowledgeChunk::query()
            ->with(['item'])
            ->whereIn('id', $fusedIds)
            ->get()
            ->keyBy('id');

        $ordered = collect($fusedIds)->map(fn ($id) => $chunks->get($id))->filter()->values();

        $reranked = $this->rerank($ordered, $query, $rerankK);

        return $this->assemble($reranked, $limit);
    }

    private function dense(string $query, int $k, float $minSim, ?string $category, array $tags, bool $includeDrafts, ?int $userId): array
    {
        return KnowledgeChunk::query()
            ->select(['knowledge_chunks.id'])
            ->whereHas('item', function ($q) use ($category, $tags, $includeDrafts, $userId) {
                if ($userId) { 
                    $q->where('created_by', $userId);
                }

                if (!$includeDrafts) {
                    $q->published();
                }

                if ($category) {
                    $q->where('category', $category);
                }

                foreach ($tags as $tag) {
                    $q->whereJsonContains('tags', $tag);
                }
            })
            ->whereVectorSimilarTo('embedding', $query, minSimilarity: $minSim)
            ->limit($k)
            ->pluck('id')
            ->all();
    }

    private function sparse(string $query, int $k, string $fts, ?string $category, array $tags, bool $includeDrafts, ?int $userId): array
    {
        $q = KnowledgeChunk::query()
            ->select('knowledge_chunks.id')
            ->join('knowledge_items', 'knowledge_items.id', '=', 'knowledge_chunks.knowledge_item_id')
            ->whereRaw("knowledge_chunks.chunk_tsv @@ websearch_to_tsquery(?, ?)", [$fts, $query]);

        if ($userId) {
            $q->where('knowledge_items.created_by', $userId);
        }
        
        if (!$includeDrafts) {
            $q->where('knowledge_items.status', 'published')
                ->where(function ($w) {
                    $w->whereNull('knowledge_items.published_at')->orWhere('knowledge_items.published_at', '<=', now());
                });
        }

        if ($category) {
            $q->where('knowledge_items.category', $category);
        }

        foreach ($tags as $tag) {
            $q->whereJsonContains('knowledge_items.tags', $tag);
        }

        return $q->orderByRaw("ts_rank_cd(knowledge_chunks.chunk_tsv, websearch_to_tsquery(?, ?)) DESC", [$fts, $query])
            ->limit($k)
            ->pluck('knowledge_chunks.id')
            ->all();
    }

    private function rrf(array $dense, array $sparse, int $k, int $take): array
    {
        $scores = [];

        foreach ($dense as $rank => $id) {
            $scores[$id] = ($scores[$id] ?? 0.0) + (1.0 / ($k + $rank + 1));
        }

        foreach ($sparse as $rank => $id) {
            $scores[$id] = ($scores[$id] ?? 0.0) + (1.0 / ($k + $rank + 1));
        }

        arsort($scores);

        return array_slice(array_keys($scores), 0, $take);
    }

    private function rerank(Collection $chunks, string $query, int $limit): Collection
    {
        if ($chunks->isEmpty()) {
            return $chunks;
        }

        $docs = $chunks->map(function ($c) {
            $title = $c->item?->title ?? '';
            $cat = $c->item?->category ?? '';
            $meta = is_array($c->meta) ? $c->meta : [];
            $heading = '';

            if (!empty($meta['heading_path']) && is_array($meta['heading_path'])) {
                $heading = implode(' > ', $meta['heading_path']);
            }

            $p = trim("Title: {$title}\nCategory: {$cat}");
            if ($heading !== '') {
                $p .= "\nSection: {$heading}";
            }

            if (!empty($meta['filename'])) {
                $p .= "\nFile: {$meta['filename']}";
            }

            return trim($p . "\n\n" . $c->chunk_text);
        })->all();

        try {
            $ranked = Reranking::of($docs)->limit($limit)->rerank($query);
        } catch (RequestException) {
            // Fallback to fused ranking order when reranking provider credentials are missing.
            return $chunks->values();
        }

        return collect($ranked->all())
            ->map(fn ($r) => [
                'index' => (int) $r->index,
                'score' => (float) $r->score,
            ])
            ->map(function ($r) use ($chunks) {
                $c = $chunks[$r['index']] ?? null;
                if ($c) {
                    $c->rerank_score = $r['score'];
                }
                return $c;
            })
            ->filter()
            ->values();
    }

    private function assemble(Collection $chunks, int $limit): array
    {
        $maxChunksPerItem = (int) config('knowledge.limits.chunks_per_item');
        $maxCode = (int) config('knowledge.limits.code_examples_per_item');
        $maxRes = (int) config('knowledge.limits.resources_per_item');

        $byItem = [];
        $codeIds = [];
        $resourceIds = [];

        foreach ($chunks as $chunk) {
            $item = $chunk->item;
            if (!$item) {
                continue;
            }

            $id = $item->id;

            if (!isset($byItem[$id])) {
                $byItem[$id] = [
                    'item' => [
                        'id' => $item->id,
                        'slug' => $item->slug,
                        'title' => $item->title,
                        'category' => $item->category,
                        'tags' => $item->tags ?? [],
                        'updated_at' => (string) $item->updated_at,
                    ],
                    'snippets' => [],
                    'code_examples' => [],
                    'resources' => [],
                ];
            }

            if (count($byItem[$id]['snippets']) >= $maxChunksPerItem) {
                continue;
            }

            $byItem[$id]['snippets'][] = [
                'source_type' => $chunk->source_type,
                'source_id' => $chunk->source_id,
                'chunk_kind' => $chunk->chunk_kind,
                'chunk_index' => $chunk->chunk_index,
                'meta' => $chunk->meta ?? [],
                'score' => property_exists($chunk, 'rerank_score') ? $chunk->rerank_score : null,
                'text' => $chunk->chunk_text,
            ];

            if ($chunk->source_type === 'code' && $chunk->source_id) {
                $codeIds[] = (int) $chunk->source_id;
            }

            if ($chunk->source_type === 'resource' && $chunk->source_id) {
                $resourceIds[] = (int) $chunk->source_id;
            }
        }

        $codeById = CodeExample::query()
            ->whereIn('id', array_values(array_unique($codeIds)))
            ->get()
            ->keyBy('id');

        $resById = KnowledgeResource::query()
            ->whereIn('id', array_values(array_unique($resourceIds)))
            ->get()
            ->keyBy('id');

        foreach ($byItem as &$row) {
            $snips = $row['snippets'];

            $row['code_examples'] = collect($snips)
                ->filter(fn ($s) => $s['source_type'] === 'code' && $s['source_id'])
                ->map(fn ($s) => $codeById->get($s['source_id']))
                ->filter()
                ->unique('id')
                ->take($maxCode)
                ->map(fn ($ex) => [
                    'id' => $ex->id,
                    'title' => $ex->title,
                    'language' => $ex->language,
                    'filename' => $ex->filename,
                    'code' => $ex->code,
                ])
                ->values()
                ->all();

            $row['resources'] = collect($snips)
                ->filter(fn ($s) => $s['source_type'] === 'resource' && $s['source_id'])
                ->map(fn ($s) => $resById->get($s['source_id']))
                ->filter()
                ->unique('id')
                ->take($maxRes)
                ->map(fn ($r) => [
                    'id' => $r->id,
                    'type' => $r->type,
                    'label' => $r->label,
                    'url' => $r->url,
                    'storage_path' => $r->storage_path,
                    'mime' => $r->mime,
                    'size' => $r->size,
                ])
                ->values()
                ->all();
        }

        return array_values(array_slice($byItem, 0, $limit, true));
    }
}
