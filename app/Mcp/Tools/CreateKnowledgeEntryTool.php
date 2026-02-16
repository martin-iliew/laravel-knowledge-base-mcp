<?php

namespace App\Mcp\Tools;

use App\Models\KnowledgeItem;
use Illuminate\Support\Str;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Request;
use Laravel\Mcp\Server\Response;
use Laravel\Mcp\Server\Schemas\JsonSchema;

class CreateKnowledgeEntryTool extends Tool
{
    public function schema(JsonSchema $schema): array
    {
        return $schema->object([
            'title' => $schema->string()->minLength(1),
            'content_markdown' => $schema->string()->minLength(1),
            'category' => $schema->string()->nullable(),
            'tags' => $schema->array($schema->string())->default([]),
        ]);
    }

    public function outputSchema(JsonSchema $schema): array
    {
        return $schema->object([
            'id' => $schema->integer(),
            'slug' => $schema->string(),
            'status' => $schema->string(),
        ]);
    }

    public function handle(Request $request): Response
    {
        $title = (string) $request->input('title');
        $content = (string) $request->input('content_markdown');
        $category = $request->input('category');
        $tags = (array) $request->input('tags', []);

        $slugBase = Str::slug($title);
        $slug = $slugBase;
        $i = 2;

        while (KnowledgeItem::query()->where('slug', $slug)->exists()) {
            $slug = $slugBase . '-' . $i;
            $i++;
        }

        $item = KnowledgeItem::query()->create([
            'slug' => $slug,
            'title' => $title,
            'content_markdown' => $content,
            'category' => is_string($category) ? $category : null,
            'tags' => $tags,
            'source' => 'ai',
            'status' => 'draft',
            'chunk_size' => (int) config('knowledge.defaults.chunk_size'),
            'chunk_overlap' => (int) config('knowledge.defaults.chunk_overlap'),
            'embedding_dimensions' => (int) config('knowledge.defaults.embedding_dimensions'),
        ]);

        return Response::structured([
            'id' => $item->id,
            'slug' => $item->slug,
            'status' => $item->status,
        ]);
    }
}
