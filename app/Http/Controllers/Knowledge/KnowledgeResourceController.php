<?php

namespace App\Http\Controllers\Knowledge;

use App\Http\Controllers\Controller;
use App\Http\Requests\Knowledge\StoreKnowledgeResourceRequest;
use App\Http\Requests\Knowledge\UpdateKnowledgeResourceRequest;
use App\Models\KnowledgeItem;
use App\Models\KnowledgeResource;
use Illuminate\Http\RedirectResponse;

class KnowledgeResourceController extends Controller
{
    /**
     * Store a newly created resource for a knowledge item.
     */
    public function store(
        StoreKnowledgeResourceRequest $request,
        KnowledgeItem $knowledgeItem
    ): RedirectResponse {
        $this->authorize('update', $knowledgeItem);
        $this->authorize('create', [KnowledgeResource::class, $knowledgeItem]);

        $validated = $request->validated();
        $validated['sort_order'] = $this->nextSortOrder($knowledgeItem);
        $validated['label'] = $this->normalizeOptionalString($validated['label'] ?? null);
        $validated['url'] = $this->normalizeOptionalString($validated['url'] ?? null);
        $validated['storage_path'] = $this->normalizeOptionalString($validated['storage_path'] ?? null);
        $validated['mime'] = $this->normalizeOptionalString($validated['mime'] ?? null);
        $validated['size'] = isset($validated['size']) ? (int) $validated['size'] : null;

        $knowledgeItem->resources()->create($validated);

        return back()->with('status', 'Resource added.');
    }

    /**
     * Update an existing resource.
     */
    public function update(
        UpdateKnowledgeResourceRequest $request,
        KnowledgeItem $knowledgeItem,
        KnowledgeResource $knowledgeResource
    ): RedirectResponse {
        abort_unless((int) $knowledgeResource->knowledge_item_id === (int) $knowledgeItem->id, 404);

        $this->authorize('update', $knowledgeResource);

        $validated = $request->validated();
        $validated['label'] = $this->normalizeOptionalString($validated['label'] ?? null);
        $validated['url'] = $this->normalizeOptionalString($validated['url'] ?? null);
        $validated['storage_path'] = $this->normalizeOptionalString($validated['storage_path'] ?? null);
        $validated['mime'] = $this->normalizeOptionalString($validated['mime'] ?? null);
        $validated['size'] = isset($validated['size']) ? (int) $validated['size'] : null;

        $knowledgeResource->update($validated);

        return back()->with('status', 'Resource updated.');
    }

    /**
     * Delete a resource.
     */
    public function destroy(KnowledgeItem $knowledgeItem, KnowledgeResource $knowledgeResource): RedirectResponse
    {
        abort_unless((int) $knowledgeResource->knowledge_item_id === (int) $knowledgeItem->id, 404);

        $this->authorize('delete', $knowledgeResource);

        $knowledgeResource->delete();

        return back()->with('status', 'Resource deleted.');
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
     * Calculate the next append-only sort order for a knowledge item's resources.
     */
    private function nextSortOrder(KnowledgeItem $knowledgeItem): int
    {
        $maxSortOrder = $knowledgeItem
            ->resources()
            ->max('sort_order');

        if ($maxSortOrder === null) {
            return 0;
        }

        return min(65535, ((int) $maxSortOrder) + 1);
    }
}
