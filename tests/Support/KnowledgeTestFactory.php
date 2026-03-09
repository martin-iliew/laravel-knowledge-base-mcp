<?php

namespace Tests\Support;

use App\Models\KnowledgeChunk;
use App\Models\KnowledgeItem;
use App\Models\User;
use App\Services\Embeddings\EmbeddingDimensionResolver;

final class KnowledgeTestFactory
{
    public static function createOwnedItem(User $user, string $slug, array $attributes = []): KnowledgeItem
    {
        return KnowledgeItem::withoutEvents(fn () => KnowledgeItem::query()->create(array_merge([
            'slug' => $slug,
            'title' => 'Item '.$slug,
            'content_markdown' => '# Content',
            'status' => 'draft',
            'source' => 'human',
            'created_by' => $user->id,
        ], $attributes)));
    }

    /**
     * @param  array<int, string>  $tags
     */
    public static function createIndexedArticle(
        User $owner,
        string $slug,
        string $title,
        string $content,
        string $category = 'Integrations',
        array $tags = []
    ): KnowledgeItem {
        $item = self::createOwnedItem($owner, $slug, [
            'title' => $title,
            'content_markdown' => $content,
            'category' => $category,
            'tags' => $tags,
            'status' => 'published',
            'published_at' => now(),
        ]);

        $dimensions = app(EmbeddingDimensionResolver::class)->resolve();
        $tagsText = collect($tags)
            ->filter(fn ($tag): bool => is_string($tag) && trim($tag) !== '')
            ->map(fn (string $tag): string => trim($tag))
            ->implode(' ');

        KnowledgeChunk::query()->insert([
            'knowledge_item_id' => $item->id,
            'source_type' => 'article',
            'source_id' => null,
            'chunk_index' => 0,
            'chunk_kind' => 'markdown',
            'chunk_text' => $content,
            'title_text' => $title,
            'heading_path_text' => '',
            'tags_text' => $tagsText,
            'category_text' => $category,
            'meta' => json_encode([], JSON_THROW_ON_ERROR),
            'chunk_hash' => hash('sha256', $slug.'|0|'.$content),
            'token_count' => str_word_count($content),
            'embedding' => self::zeroVectorLiteral($dimensions),
            'embedding_model' => 'test-seeded',
            'embedding_dimensions' => $dimensions,
            'embedded_at' => now(),
            'embedding_attempts' => 1,
            'embedding_error' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $item;
    }

    public static function configureLexicalSearchOnly(): void
    {
        config([
            'knowledge.search_v2.enabled' => true,
            'knowledge.search_v2.profiles.search_v2_single_token.dense_k' => 0,
            'knowledge.search_v2.profiles.search_v2_short.dense_k' => 0,
            'knowledge.search_v2.profiles.search_v2_long.dense_k' => 0,
            'knowledge.hybrid.enable_ai_rerank' => false,
            'knowledge.hybrid.dense_k' => 0,
            'knowledge.hybrid.sparse_k' => 20,
            'knowledge.hybrid.fused_k' => 20,
            'knowledge.hybrid.rerank_k' => 20,
            'knowledge.limits.items' => 10,
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $results
     * @return array<int, string>
     */
    public static function resultSlugs(array $results): array
    {
        return collect($results)
            ->pluck('item.slug')
            ->filter(fn ($slug): bool => is_string($slug) && $slug !== '')
            ->values()
            ->all();
    }

    private static function zeroVectorLiteral(int $dimensions): string
    {
        return '['.implode(',', array_fill(0, $dimensions, '0')).']';
    }
}
