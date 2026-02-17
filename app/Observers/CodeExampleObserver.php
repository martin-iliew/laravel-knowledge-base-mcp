<?php

namespace App\Observers;

use App\Models\CodeExample;
use App\Observers\Concerns\KnowledgeIndexingObserverHelpers;

class CodeExampleObserver
{
    use KnowledgeIndexingObserverHelpers; 

    /**
     * Persist a stable hash of the code for idempotency / change detection.
     *
     * @param CodeExample $codeExample
     * @return void
     */
    public function saving(CodeExample $codeExample): void
    {
        $codeExample->code_hash = $this->sha256Normalized($codeExample->code ?? ''); 
    }

    /**
     * Bump the parent item's index_version and enqueue re-indexing after commit.
     *
     * @param CodeExample $codeExample
     * @return void
     */
    public function saved(CodeExample $codeExample): void
    {
        if (! $codeExample->knowledge_item_id) { 
            return;
        }

        $this->bumpAndDispatchIndexSync((int) $codeExample->knowledge_item_id); 
    }

    /**
     * Bump the parent item's index_version and enqueue re-indexing after commit.
     *
     * @param CodeExample $codeExample
     * @return void
     */
    public function deleted(CodeExample $codeExample): void
    {
        if (! $codeExample->knowledge_item_id) { 
            return;
        }

        $this->bumpAndDispatchIndexSync((int) $codeExample->knowledge_item_id);
    }
}
