<?php

namespace App\Mcp\Tools;

use App\Models\KnowledgeItem;
use App\Services\KnowledgeSearchService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
class SearchKnowledgeBaseTool extends Tool
{
    /**
     * The tool's name.
     */
    protected string $name = 'search_knowledge_base';

    /**
     * The tool's description.
     */
    protected string $description = 'Search the knowledge base using hybrid retrieval.';

    /**
     * Get the tool's input schema.
     *
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()
                ->min(1)
                ->description('User query text.')
                ->required(),

            'limit' => $schema->integer()
                ->min(1)
                ->max(10)
                ->description('Max number of items to return.')
                ->default(5),

            'category' => $schema->string()
                ->description('Optional category filter.')
                ->nullable(),

            'tags' => $schema->array()
                ->items($schema->string())
                ->description('Optional tags filter (AND semantics).')
                ->default([]),

            'include_drafts' => $schema->boolean()
                ->description('Include draft/unpublished items (for authorized scope).')
                ->default(false),
        ];
    }

    /**
     * Get the tool's output schema.
     *
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function outputSchema(JsonSchema $schema): array
    {
        $snippetSchema = $schema->object([
            'source_type' => $schema->string()->enum(['article', 'code', 'resource'])->required(),
            'source_id' => $schema->integer()->nullable()->required(),
            'chunk_kind' => $schema->string()->enum(['markdown', 'code', 'text'])->required(),
            'chunk_index' => $schema->integer()->required(),
            'meta' => $schema->object()->required(),
            'score' => $schema->number()->nullable()->required(),
            'text' => $schema->string()->required(),
        ])->withoutAdditionalProperties();

        $itemSchema = $schema->object([
            'id' => $schema->integer()->required(),
            'slug' => $schema->string()->required(),
            'title' => $schema->string()->required(),
            'category' => $schema->string()->nullable()->required(),
            'tags' => $schema->array()->items($schema->string())->required(),
            'updated_at' => $schema->string()->format('date-time')->required(),
        ])->withoutAdditionalProperties();

        $codeSchema = $schema->object([
            'id' => $schema->integer()->required(),
            'title' => $schema->string()->nullable()->required(),
            'language' => $schema->string()->required(),
            'filename' => $schema->string()->nullable()->required(),
            'code' => $schema->string()->required(),
        ])->withoutAdditionalProperties();

        $resourceSchema = $schema->object([
            'id' => $schema->integer()->required(),
            'type' => $schema->string()->enum(['link', 'file'])->required(),
            'label' => $schema->string()->nullable()->required(),
            'url' => $schema->string()->nullable()->required(),
            'storage_path' => $schema->string()->nullable()->required(),
            'mime' => $schema->string()->nullable()->required(),
            'size' => $schema->integer()->nullable()->required(),
        ])->withoutAdditionalProperties();

        $resultSchema = $schema->object([
            'item' => $itemSchema->required(),
            'snippets' => $schema->array()->items($snippetSchema)->required(),
            'code_examples' => $schema->array()->items($codeSchema)->required(),
            'resources' => $schema->array()->items($resourceSchema)->required(),
        ])->withoutAdditionalProperties();

        return [
            'results' => $schema->array()->items($resultSchema)->required(),
        ];
    }

    /**
     * Handle the tool request.
     */
    public function handle(Request $request, KnowledgeSearchService $search): Response|ResponseFactory
    {
        $authenticatedUser = $request->user();
        $requireAuth = (bool) config('knowledge.mcp.require_auth');

        if ($requireAuth && ! $authenticatedUser) {
            return Response::error('Unauthorized.');
        }

        $userId = $authenticatedUser
            ? (int) $authenticatedUser->id
            : $this->resolveDefaultUserId();

        $query = (string) $request->get('query', '');
        $limit = $request->integer('limit', 5);
        $category = $request->get('category');
        $tags = array_values(array_filter(
            $request->array('tags'),
            fn ($tag): bool => is_string($tag) && trim($tag) !== ''
        ));
        $includeDrafts = $request->boolean('include_drafts');

        $results = $search->search(
            query: $query,
            limit: $limit,
            category: is_string($category) ? $category : null,
            tags: $tags,
            includeDrafts: $includeDrafts,
            userId: $userId,
        );

        return Response::structured(['results' => $results]);
    }

    /**
     * Resolve default user scope for unauthenticated MCP requests.
     */
    private function resolveDefaultUserId(): ?int
    {
        $defaultUserId = config('knowledge.mcp.default_user_id');

        if (is_numeric($defaultUserId)) {
            $candidate = (int) $defaultUserId;

            if ($candidate > 0) {
                return $candidate;
            }
        }

        $ownerUserId = KnowledgeItem::query()
            ->select('created_by')
            ->selectRaw('count(*) as total_items')
            ->groupBy('created_by')
            ->orderByDesc('total_items')
            ->value('created_by');

        return is_numeric($ownerUserId) ? (int) $ownerUserId : null;
    }
}
