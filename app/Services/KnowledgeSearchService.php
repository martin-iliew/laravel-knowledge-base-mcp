<?php

namespace App\Services;

use App\Models\CodeExample;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeResource;
use Illuminate\Support\Collection;
use Laravel\Ai\Reranking;

class KnowledgeSearchService
{
    /**
     * Search the knowledge base using hybrid retrieval (dense + sparse), fuse results,
     * optionally rerank, then assemble an evidence-only payload.
     *
     * Pipeline:
     * 1) Dense candidates (pgvector similarity)
     * 2) Sparse candidates (Postgres FTS)
     * 3) Fuse with Reciprocal Rank Fusion (RRF)
     * 4) Optional AI rerank (Laravel AI reranking)
     * 5) Assemble grouped by KnowledgeItem (snippets + referenced code/resources)
     *
     * @param string      $query         User query text
     * @param int         $limit         Max number of items to return (bounded by config)
     * @param string|null $category      Optional category filter
     * @param array       $tags          Optional tags filter (AND semantics)
     * @param bool        $includeDrafts Whether to include draft/unpublished items
     * @param int|null    $userId        Optional owner scope (created_by)
     * @return array                     Evidence-only payload grouped by item
     */
    public function search(
        string $query,
        int $limit = 5,
        ?string $category = null,
        array $tags = [],
        bool $includeDrafts = false,
        ?int $userId = null
    ): array {
        $cfg = $this->config();

        $limit = $this->normalizeLimit($limit, $cfg['items_limit']);

        $denseIds = $this->dense($query, $cfg['dense_k'], $cfg['min_similarity'], $category, $tags, $includeDrafts, $userId);
        $sparseIds = $this->sparse($query, $cfg['sparse_k'], $cfg['fts_config'], $category, $tags, $includeDrafts, $userId);

        $fusedIds = $this->rrf($denseIds, $sparseIds, $cfg['rrf_k'], $cfg['fused_k']);

        if ($fusedIds === []) {
            return [];
        }

        $orderedChunks = $this->loadChunksInFusedOrder($fusedIds);

        $reranked = $this->rerank($orderedChunks, $query, $cfg['rerank_k']);

        return $this->assemble($reranked, $limit);
    }

    /**
     * Read and normalize all relevant search configuration in one place.
     *
     * @return array<string, int|float|string>
     */
    private function config(): array
    {
        return [
            'items_limit' => (int) config('knowledge.limits.items'),
            'dense_k' => (int) config('knowledge.hybrid.dense_k'),
            'sparse_k' => (int) config('knowledge.hybrid.sparse_k'),
            'fused_k' => (int) config('knowledge.hybrid.fused_k'),
            'rrf_k' => (int) config('knowledge.hybrid.rrf_k'),
            'rerank_k' => (int) config('knowledge.hybrid.rerank_k'),
            'fts_config' => (string) config('knowledge.hybrid.fts_config'),
            'min_similarity' => (float) config('knowledge.defaults.min_similarity'),
        ];
    }

    /**
     * Clamp the requested item limit to [1..configMax].
     *
     * @param int $limit
     * @param int $configMax
     * @return int
     */
    private function normalizeLimit(int $limit, int $configMax): int
    {
        return max(1, min($limit, max(1, $configMax)));
    }

    /**
     * Dense retrieval: vector similarity search against chunk embeddings with item-level filters.
     *
     * @param string      $query
     * @param int         $k
     * @param float       $minSim
     * @param string|null $category
     * @param array       $tags
     * @param bool        $includeDrafts
     * @param int|null    $userId
     * @return array<int> Chunk IDs ordered by vector similarity
     */
    private function dense(
        string $query,
        int $k,
        float $minSim,
        ?string $category,
        array $tags,
        bool $includeDrafts,
        ?int $userId
    ): array {
        return KnowledgeChunk::query()
            ->select(['knowledge_chunks.id'])
            ->whereHas('item', function ($q) use ($category, $tags, $includeDrafts, $userId) {
                if ($userId) {
                    $q->where('created_by', $userId);
                }

                if (! $includeDrafts) {
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

    /**
     * Sparse retrieval: full-text search (FTS) over chunk_tsv with item-level filters.
     *
     * @param string      $query
     * @param int         $k
     * @param string      $fts
     * @param string|null $category
     * @param array       $tags
     * @param bool        $includeDrafts
     * @param int|null    $userId
     * @return array<int> Chunk IDs ordered by FTS rank
     */
    private function sparse(
        string $query,
        int $k,
        string $fts,
        ?string $category,
        array $tags,
        bool $includeDrafts,
        ?int $userId
    ): array {
        $q = KnowledgeChunk::query()
            ->select('knowledge_chunks.id')
            ->join('knowledge_items', 'knowledge_items.id', '=', 'knowledge_chunks.knowledge_item_id')
            ->whereRaw('knowledge_chunks.chunk_tsv @@ websearch_to_tsquery(?, ?)', [$fts, $query]);

        if ($userId) {
            $q->where('knowledge_items.created_by', $userId);
        }

        if (! $includeDrafts) {
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

        return $q->orderByRaw(
            'ts_rank_cd(knowledge_chunks.chunk_tsv, websearch_to_tsquery(?, ?)) DESC',
            [$fts, $query]
        )
            ->limit($k)
            ->pluck('knowledge_chunks.id')
            ->all();
    }

    /**
     * Fuse two ranked ID lists using Reciprocal Rank Fusion (RRF).
     *
     * @param array<int> $dense
     * @param array<int> $sparse
     * @param int        $k    RRF constant
     * @param int        $take Max fused results to return
     * @return array<int>      Fused IDs, best first
     */
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

    /**
     * Load chunks by ID and return them ordered exactly as the fused ID list.
     * Adds a fused_rank attribute matching the fused order index.
     *
     * @param array<int> $fusedIds
     * @return Collection<int, KnowledgeChunk>
     */
    private function loadChunksInFusedOrder(array $fusedIds): Collection
    {
        $chunksById = KnowledgeChunk::query()
            ->with(['item'])
            ->whereIn('id', $fusedIds)
            ->get()
            ->keyBy('id');

        return collect($fusedIds)
            ->values()
            ->map(function ($id, $rank) use ($chunksById) {
                $chunk = $chunksById->get($id);
                if (! $chunk) {
                    return null;
                }

                $chunk->fused_rank = (int) $rank;

                return $chunk;
            })
            ->filter()
            ->values();
    }

    /**
     * Rerank candidate chunks. If AI rerank is not configured or fails, fallback to fused order.
     *
     * @param Collection<int, KnowledgeChunk> $chunks Candidates in fused order
     * @param string                          $query
     * @param int                             $limit  Max results after rerank
     * @return Collection<int, KnowledgeChunk>        Reranked chunks (or fused fallback)
     */
    private function rerank(Collection $chunks, string $query, int $limit): Collection
    {
        if ($chunks->isEmpty()) {
            return $chunks;
        }

        if (! $this->shouldUseAiRerank()) {
            return $chunks->take($limit)->values();
        }

        $docs = $chunks->map(fn ($c) => $this->buildRerankDocument($c))->all();

        try {
            $ranked = Reranking::of($docs)->limit($limit)->rerank($query);
        } catch (\Throwable) {
            // Fallback to fused ranking order when reranking provider credentials are missing.
            return $chunks->take($limit)->values();
        }

        return collect($ranked->all())
            ->map(fn ($r) => [
                'index' => (int) $r->index,
                'score' => (float) $r->score,
            ])
            ->sortByDesc('score')
            ->values()
            ->map(function ($r, $rank) use ($chunks) {
                $c = $chunks->get($r['index']);
                if ($c) {
                    $c->rerank_score = $r['score'];
                    $c->rerank_rank = (int) $rank;
                }

                return $c;
            })
            ->filter()
            ->values();
    }

    /**
     * Build a stable, information-rich document string for reranking.
     * Keeps the same content structure as before: metadata header + chunk text.
     *
     * @param KnowledgeChunk $chunk
     * @return string
     */
    private function buildRerankDocument(KnowledgeChunk $chunk): string
    {
        $title = $chunk->item?->title ?? '';
        $cat = $chunk->item?->category ?? '';
        $meta = is_array($chunk->meta) ? $chunk->meta : [];
        $heading = '';

        if (! empty($meta['heading_path']) && is_array($meta['heading_path'])) {
            $heading = implode(' > ', $meta['heading_path']);
        }

        $p = trim("Title: {$title}\nCategory: {$cat}");

        if ($heading !== '') {
            $p .= "\nSection: {$heading}";
        }

        if (! empty($meta['filename'])) {
            $p .= "\nFile: {$meta['filename']}";
        }

        return trim($p . "\n\n" . $chunk->chunk_text);
    }

    /**
     * Decide whether AI reranking should be used based on feature flag and provider key presence.
     *
     * @return bool
     */
    private function shouldUseAiRerank(): bool
    {
        if (! (bool) config('knowledge.hybrid.enable_ai_rerank')) {
            return false;
        }

        $provider = config('ai.default_for_reranking');
        if (! is_string($provider) || $provider === '') {
            return false;
        }

        $providerKey = config("ai.providers.{$provider}.key");

        return is_string($providerKey) && trim($providerKey) !== '';
    }

    /**
     * Assemble evidence-only response grouped by KnowledgeItem.
     *
     * Output structure per item:
     * - item: id/slug/title/category/tags/updated_at
     * - snippets: chunk-level evidence (ordered by rerank score when present, else fused order)
     * - code_examples: only referenced by snippets (capped)
     * - resources: only referenced by snippets (capped)
     *
     * @param Collection<int, KnowledgeChunk> $chunks
     * @param int                             $limit
     * @return array
     */
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
            if (! $item) {
                continue;
            }

            $itemId = $item->id;

            if (! isset($byItem[$itemId])) {
                $updatedAt = $item->updated_at;

                $byItem[$itemId] = [
                    'item' => [
                        'id' => $item->id,
                        'slug' => $item->slug,
                        'title' => $item->title,
                        'category' => $item->category,
                        'tags' => $item->tags ?? [],
                        'updated_at' => $updatedAt ? $updatedAt->clone()->utc()->toIso8601String() : null,
                    ],
                    'snippets' => [],
                    'code_examples' => [],
                    'resources' => [],
                ];
            }

            if (count($byItem[$itemId]['snippets']) >= $maxChunksPerItem) {
                continue;
            }

            $rerankScore = $chunk->getAttribute('rerank_score');
            $rerankRank = $chunk->getAttribute('rerank_rank');
            $fusedRank = $chunk->getAttribute('fused_rank');

            $byItem[$itemId]['snippets'][] = [
                'source_type' => $chunk->source_type,
                'source_id' => $chunk->source_id,
                'chunk_kind' => $chunk->chunk_kind,
                'chunk_index' => $chunk->chunk_index,
                'meta' => $chunk->meta ?? [],
                'score' => is_numeric($rerankScore) ? (float) $rerankScore : null,
                'text' => $chunk->chunk_text,
                '_fused_rank' => is_numeric($fusedRank) ? (int) $fusedRank : PHP_INT_MAX,
                '_rerank_rank' => is_numeric($rerankRank) ? (int) $rerankRank : null,
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
            $row['snippets'] = $this->sortSnippets($row['snippets']);

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

    /**
     * Sort snippets with the same precedence as before:
     * 1) Snippets with score first
     * 2) Higher score first
     * 3) Lower rerank_rank first (when score ties)
     * 4) Lower fused_rank first (final tie-break)
     *
     * @param array<int, array> $snippets
     * @return array<int, array>
     */
    private function sortSnippets(array $snippets): array
    {
        return collect($snippets)
            ->sort(function (array $a, array $b): int {
                $aHasScore = $a['score'] !== null;
                $bHasScore = $b['score'] !== null;

                if ($aHasScore !== $bHasScore) {
                    return $aHasScore ? -1 : 1;
                }

                if ($aHasScore && $bHasScore) {
                    if ($a['score'] !== $b['score']) {
                        return $a['score'] < $b['score'] ? 1 : -1;
                    }

                    $aRerank = $a['_rerank_rank'] ?? PHP_INT_MAX;
                    $bRerank = $b['_rerank_rank'] ?? PHP_INT_MAX;

                    if ($aRerank !== $bRerank) {
                        return $aRerank <=> $bRerank;
                    }
                }

                return $a['_fused_rank'] <=> $b['_fused_rank'];
            })
            ->map(function (array $snippet): array {
                unset($snippet['_fused_rank'], $snippet['_rerank_rank']);

                return $snippet;
            })
            ->values()
            ->all();
    }
}
