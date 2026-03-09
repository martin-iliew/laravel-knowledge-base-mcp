<?php

namespace App\Services\Embeddings;

use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Schema;

class EmbeddingDimensionResolver
{
    private ?int $resolvedDimensions = null;

    public function __construct(
        protected DatabaseManager $database,
    ) {}

    /**
     * Resolve the effective embedding dimensions for storage/search.
     */
    public function resolve(): int
    {
        if (is_int($this->resolvedDimensions)) {
            return $this->resolvedDimensions;
        }

        $configuredDimensions = max(1, (int) config('knowledge.defaults.embedding_dimensions', 1536));

        if ($this->database->getDriverName() !== 'pgsql') {
            return $this->resolvedDimensions = $configuredDimensions;
        }

        if (! Schema::hasTable('knowledge_chunks') || ! Schema::hasColumn('knowledge_chunks', 'embedding')) {
            return $this->resolvedDimensions = $configuredDimensions;
        }

        $vectorType = $this->resolveVectorType();

        if (! $vectorType) {
            return $this->resolvedDimensions = $configuredDimensions;
        }

        if (preg_match('/^vector\((\d+)\)$/', $vectorType, $matches) !== 1) {
            return $this->resolvedDimensions = $configuredDimensions;
        }

        $databaseDimensions = (int) ($matches[1] ?? 0);

        if ($databaseDimensions <= 0) {
            return $this->resolvedDimensions = $configuredDimensions;
        }

        return $this->resolvedDimensions = $databaseDimensions;
    }

    private function resolveVectorType(): ?string
    {
        try {
            $row = $this->database->selectOne(
                <<<'SQL'
                    select format_type(a.atttypid, a.atttypmod) as type_name
                    from pg_attribute a
                    join pg_class c on c.oid = a.attrelid
                    join pg_namespace n on n.oid = c.relnamespace
                    where n.nspname = current_schema()
                      and c.relname = ?
                      and a.attname = ?
                      and a.attnum > 0
                      and not a.attisdropped
                    limit 1
                SQL,
                ['knowledge_chunks', 'embedding']
            );
        } catch (\Throwable) {
            return null;
        }

        if (! is_object($row) || ! isset($row->type_name) || ! is_string($row->type_name)) {
            return null;
        }

        return trim($row->type_name);
    }
}
