<?php

namespace App\Observers;

use App\Jobs\SyncKnowledgeItemIndex;
use App\Models\KnowledgeItem;

class KnowledgeItemObserver
{
    public function saving(KnowledgeItem $item): void
    {
        $item->content_hash = hash('sha256', $this->normalize($item->content_markdown ?? ''));

        if (!$item->chunk_size) {
            $item->chunk_size = (int) config('knowledge.defaults.chunk_size');
        }

        if (!$item->chunk_overlap && $item->chunk_overlap !== 0) {
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

    public function saved(KnowledgeItem $item): void
    {
        if (! $item->wasChanged('index_version')) {
            return;
        }

        SyncKnowledgeItemIndex::dispatch($item->id, (int) $item->index_version)->afterCommit();
    }

    private function normalize(string $s): string
    {
        $t = preg_replace('/\r\n?/', "\n", $s) ?? $s;
        return trim($t);
    }
}
