<?php

namespace Tests\Support;

use App\Models\KnowledgeChunk;
use App\Models\KnowledgeItem;
use App\Services\KnowledgeSearchService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

final class KnowledgeSearchAssembleFixtures
{
    public static function assemble(Collection $chunks, int $limit = 5): array
    {
        $service = app(KnowledgeSearchService::class);

        return InvokesPrivateMethods::call($service, 'assemble', [$chunks, $limit]);
    }

    public static function fakeItem(int $id, string $updatedAt): KnowledgeItem
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

    public static function fakeArticleChunk(
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
}
