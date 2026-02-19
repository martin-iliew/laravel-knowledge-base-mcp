<?php

namespace App\Observers\Concerns;

use App\Jobs\SyncKnowledgeItemIndex;
use Illuminate\Support\Facades\DB;

trait KnowledgeIndexingObserverHelpers
{
    /**
     * Normalize text for stable hashing (newline normalization + trim).
     *
     * @param string $text
     * @return string
     */
    protected function normalizeText(string $text): string
    {
        $normalized = preg_replace('/\r\n?/', "\n", $text) ?? $text;

        return trim($normalized);
    }

    /**
     * Compute a SHA-256 hash over normalized text.
     *
     * @param string $text
     * @return string
     */
    protected function sha256Normalized(string $text): string
    {
        return hash('sha256', $this->normalizeText($text));
    }

    /**
     * Dispatch a re-index job after commit.
     *
     * @param int $itemId
     * @param int $indexVersion
     * @return void
     */
    protected function dispatchIndexSync(int $itemId, int $indexVersion): void
    {
        SyncKnowledgeItemIndex::dispatch($itemId, $indexVersion)->afterCommit();
    }

    /**
     * Atomically bump index_version on a knowledge item and return the new version.
     * Postgres-specific (UPDATE ... RETURNING).
     *
     * @param int $itemId
     * @return int
     */
    protected function bumpIndexVersionAndReturn(int $itemId): int
    {
        $row = DB::selectOne(
            'UPDATE knowledge_items
             SET index_version = index_version + 1
             WHERE id = ?
             RETURNING index_version',
            [$itemId]
        );

        return (int) ($row->index_version ?? 0);
    }

    /**
     * Convenience: bump index_version and dispatch the indexing job after commit.
     *
     * @param int $itemId
     * @return void
     */
    protected function bumpAndDispatchIndexSync(int $itemId): void
    {
        if ($itemId <= 0) {
            return;
        }

        $version = $this->bumpIndexVersionAndReturn($itemId);

        if ($version > 0) {
            $this->dispatchIndexSync($itemId, $version);
        }
    }
}
