<?php

namespace App\Http\Controllers\Knowledge;

use App\Http\Controllers\Controller;
use App\Http\Requests\Knowledge\KnowledgeSearchRequest;
use App\Models\KnowledgeItem;
use App\Models\User;
use App\Services\KnowledgeSearchService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class KnowledgeBaseController extends Controller
{
    /**
     * Display docs-style knowledge base with integrated search.
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

        $latestItems = KnowledgeItem::query()
            ->accessibleTo($user)
            ->select(['id', 'title', 'slug', 'category', 'tags', 'status', 'updated_at', 'published_at'])
            ->latest('updated_at')
            ->limit(12)
            ->get();

        $categoryTree = $this->buildCategoryTree(
            KnowledgeItem::query()
                ->accessibleTo($user)
                ->select(['category', 'tags'])
                ->get()
        );

        return Inertia::render('knowledge/base', [
            'results' => $results,
            'latestItems' => $latestItems->map(function (KnowledgeItem $item): array {
                return [
                    'id' => $item->id,
                    'slug' => $item->slug,
                    'title' => $item->title,
                    'category' => $item->category,
                    'tags' => $item->tags ?? [],
                    'status' => $item->status,
                    'updated_at' => $item->updated_at?->toIso8601String(),
                    'published_at' => $item->published_at?->toIso8601String(),
                ];
            })->values(),
            'filters' => [
                'query' => $query,
                'category' => $category,
                'tags' => implode(', ', $tags),
                'include_drafts' => $includeDrafts,
                'limit' => $limit,
            ],
            'categoryOptions' => $categoryTree->pluck('name')->values(),
            'tagOptions' => $categoryTree
                ->flatMap(fn (array $node): Collection => collect($node['children'] ?? [])->pluck('name'))
                ->unique()
                ->values(),
            'categoryTree' => $categoryTree->values(),
        ]);
    }

    /**
     * Display the reader-first view for a knowledge item.
     */
    public function show(Request $request, KnowledgeItem $knowledgeItem): Response
    {
        $this->authorize('view', $knowledgeItem);

        /** @var User $user */
        $user = $request->user();

        $knowledgeItem->load([
            'creator:id,name,email',
            'codeExamples' => fn ($query) => $query->orderBy('sort_order')->orderBy('id'),
            'resources' => fn ($query) => $query->orderBy('sort_order')->orderBy('id'),
        ]);

        $ownerId = (int) $knowledgeItem->created_by;
        $userId = (int) $user->id;
        $accessLevel = $ownerId === $userId
            ? 'owner'
            : ((string) ($user->knowledgeAccessReceived()
                ->where('owner_user_id', $ownerId)
                ->value('permission') ?? 'viewer'));

        return Inertia::render('knowledge/show', [
            'item' => [
                'id' => $knowledgeItem->id,
                'slug' => $knowledgeItem->slug,
                'title' => $knowledgeItem->title,
                'content_html' => Str::markdown(
                    $knowledgeItem->content_markdown,
                    [
                        'html_input' => 'strip',
                        'allow_unsafe_links' => false,
                    ]
                ),
                'category' => $knowledgeItem->category,
                'tags' => $knowledgeItem->tags ?? [],
                'status' => $knowledgeItem->status,
                'published_at' => $knowledgeItem->published_at?->toIso8601String(),
                'updated_at' => $knowledgeItem->updated_at?->toIso8601String(),
                'owner' => [
                    'id' => $knowledgeItem->creator?->id,
                    'name' => $knowledgeItem->creator?->name,
                    'email' => $knowledgeItem->creator?->email,
                ],
            ],
            'codeExamples' => $knowledgeItem->codeExamples->map(function ($example): array {
                return [
                    'id' => $example->id,
                    'sort_order' => $example->sort_order,
                    'title' => $example->title,
                    'language' => $example->language,
                    'filename' => $example->filename,
                    'description' => $example->description,
                    'code' => $example->code,
                    'updated_at' => $example->updated_at?->toIso8601String(),
                ];
            })->values(),
            'resources' => $knowledgeItem->resources->map(function ($resource): array {
                return [
                    'id' => $resource->id,
                    'sort_order' => $resource->sort_order,
                    'type' => $resource->type,
                    'label' => $resource->label,
                    'url' => $resource->url,
                    'storage_path' => $resource->storage_path,
                    'mime' => $resource->mime,
                    'size' => $resource->size,
                    'extracted_text' => $resource->extracted_text,
                    'extracted_at' => $resource->extracted_at?->toIso8601String(),
                    'extract_attempts' => $resource->extract_attempts,
                    'extract_error' => $resource->extract_error,
                    'updated_at' => $resource->updated_at?->toIso8601String(),
                ];
            })->values(),
            'permissions' => [
                'can_update' => $ownerId === $userId || $accessLevel === 'editor',
                'can_delete' => $ownerId === $userId,
                'access_level' => $accessLevel,
            ],
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
            ->map(fn (string $tag): string => trim($tag))
            ->filter(fn (string $tag): bool => $tag !== '')
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

    /**
     * Build a tree-like category structure with tag children for sidebar navigation.
     *
     * @param  Collection<int, KnowledgeItem>  $items
     * @return Collection<int, array{name:string,count:int,children:array<int, array{name:string,count:int}>}>
     */
    private function buildCategoryTree(Collection $items): Collection
    {
        $grouped = $items->groupBy(function (KnowledgeItem $item): string {
            if (is_string($item->category) && trim($item->category) !== '') {
                return trim($item->category);
            }

            return 'Uncategorized';
        });

        return $grouped
            ->map(function (Collection $categoryItems, string $category): array {
                $tagCounts = $categoryItems
                    ->flatMap(function (KnowledgeItem $item): array {
                        return is_array($item->tags) ? $item->tags : [];
                    })
                    ->filter(fn ($tag): bool => is_string($tag) && trim($tag) !== '')
                    ->map(fn (string $tag): string => trim($tag))
                    ->countBy()
                    ->sortKeys()
                    ->map(function (int $count, string $tag): array {
                        return [
                            'name' => $tag,
                            'count' => $count,
                        ];
                    })
                    ->values()
                    ->all();

                return [
                    'name' => $category,
                    'count' => $categoryItems->count(),
                    'children' => $tagCounts,
                ];
            })
            ->sortBy(fn (array $node): string => $node['name'] === 'Uncategorized' ? 'zzzzzz' : mb_strtolower($node['name']))
            ->values();
    }
}
