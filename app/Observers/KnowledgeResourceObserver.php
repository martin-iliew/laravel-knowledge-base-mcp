<?php

namespace App\Observers;

use App\Models\KnowledgeResource;
use App\Observers\Concerns\KnowledgeIndexingObserverHelpers;

class KnowledgeResourceObserver
{
    use KnowledgeIndexingObserverHelpers; 

    /**
     * Persist a stable hash of extracted text (when present).
     *
     * @param KnowledgeResource $resource
     * @return void
     */
    public function saving(KnowledgeResource $resource): void
    {
        if ($resource->extracted_text) {
            $resource->extracted_hash = $this->sha256Normalized($resource->extracted_text); 
        }
    }

    /**
     * Bump the parent item's index_version and enqueue re-indexing after commit.
     *
     * @param KnowledgeResource $resource
     * @return void
     */
    public function saved(KnowledgeResource $resource): void
    {
        if (! $resource->knowledge_item_id) { 
            return;
        }

        $this->bumpAndDispatchIndexSync((int) $resource->knowledge_item_id); 
    }

    /**
     * Bump the parent item's index_version and enqueue re-indexing after commit.
     *
     * @param KnowledgeResource $resource
     * @return void
     */
    public function deleted(KnowledgeResource $resource): void
    {
        if (! $resource->knowledge_item_id) { 
            return;
        }

        $this->bumpAndDispatchIndexSync((int) $resource->knowledge_item_id); 
    }
}
