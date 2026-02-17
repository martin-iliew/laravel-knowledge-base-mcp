<?php

namespace App\Observers;

use App\Models\KnowledgeItem;
use App\Observers\Concerns\KnowledgeIndexingObserverHelpers;

class KnowledgeItemObserver
{
    use KnowledgeIndexingObserverHelpers; 

    /**
     * Maintain hashes/defaults and bump index_version when reindex-relevant fields change.
     *
     * @param KnowledgeItem $item
     * @return void
     */
    public function saving(KnowledgeItem $item): void
    {
        $item->content_hash = $this->sha256Normalized($item->content_markdown ?? ''); 

        if (! $item->chunk_size) {
            $item->chunk_size = (int) config('knowledge.defaults.chunk_size');
        }

        if (! $item->chunk_overlap && $item->chunk_overlap !== 0) {
            $item->chunk_overlap = (int) config('knowledge.defaults.chunk_overlap');
        }

        $reindexFields = [
            'title',
            'content_markdown',
            'category',
            'tags',
            'chunk_size',
            'chunk_overlap',
        ];

        foreach ($reindexFields as $f) {
            if ($item->isDirty($f)) {
                $item->index_version = ((int) $item->index_version) + 1;
                break;
            }
        }
    }

    /**
     * Enqueue indexing only when index_version changed in this save.
     *
     * @param KnowledgeItem $item
     * @return void
     */
    public function saved(KnowledgeItem $item): void
    {
        if (! $item->wasChanged('index_version')) {
            return;
        }

        $this->dispatchIndexSync($item->id, (int) $item->index_version); 
    }
}
