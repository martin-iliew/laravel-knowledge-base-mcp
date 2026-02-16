<?php

namespace App\Mcp\Tools;

use App\Services\KnowledgeSearchService;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Request;
use Laravel\Mcp\Server\Response;
use Laravel\Mcp\Server\Schemas\JsonSchema;

class SearchKnowledgeBaseTool extends Tool
{
    
    protected string $name = 'search-knowledge-base'; 
    protected string $description = 'Search the authenticated user’s knowledge base using hybrid retrieval.'; 

    public function schema(JsonSchema $schema): array
    {
        return $schema->object([
            'query' => $schema->string()->minLength(1),
            'limit' => $schema->integer()->minimum(1)->maximum(10)->default(5),
            'category' => $schema->string()->nullable(),
            'tags' => $schema->array($schema->string())->default([]),
            'include_drafts' => $schema->boolean()->default(false),
        ]);
    }

    public function outputSchema(JsonSchema $schema): array
    {
        return $schema->object([
            'results' => $schema->array($schema->any()),
        ]);
    }

    public function handle(Request $request, KnowledgeSearchService $search): Response
    {
        $user = $request->user(); 
        if (! $user) { 
            return Response::error('Unauthorized.');
        }

        $query = (string) $request->input('query');
        $limit = (int) $request->input('limit', 5);
        $category = $request->input('category');
        $tags = (array) $request->input('tags', []);
        $includeDrafts = (bool) $request->input('include_drafts', false);

        $results = $search->search(
            query: $query,
            limit: $limit,
            category: is_string($category) ? $category : null,
            tags: $tags,
            includeDrafts: $includeDrafts,
            userId: $user->id
        );

        return Response::structured(['results' => $results]);
    }
}
