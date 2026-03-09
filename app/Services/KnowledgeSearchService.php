<?php

namespace App\Services;

use App\Models\CodeExample;
use App\Models\KnowledgeAccountAccess;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeResource;
use App\Services\Embeddings\EmbeddingDimensionResolver;
use App\Services\Embeddings\EmbeddingManager;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Laravel\Ai\Reranking;

class KnowledgeSearchService
{
    public function __construct(
        protected EmbeddingManager $embeddingManager,
    ) {}

    /**
     * Search the knowledge base using hybrid retrieval (dense + sparse), then optionally rerank.
     *
     * @param  array<int, string>  $tags
     * @return array<int, array<string, mixed>>
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
        $ownerScopeIds = $this->resolveOwnerScopeIds($userId);

        $denseK = $cfg['dense_k'];
        $sparseK = $cfg['sparse_k'];
        $fusedK = $cfg['fused_k'];
        $fallbackK = $cfg['fused_k'];
        $minSimilarity = $cfg['min_similarity'];
        $denseWeight = 0.5;
        $sparseWeight = 0.5;
        $isSingleToken = false;

        if ((bool) config('knowledge.search_v2.enabled', false)) {
            $profile = $this->queryProfile($query, (array) config('knowledge.search_v2.profiles', []));

            $denseK = (int) ($profile['dense_k'] ?? $denseK);
            $sparseK = (int) ($profile['sparse_k'] ?? $sparseK);
            $fusedK = (int) ($profile['fused_k'] ?? $fusedK);
            $fallbackK = (int) ($profile['fallback_k'] ?? $fallbackK);
            $denseWeight = (float) ($profile['dense_weight'] ?? $denseWeight);
            $sparseWeight = (float) ($profile['sparse_weight'] ?? $sparseWeight);
            $isSingleToken = (bool) ($profile['is_single_token'] ?? false);

            $profileMinSimilarity = $profile['min_similarity'] ?? $minSimilarity;
            $minSimilarity = is_numeric($profileMinSimilarity)
                ? (float) $profileMinSimilarity
                : null;
        }

        $denseIds = $this->dense(
            query: $query,
            k: $denseK,
            minSim: $minSimilarity,
            category: $category,
            tags: $tags,
            includeDrafts: $includeDrafts,
            ownerScopeIds: $ownerScopeIds,
        );

        $sparseIds = $this->sparse(
            query: $query,
            k: $sparseK,
            fallbackK: $fallbackK,
            fts: $cfg['fts_config'],
            category: $category,
            tags: $tags,
            includeDrafts: $includeDrafts,
            ownerScopeIds: $ownerScopeIds,
            singleToken: $isSingleToken,
        );

        $fusedIds = $this->weightedRrf(
            dense: $denseIds,
            sparse: $sparseIds,
            denseWeight: $denseWeight,
            sparseWeight: $sparseWeight,
            k: $cfg['rrf_k'],
            take: $fusedK
        );

        if ($fusedIds === []) {
            return [];
        }

        $orderedChunks = $this->loadChunksInFusedOrder($fusedIds);
        $rerankedChunks = $this->rerank($orderedChunks, $query, $cfg['rerank_k']);

        return $this->assemble($rerankedChunks, $limit);
    }

    /**
     * @return array{items_limit:int,dense_k:int,sparse_k:int,fused_k:int,rrf_k:int,rerank_k:int,fts_config:string,min_similarity:float}
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

    private function normalizeLimit(int $limit, int $configMax): int
    {
        return max(1, min($limit, max(1, $configMax)));
    }

    /**
     * @param  array<int, string>  $tags
     * @return array<int>
     */
    private function dense(
        string $query,
        int $k,
        ?float $minSim,
        ?string $category,
        array $tags,
        bool $includeDrafts,
        ?array $ownerScopeIds
    ): array {
        if ($k <= 0) {
            return [];
        }

        // When using jina-local we must pre-generate the query vector so the
        // correct "Query: " prefix is applied. For laravel-ai the raw string
        // is passed through to whereVectorSimilarTo which handles embedding
        // generation internally via Str::toEmbeddings().
        $embeddingDims = app(EmbeddingDimensionResolver::class)->resolve();

        /** @var array<int, float>|string $vectorInput */
        $vectorInput = $this->embeddingManager->isJinaLocal()
            ? $this->embeddingManager->embedQuery($query, $embeddingDims)
            : $query;

        $queryBuilder = KnowledgeChunk::query()
            ->select(['knowledge_chunks.id'])
            ->whereHas('item', function (Builder $itemQuery) use (
                $ownerScopeIds,
                $category,
                $tags,
                $includeDrafts
            ): void {
                $this->applyItemFilters(
                    query: $itemQuery,
                    ownerScopeIds: $ownerScopeIds,
                    category: $category,
                    tags: $tags,
                    includeDrafts: $includeDrafts
                );
            });

        if ($minSim === null) {
            $queryBuilder->whereVectorSimilarTo('embedding', $vectorInput);
        } else {
            $queryBuilder->whereVectorSimilarTo('embedding', $vectorInput, minSimilarity: $minSim);
        }

        return $queryBuilder
            ->limit($k)
            ->pluck('id')
            ->all();
    }

    /**
     * @param  array<int, string>  $tags
     * @return array<int>
     */
    private function sparse(
        string $query,
        int $k,
        int $fallbackK,
        string $fts,
        ?string $category,
        array $tags,
        bool $includeDrafts,
        ?array $ownerScopeIds,
        bool $singleToken
    ): array {
        if ($k <= 0) {
            return [];
        }

        $strictIds = $this->sparseStrict(
            query: $query,
            k: $k,
            fts: $fts,
            category: $category,
            tags: $tags,
            includeDrafts: $includeDrafts,
            ownerScopeIds: $ownerScopeIds
        );

        if (count($strictIds) >= $k) {
            return $strictIds;
        }

        $fallbackDepth = max($k, $fallbackK);
        $relaxedIds = $this->sparseRelaxed(
            query: $query,
            k: $fallbackDepth,
            fts: $fts,
            category: $category,
            tags: $tags,
            includeDrafts: $includeDrafts,
            ownerScopeIds: $ownerScopeIds
        );

        $fuzzyIds = $this->sparseFuzzy(
            query: $query,
            k: $fallbackDepth,
            category: $category,
            tags: $tags,
            includeDrafts: $includeDrafts,
            ownerScopeIds: $ownerScopeIds,
            singleToken: $singleToken
        );

        return $this->weightedRrfMany(
            lists: [
                ['ids' => $strictIds, 'weight' => 0.6],
                ['ids' => $relaxedIds, 'weight' => 0.25],
                ['ids' => $fuzzyIds, 'weight' => 0.15],
            ],
            k: 60,
            take: $k
        );
    }

    /**
     * @param  array<int, string>  $tags
     * @param  array<int>|null  $ownerScopeIds
     * @return array<int>
     */
    private function sparseStrict(
        string $query,
        int $k,
        string $fts,
        ?string $category,
        array $tags,
        bool $includeDrafts,
        ?array $ownerScopeIds
    ): array {
        try {
            $builder = KnowledgeChunk::query()
                ->select('knowledge_chunks.id')
                ->join('knowledge_items', 'knowledge_items.id', '=', 'knowledge_chunks.knowledge_item_id')
                ->whereRaw('knowledge_chunks.search_tsv @@ websearch_to_tsquery(?, ?)', [$fts, $query]);

            $this->applyItemFilters(
                query: $builder,
                ownerScopeIds: $ownerScopeIds,
                category: $category,
                tags: $tags,
                includeDrafts: $includeDrafts,
                table: 'knowledge_items'
            );

            return $builder
                ->orderByRaw(
                    'ts_rank_cd(knowledge_chunks.search_tsv, websearch_to_tsquery(?, ?)) DESC',
                    [$fts, $query]
                )
                ->limit($k)
                ->pluck('knowledge_chunks.id')
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @param  array<int, string>  $tags
     * @param  array<int>|null  $ownerScopeIds
     * @return array<int>
     */
    private function sparseRelaxed(
        string $query,
        int $k,
        string $fts,
        ?string $category,
        array $tags,
        bool $includeDrafts,
        ?array $ownerScopeIds
    ): array {
        $tokens = $this->tokenizeQuery($query);

        if ($tokens === []) {
            return [];
        }

        $prefixOrQuery = $this->buildPrefixOrTsquery($tokens);

        if ($prefixOrQuery === null) {
            return [];
        }

        try {
            $builder = KnowledgeChunk::query()
                ->select('knowledge_chunks.id')
                ->join('knowledge_items', 'knowledge_items.id', '=', 'knowledge_chunks.knowledge_item_id')
                ->whereRaw('knowledge_chunks.search_tsv @@ to_tsquery(?, ?)', [$fts, $prefixOrQuery]);

            $this->applyItemFilters(
                query: $builder,
                ownerScopeIds: $ownerScopeIds,
                category: $category,
                tags: $tags,
                includeDrafts: $includeDrafts,
                table: 'knowledge_items'
            );

            return $builder
                ->orderByRaw(
                    'ts_rank_cd(knowledge_chunks.search_tsv, to_tsquery(?, ?)) DESC',
                    [$fts, $prefixOrQuery]
                )
                ->limit($k)
                ->pluck('knowledge_chunks.id')
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @param  array<int, string>  $tags
     * @param  array<int>|null  $ownerScopeIds
     * @return array<int>
     */
    private function sparseFuzzy(
        string $query,
        int $k,
        ?string $category,
        array $tags,
        bool $includeDrafts,
        ?array $ownerScopeIds,
        bool $singleToken
    ): array {
        $normalized = mb_strtolower(trim($query));

        if ($normalized === '') {
            return [];
        }

        $minSimilarity = $singleToken ? 0.25 : 0.2;
        $similaritySql = 'GREATEST(
            word_similarity(?, COALESCE(knowledge_chunks.title_text, \'\')),
            word_similarity(?, COALESCE(knowledge_chunks.heading_path_text, \'\')),
            word_similarity(?, COALESCE(knowledge_chunks.tags_text, \'\')),
            word_similarity(?, COALESCE(knowledge_chunks.category_text, \'\'))
        )';

        try {
            $builder = KnowledgeChunk::query()
                ->select('knowledge_chunks.id')
                ->join('knowledge_items', 'knowledge_items.id', '=', 'knowledge_chunks.knowledge_item_id')
                ->whereRaw("{$similaritySql} >= ?", [
                    $normalized,
                    $normalized,
                    $normalized,
                    $normalized,
                    $minSimilarity,
                ]);

            $this->applyItemFilters(
                query: $builder,
                ownerScopeIds: $ownerScopeIds,
                category: $category,
                tags: $tags,
                includeDrafts: $includeDrafts,
                table: 'knowledge_items'
            );

            return $builder
                ->orderByRaw("{$similaritySql} DESC", [
                    $normalized,
                    $normalized,
                    $normalized,
                    $normalized,
                ])
                ->limit($k)
                ->pluck('knowledge_chunks.id')
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @param  array<int>|null  $ownerScopeIds
     * @param  array<int, string>  $tags
     */
    private function applyItemFilters(
        Builder $query,
        ?array $ownerScopeIds,
        ?string $category,
        array $tags,
        bool $includeDrafts,
        string $table = 'knowledge_items'
    ): void {
        if ($ownerScopeIds !== null) {
            if ($ownerScopeIds === []) {
                $query->whereRaw('1 = 0');

                return;
            }

            $query->whereIn("{$table}.created_by", $ownerScopeIds);
        }

        if (! $includeDrafts) {
            $query->where("{$table}.status", 'published')
                ->where(function (Builder $publishedQuery) use ($table): void {
                    $publishedQuery->whereNull("{$table}.published_at")
                        ->orWhere("{$table}.published_at", '<=', now());
                });
        }

        if ($category !== null && $category !== '') {
            $query->where("{$table}.category", $category);
        }

        foreach ($tags as $tag) {
            $query->whereJsonContains("{$table}.tags", $tag);
        }
    }

    /**
     * @return array<int, string>
     */
    private function tokenizeQuery(string $query): array
    {
        return collect(preg_split('/[^[:alnum:]_]+/u', mb_strtolower($query)) ?: [])
            ->map(fn ($token): string => is_string($token) ? trim($token) : '')
            ->filter(fn (string $token): bool => $token !== '' && mb_strlen($token) >= 2)
            ->unique()
            ->take(12)
            ->values()
            ->all();
    }

    /**
     * @param  array<int, string>  $tokens
     */
    private function buildPrefixOrTsquery(array $tokens): ?string
    {
        if ($tokens === []) {
            return null;
        }

        $query = collect($tokens)
            ->map(fn (string $token): string => preg_replace('/[^[:alnum:]_]/u', '', $token) ?: '')
            ->filter(fn (string $token): bool => $token !== '')
            ->map(fn (string $token): string => "{$token}:*")
            ->implode(' | ');

        return $query !== '' ? $query : null;
    }

    /**
     * @return array<int>|null
     */
    private function resolveOwnerScopeIds(?int $userId): ?array
    {
        if ($userId === null) {
            return null;
        }

        $permissions = KnowledgeAccountAccess::query()
            ->where('grantee_user_id', $userId)
            ->pluck('permission')
            ->filter(fn ($permission): bool => is_string($permission) && in_array($permission, ['viewer', 'editor'], true))
            ->unique()
            ->values()
            ->all();

        if ($permissions !== []) {
            return null;
        }

        return [$userId];
    }

    /**
     * @param  array<int>  $dense
     * @param  array<int>  $sparse
     * @return array<int>
     */
    private function rrf(array $dense, array $sparse, int $k, int $take): array
    {
        return $this->weightedRrf($dense, $sparse, 0.5, 0.5, $k, $take);
    }

    /**
     * @param  array<int>  $fusedIds
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
            ->map(function (int $id, int $rank) use ($chunksById): ?KnowledgeChunk {
                $chunk = $chunksById->get($id);

                if (! $chunk) {
                    return null;
                }

                $chunk->fused_rank = $rank;

                return $chunk;
            })
            ->filter()
            ->values();
    }

    /**
     * @param  Collection<int, KnowledgeChunk>  $chunks
     * @return Collection<int, KnowledgeChunk>
     */
    private function rerank(Collection $chunks, string $query, int $limit): Collection
    {
        if ($chunks->isEmpty()) {
            return $chunks;
        }

        if (! $this->shouldUseAiRerank()) {
            return $chunks->take($limit)->values();
        }

        $docs = $chunks->map(fn (KnowledgeChunk $chunk): string => $this->buildRerankDocument($chunk))->all();

        try {
            $ranked = Reranking::of($docs)
                ->limit($limit)
                ->rerank($query);
        } catch (\Throwable) {
            return $chunks->take($limit)->values();
        }

        return collect($ranked->all())
            ->map(fn ($rank): array => [
                'index' => (int) $rank->index,
                'score' => (float) $rank->score,
            ])
            ->sortByDesc('score')
            ->values()
            ->map(function (array $rankedChunk, int $rank) use ($chunks): ?KnowledgeChunk {
                $chunk = $chunks->get($rankedChunk['index']);

                if (! $chunk) {
                    return null;
                }

                $chunk->rerank_score = $rankedChunk['score'];
                $chunk->rerank_rank = $rank;

                return $chunk;
            })
            ->filter()
            ->values();
    }

    private function buildRerankDocument(KnowledgeChunk $chunk): string
    {
        $title = $chunk->item?->title ?? '';
        $category = $chunk->item?->category ?? '';
        $meta = is_array($chunk->meta) ? $chunk->meta : [];
        $heading = '';

        if (! empty($meta['heading_path']) && is_array($meta['heading_path'])) {
            $heading = implode(' > ', $meta['heading_path']);
        }

        $document = trim("Title: {$title}\nCategory: {$category}");

        if ($heading !== '') {
            $document .= "\nSection: {$heading}";
        }

        if (! empty($meta['filename'])) {
            $document .= "\nFile: {$meta['filename']}";
        }

        return trim($document."\n\n".$chunk->chunk_text);
    }

    private function shouldUseAiRerank(): bool
    {
        if (! (bool) config('knowledge.hybrid.enable_ai_rerank')) {
            return false;
        }

        $provider = config('ai.default_for_reranking');

        if (! is_string($provider) || trim($provider) === '') {
            return false;
        }

        $providerKey = config("ai.providers.{$provider}.key");

        return is_string($providerKey) && trim($providerKey) !== '';
    }

    /**
     * @param  Collection<int, KnowledgeChunk>  $chunks
     * @return array<int, array<string, mixed>>
     */
    private function assemble(Collection $chunks, int $limit): array
    {
        $maxChunksPerItem = (int) config('knowledge.limits.chunks_per_item');
        $maxCode = (int) config('knowledge.limits.code_examples_per_item');
        $maxResources = (int) config('knowledge.limits.resources_per_item');

        /** @var array<int, array<string, mixed>> $byItem */
        $byItem = [];
        $codeIds = [];
        $resourceIds = [];

        foreach ($chunks as $chunk) {
            $item = $chunk->item;

            if (! $item) {
                continue;
            }

            $itemId = (int) $item->id;

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

            if ($chunk->source_type === 'code' && $chunk->source_id !== null) {
                $codeIds[] = (int) $chunk->source_id;
            }

            if ($chunk->source_type === 'resource' && $chunk->source_id !== null) {
                $resourceIds[] = (int) $chunk->source_id;
            }
        }

        $codeById = CodeExample::query()
            ->whereIn('id', array_values(array_unique($codeIds)))
            ->get()
            ->keyBy('id');

        $resourceById = KnowledgeResource::query()
            ->whereIn('id', array_values(array_unique($resourceIds)))
            ->get()
            ->keyBy('id');

        foreach ($byItem as &$row) {
            $snippets = $this->sortSnippets($row['snippets']);
            $row['snippets'] = $snippets;

            $row['code_examples'] = collect($snippets)
                ->filter(fn (array $snippet): bool => $snippet['source_type'] === 'code' && $snippet['source_id'] !== null)
                ->map(fn (array $snippet): ?CodeExample => $codeById->get($snippet['source_id']))
                ->filter()
                ->unique('id')
                ->take($maxCode)
                ->map(fn (CodeExample $example): array => [
                    'id' => $example->id,
                    'title' => $example->title,
                    'language' => $example->language,
                    'filename' => $example->filename,
                    'code' => $example->code,
                ])
                ->values()
                ->all();

            $row['resources'] = collect($snippets)
                ->filter(fn (array $snippet): bool => $snippet['source_type'] === 'resource' && $snippet['source_id'] !== null)
                ->map(fn (array $snippet): ?KnowledgeResource => $resourceById->get($snippet['source_id']))
                ->filter()
                ->unique('id')
                ->take($maxResources)
                ->map(fn (KnowledgeResource $resource): array => [
                    'id' => $resource->id,
                    'type' => $resource->type,
                    'label' => $resource->label,
                    'url' => $resource->url,
                    'storage_path' => $resource->storage_path,
                    'mime' => $resource->mime,
                    'size' => $resource->size,
                ])
                ->values()
                ->all();
        }
        unset($row);

        return array_values(array_slice($byItem, 0, $limit, true));
    }

    /**
     * @param  array<int, array<string, mixed>>  $snippets
     * @return array<int, array<string, mixed>>
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

    /**
     * Build a query profile for dynamic retrieval weighting.
     *
     * @param  array<string, array<string, mixed>>  $profiles
     * @return array<string, mixed>
     */
    private function queryProfile(string $query, array $profiles): array
    {
        $tokens = preg_split('/\s+/', trim($query)) ?: [];
        $tokenCount = count(array_filter($tokens, fn (string $token): bool => $token !== ''));

        $isSingleToken = $tokenCount <= 1;
        $isLongQuery = $tokenCount >= 8;

        $base = [
            'dense_k' => 60,
            'sparse_k' => 60,
            'fused_k' => 80,
            'fallback_k' => 80,
            'min_similarity' => 0.35,
            'dense_weight' => 0.5,
            'sparse_weight' => 0.5,
        ];

        $selected = $profiles['search_v2_short'] ?? [];

        if ($isSingleToken) {
            $selected = $profiles['search_v2_single_token'] ?? $selected;
        } elseif ($isLongQuery) {
            $selected = $profiles['search_v2_long'] ?? $selected;
        }

        $profile = array_merge($base, $selected);

        if ($isSingleToken) {
            $profile['min_similarity'] = null;
        }

        $profile['is_single_token'] = $isSingleToken;

        return $profile;
    }

    /**
     * Weighted reciprocal rank fusion.
     *
     * @param  array<int>  $dense
     * @param  array<int>  $sparse
     * @return array<int>
     */
    private function weightedRrf(
        array $dense,
        array $sparse,
        float $denseWeight,
        float $sparseWeight,
        int $k,
        int $take
    ): array {
        $scores = [];

        foreach ($dense as $rank => $id) {
            $scores[$id] = ($scores[$id] ?? 0.0) + ($denseWeight / ($k + $rank + 1));
        }

        foreach ($sparse as $rank => $id) {
            $scores[$id] = ($scores[$id] ?? 0.0) + ($sparseWeight / ($k + $rank + 1));
        }

        arsort($scores);

        return array_slice(array_map('intval', array_keys($scores)), 0, $take);
    }

    /**
     * @param  array<int, array{ids:array<int>,weight:float}>  $lists
     * @return array<int>
     */
    private function weightedRrfMany(array $lists, int $k, int $take): array
    {
        $scores = [];

        foreach ($lists as $list) {
            $weight = (float) ($list['weight'] ?? 0.0);
            $ids = $list['ids'] ?? [];

            if ($weight <= 0.0 || $ids === []) {
                continue;
            }

            foreach ($ids as $rank => $id) {
                $scores[$id] = ($scores[$id] ?? 0.0) + ($weight / ($k + $rank + 1));
            }
        }

        arsort($scores);

        return array_slice(array_map('intval', array_keys($scores)), 0, $take);
    }
}
