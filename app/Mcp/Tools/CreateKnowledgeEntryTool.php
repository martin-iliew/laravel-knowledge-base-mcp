<?php

namespace App\Mcp\Tools;

use App\Models\KnowledgeItem;
use App\Models\User;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

class CreateKnowledgeEntryTool extends Tool
{
    /**
     * The tool's name.
     */
    protected string $name = 'create_knowledge_entry';

    /**
     * The tool's description.
     */
    protected string $description = 'Create a draft knowledge item.';

    /**
     * Get the tool's input schema.
     *
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()
                ->min(1)
                ->description('Knowledge item title.')
                ->required(),

            'content_markdown' => $schema->string()
                ->min(1)
                ->description('Markdown body for the knowledge item.')
                ->required(),

            'category' => $schema->string()
                ->description('Optional category for filtering.')
                ->nullable(),

            'tags' => $schema->array()
                ->items($schema->string())
                ->description('Optional tags (AND semantics in retrieval).')
                ->default([]),
        ];
    }

    /**
     * Get the tool's output schema.
     *
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()
                ->description('Created knowledge item ID.')
                ->required(),

            'slug' => $schema->string()
                ->description('Final unique slug.')
                ->required(),

            'status' => $schema->string()
                ->enum(['draft'])
                ->description('Always "draft" on creation.')
                ->required(),
        ];
    }

    /**
     * Handle the tool request.
     * Creates a draft KnowledgeItem scoped to the authenticated user or a configured default user.
     */
    public function handle(Request $request): Response|ResponseFactory
    {
        $title = (string) $request->get('title', '');
        $content = (string) $request->get('content_markdown', '');
        $category = $request->get('category');
        $tags = array_values(array_filter($request->array('tags'), is_string(...)));

        $slugBase = Str::slug($title);
        if ($slugBase === '') {
            $slugBase = 'knowledge-entry';
        }

        $slug = $slugBase;
        $i = 2;

        $userId = $this->resolveUserId($request);
        $requireAuth = (bool) config('knowledge.mcp.require_auth');
        $userId = $this->resolveUserId($request);
        $requireAuth = (bool) config('knowledge.mcp.require_auth');

        if (($requireAuth && ! $request->user()) || ! $userId) {
            return Response::error('Unauthorized. Configure KB_MCP_DEFAULT_USER_ID or use Sanctum auth.');
        if (($requireAuth && ! $request->user()) || ! $userId) {
            return Response::error('Unauthorized. Configure KB_MCP_DEFAULT_USER_ID or use Sanctum auth.');
        }

        while (KnowledgeItem::query()->where('slug', $slug)->exists()) {
            $slug = $slugBase.'-'.$i;
            $slug = $slugBase.'-'.$i;
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
            'created_by' => $userId,
            'created_by' => $userId,
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

    /**
     * Resolve the acting user ID from the authenticated request user or the configured default.
     */
    private function resolveUserId(Request $request): ?int
    {
        if ($user = $request->user()) {
            return (int) $user->id;
        }

        $defaultUserId = config('knowledge.mcp.default_user_id');
        if (! is_numeric($defaultUserId)) {
            return null;
        }

        $id = (int) $defaultUserId;

        return User::query()->whereKey($id)->exists() ? $id : null;
    }
}
