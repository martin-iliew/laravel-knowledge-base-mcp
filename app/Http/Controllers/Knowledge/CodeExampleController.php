<?php

namespace App\Http\Controllers\Knowledge;

use App\Http\Controllers\Controller;
use App\Http\Requests\Knowledge\StoreCodeExampleRequest;
use App\Http\Requests\Knowledge\UpdateCodeExampleRequest;
use App\Models\CodeExample;
use App\Models\KnowledgeItem;
use Illuminate\Http\RedirectResponse;

class CodeExampleController extends Controller
{
    /**
     * Store a newly created code example for a knowledge item.
     */
    public function store(StoreCodeExampleRequest $request, KnowledgeItem $knowledgeItem): RedirectResponse
    {
        $this->authorize('update', $knowledgeItem);
        $this->authorize('create', [CodeExample::class, $knowledgeItem]);

        $validated = $request->validated();
        $validated['sort_order'] = $this->nextSortOrder($knowledgeItem);
        $validated['title'] = $this->normalizeOptionalString($validated['title'] ?? null);
        $validated['filename'] = $this->normalizeOptionalString($validated['filename'] ?? null);
        $validated['description'] = $this->normalizeOptionalString($validated['description'] ?? null);

        $knowledgeItem->codeExamples()->create($validated);

        return back()->with('status', 'Code example added.');
    }

    /**
     * Update an existing code example.
     */
    public function update(
        UpdateCodeExampleRequest $request,
        KnowledgeItem $knowledgeItem,
        CodeExample $codeExample
    ): RedirectResponse {
        abort_unless((int) $codeExample->knowledge_item_id === (int) $knowledgeItem->id, 404);

        $this->authorize('update', $codeExample);

        $validated = $request->validated();
        $validated['title'] = $this->normalizeOptionalString($validated['title'] ?? null);
        $validated['filename'] = $this->normalizeOptionalString($validated['filename'] ?? null);
        $validated['description'] = $this->normalizeOptionalString($validated['description'] ?? null);

        $codeExample->update($validated);

        return back()->with('status', 'Code example updated.');
    }

    /**
     * Delete a code example.
     */
    public function destroy(KnowledgeItem $knowledgeItem, CodeExample $codeExample): RedirectResponse
    {
        abort_unless((int) $codeExample->knowledge_item_id === (int) $knowledgeItem->id, 404);

        $this->authorize('delete', $codeExample);

        $codeExample->delete();

        return back()->with('status', 'Code example deleted.');
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
     * Calculate the next append-only sort order for a knowledge item's code examples.
     */
    private function nextSortOrder(KnowledgeItem $knowledgeItem): int
    {
        $maxSortOrder = $knowledgeItem
            ->codeExamples()
            ->max('sort_order');

        if ($maxSortOrder === null) {
            return 0;
        }

        return min(65535, ((int) $maxSortOrder) + 1);
    }
}
