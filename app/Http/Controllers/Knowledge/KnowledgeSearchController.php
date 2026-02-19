<?php

namespace App\Http\Controllers\Knowledge;

use App\Http\Controllers\Controller;
use App\Http\Requests\Knowledge\KnowledgeSearchRequest;
use App\Models\KnowledgeItem;
use App\Models\User;
use App\Services\KnowledgeSearchService;
use Inertia\Inertia;
use Inertia\Response;

class KnowledgeSearchController extends Controller
{
    /**
     * Display the search UI and execute hybrid retrieval for the current user scope.
     */
    public function index(KnowledgeSearchRequest $request, KnowledgeSearchService $knowledgeSearch): Response
    {
        $this->authorize('viewAny', KnowledgeItem::class);

        /** @var User $user */
        $user = $request->user();

        $validated = $request->validated();
        $query = trim((string) ($validated['query'] ?? ''));
        $category = $this->normalizeOptionalString($validated['category'] ?? null);
        $tags = $this->normalizeTagsFromInput((string) ($validated['tags'] ?? ''));
        $includeDrafts = $request->boolean('include_drafts', false);
        $limit = max(1, min((int) ($validated['limit'] ?? 5), 10));

        $results = [];

        if ($query !== '') {
            $results = $knowledgeSearch->search(
                query: $query,
                limit: $limit,
                category: $category,
                tags: $tags,
                includeDrafts: $includeDrafts,
                userId: (int) $user->id
            );
        }

        return Inertia::render('knowledge-base/search', [
            'results' => $results,
            'filters' => [
                'query' => $query,
                'category' => $category,
                'tags' => implode(', ', $tags),
                'include_drafts' => $includeDrafts,
                'limit' => $limit,
            ],
            'categoryOptions' => KnowledgeItem::query()
                ->accessibleTo($user)
                ->whereNotNull('category')
                ->distinct()
                ->orderBy('category')
                ->pluck('category')
                ->filter(fn ($value) => is_string($value) && $value !== '')
                ->values()
                ->all(),
        ]);
    }

    /**
     * Normalize comma-separated tags from a single input.
     *
     * @return array<int, string>
     */
    private function normalizeTagsFromInput(string $tagsInput): array
    {
        return collect(explode(',', $tagsInput))
            ->map(fn (string $tag) => trim($tag))
            ->filter(fn (string $tag) => $tag !== '')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Normalize optional string fields by trimming and converting empty values to null.
     */
    private function normalizeOptionalString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $normalized = trim($value);

        return $normalized !== '' ? $normalized : null;
    }
}
