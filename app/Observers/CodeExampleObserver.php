<?php

namespace App\Observers;

use App\Jobs\SyncKnowledgeItemIndex;
use App\Models\CodeExample;
use App\Models\KnowledgeItem;

class CodeExampleObserver
{
    public function saving(CodeExample $ex): void
    {
        $ex->code_hash = hash('sha256', $this->normalize($ex->code ?? ''));
    }

    public function saved(CodeExample $ex): void
    {
        $this->bumpAndDispatch($ex->knowledge_item_id);
    }

    public function deleted(CodeExample $ex): void
    {
        $this->bumpAndDispatch($ex->knowledge_item_id);
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
