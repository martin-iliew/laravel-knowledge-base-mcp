<?php

namespace App\Observers;

use App\Jobs\SyncKnowledgeItemIndex;
use App\Models\KnowledgeItem;
use App\Models\KnowledgeResource;

class KnowledgeResourceObserver
{
    public function saving(KnowledgeResource $r): void
    {
        if ($r->extracted_text) {
            $r->extracted_hash = hash('sha256', $this->normalize($r->extracted_text));
        }
    }

    public function saved(KnowledgeResource $r): void
    {
        $this->bumpAndDispatch($r->knowledge_item_id);
    }

    public function deleted(KnowledgeResource $r): void
    {
        $this->bumpAndDispatch($r->knowledge_item_id);
    }

    private function bumpAndDispatch(int $itemId): void
    {
        KnowledgeItem::query()->whereKey($itemId)->increment('index_version');
        $v = (int) KnowledgeItem::query()->whereKey($itemId)->value('index_version');

        SyncKnowledgeItemIndex::dispatch($itemId, $v)->afterCommit();
    }

    private function normalize(string $s): string
    {
        $t = preg_replace('/\r\n?/', "\n", $s) ?? $s;
        return trim($t);
    }
}
