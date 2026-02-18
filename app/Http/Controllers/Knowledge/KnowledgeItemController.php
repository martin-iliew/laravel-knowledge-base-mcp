<?php

namespace App\Http\Controllers\Knowledge;

use App\Http\Controllers\Controller;
use App\Http\Requests\Knowledge\PreviewMarkdownRequest;
use App\Http\Requests\Knowledge\StoreKnowledgeItemRequest;
use App\Http\Requests\Knowledge\UpdateKnowledgeItemRequest;
use App\Models\KnowledgeItem;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class KnowledgeItemController extends Controller
{
    private const FORM_STEP_DETAILS = 'details';

    private const FORM_STEP_ENHANCEMENTS = 'enhancements';

    /**
     * Display the knowledge base home (owned + shared items).
     */
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', KnowledgeItem::class);

        /** @var User $user */
        $user = $request->user();
        $userId = (int) $user->id;
        $receivedAccessByOwner = $user->knowledgeAccessReceived()
            ->pluck('permission', 'owner_user_id');

        $items = KnowledgeItem::query()
            ->accessibleTo($user)
            ->with('creator:id,name,email')
            ->withCount(['codeExamples', 'resources'])
            ->latest('updated_at')
            ->get()
            ->map(function (KnowledgeItem $item) use ($receivedAccessByOwner, $userId): array {
                $ownerId = (int) $item->created_by;
                $accessLevel = $ownerId === $userId
                    ? 'owner'
                    : ((string) ($receivedAccessByOwner->get($ownerId) ?? 'viewer'));

                return [
                    'id' => $item->id,
                    'slug' => $item->slug,
                    'title' => $item->title,
                    'category' => $item->category,
                    'tags' => $item->tags ?? [],
                    'status' => $item->status,
                    'updated_at' => $item->updated_at?->toIso8601String(),
                    'published_at' => $item->published_at?->toIso8601String(),
                    'code_examples_count' => (int) $item->code_examples_count,
                    'resources_count' => (int) $item->resources_count,
                    'owner' => [
                        'id' => $item->creator?->id,
                        'name' => $item->creator?->name,
                        'email' => $item->creator?->email,
                    ],
                    'access_level' => $accessLevel,
                    'can_update' => $ownerId === $userId || $accessLevel === 'editor',
                    'can_delete' => $ownerId === $userId,
                ];
            })
            ->values();

        return Inertia::render('dashboard', [
            'items' => $items,
        ]);
    }

    /**
     * Show the creation form for a knowledge item.
     */
    public function create(Request $request): Response
    {
        $this->authorize('create', KnowledgeItem::class);

        /** @var User $user */
        $user = $request->user();

        return Inertia::render('knowledge/form', [
            'mode' => 'create',
            'step' => self::FORM_STEP_DETAILS,
            'item' => null,
            'codeExamples' => [],
            'resources' => [],
            'categoryOptions' => $this->categoryOptions($user),
            'permissions' => [
                'can_update' => true,
                'can_delete' => false,
                'access_level' => 'owner',
            ],
        ]);
    }

    /**
     * Store a newly created knowledge item.
     */
    public function store(StoreKnowledgeItemRequest $request): RedirectResponse
    {
        $this->authorize('create', KnowledgeItem::class);

        $validated = $request->validated();
        $title = trim((string) $validated['title']);
        $status = (string) ($validated['status'] ?? 'draft');

        $knowledgeItem = KnowledgeItem::query()->create([
            'slug' => $this->buildUniqueSlug($title),
            'title' => $title,
            'content_markdown' => (string) $validated['content_markdown'],
            'category' => $this->normalizeOptionalString($validated['category'] ?? null),
            'tags' => $this->normalizeTags($validated['tags'] ?? []),
            'status' => $status,
            'source' => 'human',
            'published_at' => $status === 'published' ? now() : null,
            'created_by' => $request->user()->id,
            'chunk_size' => (int) config('knowledge.defaults.chunk_size'),
            'chunk_overlap' => (int) config('knowledge.defaults.chunk_overlap'),
            'embedding_dimensions' => (int) config('knowledge.defaults.embedding_dimensions'),
        ]);

        return to_route('knowledge-items.edit', [
            'knowledgeItem' => $knowledgeItem,
            'step' => self::FORM_STEP_ENHANCEMENTS,
        ])
            ->with('status', 'Knowledge item created.');
    }

    /**
     * Show the edit form for an existing knowledge item.
     */
    public function edit(Request $request, KnowledgeItem $knowledgeItem): Response
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

        return Inertia::render('knowledge/form', [
            'mode' => 'edit',
            'step' => $this->resolveFormStep($request, self::FORM_STEP_DETAILS),
            'item' => [
                'id' => $knowledgeItem->id,
                'slug' => $knowledgeItem->slug,
                'title' => $knowledgeItem->title,
                'content_markdown' => $knowledgeItem->content_markdown,
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
            'categoryOptions' => $this->categoryOptions($user),
            'permissions' => [
                'can_update' => $ownerId === $userId || $accessLevel === 'editor',
                'can_delete' => $ownerId === $userId,
                'access_level' => $accessLevel,
            ],
        ]);
    }

    /**
     * Update an existing knowledge item.
     */
    public function update(UpdateKnowledgeItemRequest $request, KnowledgeItem $knowledgeItem): RedirectResponse
    {
        $this->authorize('update', $knowledgeItem);

        $validated = $request->validated();
        $title = trim((string) $validated['title']);
        $status = (string) ($validated['status'] ?? $knowledgeItem->status);

        $knowledgeItem->fill([
            'title' => $title,
            'content_markdown' => (string) $validated['content_markdown'],
            'category' => $this->normalizeOptionalString($validated['category'] ?? null),
            'tags' => $this->normalizeTags($validated['tags'] ?? []),
            'status' => $status,
            'published_at' => $status === 'published'
                ? ($knowledgeItem->published_at ?? now())
                : null,
        ]);

        if ($knowledgeItem->isDirty('title')) {
            $knowledgeItem->slug = $this->buildUniqueSlug($title, $knowledgeItem->id);
        }

        $knowledgeItem->save();

        return to_route('knowledge-items.edit', [
            'knowledgeItem' => $knowledgeItem,
            'step' => $this->resolveFormStep($request, self::FORM_STEP_DETAILS),
        ])
            ->with('status', 'Knowledge item updated.');
    }

    /**
     * Delete a knowledge item.
     */
    public function destroy(KnowledgeItem $knowledgeItem): RedirectResponse
    {
        $this->authorize('delete', $knowledgeItem);

        $knowledgeItem->delete();

        return to_route('knowledge-base.index')->with('status', 'Knowledge item deleted.');
    }

    /**
     * Render markdown preview HTML for editor live preview.
     */
    public function previewMarkdown(PreviewMarkdownRequest $request): JsonResponse
    {
        $this->authorize('viewAny', KnowledgeItem::class);

        $html = Str::markdown(
            (string) $request->validated('content_markdown'),
            [
                'html_input' => 'strip',
                'allow_unsafe_links' => false,
            ]
        );

        return response()->json([
            'html' => $html,
        ]);
    }

    /**
     * Build a unique slug using the same collision strategy as MCP tool creation.
     */
    private function buildUniqueSlug(string $title, ?int $ignoreItemId = null): string
    {
        $slugBase = Str::slug($title);
        if ($slugBase === '') {
            $slugBase = 'knowledge-entry';
        }

        $slug = $slugBase;
        $suffix = 2;

        while (
            KnowledgeItem::query()
                ->when($ignoreItemId, fn ($query) => $query->where('id', '!=', $ignoreItemId))
                ->where('slug', $slug)
                ->exists()
        ) {
            $slug = $slugBase.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }

    /**
     * Normalize tag values from a validated input array.
     *
     * @param  array<int|string, mixed>  $tags
     * @return array<int, string>
     */
    private function normalizeTags(array $tags): array
    {
        return collect($tags)
            ->filter(fn ($tag) => is_string($tag))
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

    /**
     * Resolve the requested editor step, falling back to details for invalid values.
     */
    private function resolveFormStep(Request $request, string $fallback): string
    {
        $step = trim((string) $request->query('step', $fallback));

        return in_array($step, [self::FORM_STEP_DETAILS, self::FORM_STEP_ENHANCEMENTS], true)
            ? $step
            : $fallback;
    }

    /**
     * Build the category filter options for the current user.
     *
     * @return array<int, string>
     */
    private function categoryOptions(User $user): array
    {
        return KnowledgeItem::query()
            ->accessibleTo($user)
            ->whereNotNull('category')
            ->distinct()
            ->orderBy('category')
            ->pluck('category')
            ->filter(fn ($category) => is_string($category) && $category !== '')
            ->values()
            ->all();
    }
}
