<?php

namespace App\Mcp\Tools;

use App\Services\KnowledgeSearchService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

class SearchKnowledgeBaseTool extends Tool
{
    protected string $name = 'search_knowledge_base';

    protected string $description = 'Search the knowledge base using hybrid retrieval.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->min(1)->required(),
            'limit' => $schema->integer()->min(1)->max(10)->default(5),
            'category' => $schema->string()->nullable(),
            'tags' => $schema->array()->items($schema->string())->default([]),
            'include_drafts' => $schema->boolean()->default(false),
        ];
    }

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
        $tags = array_values(array_filter($request->array('tags'), is_string(...)));
        $includeDrafts = $request->boolean('include_drafts');

        $results = $search->search(
            query: $query,
            limit: $limit,
            category: is_string($category) ? $category : null,
            tags: $tags,
            includeDrafts: $includeDrafts,
            userId: $userId
        );

        return Response::structured(['results' => $results]);
    }

    private function resolveDefaultUserId(): ?int
    {
        $defaultUserId = config('knowledge.mcp.default_user_id');

        return is_numeric($defaultUserId) ? (int) $defaultUserId : null;
    }
}
