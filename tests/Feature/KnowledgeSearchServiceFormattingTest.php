<?php

use App\Models\KnowledgeChunk;
use App\Models\KnowledgeItem;
use App\Services\KnowledgeSearchService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

function assembleFromChunks(Collection $chunks, int $limit = 5): array
{
    $service = app(KnowledgeSearchService::class);
    $method = new ReflectionMethod(KnowledgeSearchService::class, 'assemble');
    $method->setAccessible(true);

    return $method->invoke($service, $chunks, $limit);
}

function fakeItem(int $id, string $updatedAt): KnowledgeItem
{
    $item = new KnowledgeItem;
    $item->id = $id;
    $item->slug = "item-{$id}";
    $item->title = "Item {$id}";
    $item->category = 'test';
    $item->tags = ['kb'];
    $item->updated_at = Carbon::parse($updatedAt);

    return $item;
}

function fakeArticleChunk(
    KnowledgeItem $item,
    int $chunkIndex,
    string $text,
    int $fusedRank,
    ?float $rerankScore = null,
    ?int $rerankRank = null
): KnowledgeChunk {
    $chunk = new KnowledgeChunk;
    $chunk->source_type = 'article';
    $chunk->source_id = null;
    $chunk->chunk_kind = 'text';
    $chunk->chunk_index = $chunkIndex;
    $chunk->chunk_text = $text;
    $chunk->meta = [];
    $chunk->setAttribute('fused_rank', $fusedRank);

    if ($rerankScore !== null) {
        $chunk->setAttribute('rerank_score', $rerankScore);
    }

    if ($rerankRank !== null) {
        $chunk->setAttribute('rerank_rank', $rerankRank);
    }

    $chunk->setRelation('item', $item);

    return $chunk;
}

test('assemble keeps rerank score as float when available', function () {
    $item = fakeItem(10, '2026-02-17T07:53:05+00:00');

    $chunks = collect([
        fakeArticleChunk($item, 0, 'scored snippet', 0, 0.91, 0),
    ]);

    $results = assembleFromChunks($chunks);

    expect($results)->toHaveCount(1);
    expect($results[0]['snippets'])->toHaveCount(1);
    expect($results[0]['snippets'][0]['score'])->toBeFloat()->toBe(0.91);
});

test('assemble orders snippets by rerank score desc when scores exist', function () {
    $item = fakeItem(11, '2026-02-17T07:53:05+00:00');

    $chunks = collect([
        fakeArticleChunk($item, 0, 'lower score', 0, 0.2, 1),
        fakeArticleChunk($item, 1, 'higher score', 3, 0.9, 0),
    ]);

    $results = assembleFromChunks($chunks);
    $texts = array_map(fn (array $s) => $s['text'], $results[0]['snippets']);

    expect($texts)->toBe(['higher score', 'lower score']);
});

test('assemble orders snippets by fused rank when rerank score is missing', function () {
    $item = fakeItem(12, '2026-02-17T07:53:05+00:00');

    $chunks = collect([
        fakeArticleChunk($item, 0, 'later fused rank', 4),
        fakeArticleChunk($item, 1, 'earlier fused rank', 1),
    ]);

    $results = assembleFromChunks($chunks);
    $texts = array_map(fn (array $s) => $s['text'], $results[0]['snippets']);

    expect($texts)->toBe(['earlier fused rank', 'later fused rank']);
    expect($results[0]['snippets'][0]['score'])->toBeNull();
    expect($results[0]['snippets'][1]['score'])->toBeNull();
});

test('assemble returns updated_at in iso8601 with utc offset', function () {
    $item = fakeItem(13, '2026-02-17 01:53:05');

    $chunks = collect([
        fakeArticleChunk($item, 0, 'timezone check', 0),
    ]);

    $results = assembleFromChunks($chunks);

    expect($results[0]['item']['updated_at'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/');
});
