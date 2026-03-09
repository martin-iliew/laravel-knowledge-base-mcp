<?php

namespace App\Services\Embeddings;

/**
 * Driver-agnostic embedding result returned by all embedding drivers.
 */
class EmbeddingResult
{
    /**
     * @param  array<int, array<int, float>>  $embeddings  Vectors indexed by input position.
     * @param  string|null  $model  Model identifier reported by the provider (nullable).
     */
    public function __construct(
        public readonly array $embeddings,
        public readonly ?string $model = null,
    ) {}
}
