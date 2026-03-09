<?php

namespace App\Services\Embeddings;

use Laravel\Ai\Embeddings;
use RuntimeException;

/**
 * Unified entry point for generating embeddings.
 *
 * Dispatches to the configured driver:
 *  - "laravel-ai"  (default) — delegates to the Laravel AI Embeddings facade / configured provider.
 *  - "jina-local"  — calls a locally-hosted jina-embeddings-v4 server via HTTP.
 */
class EmbeddingManager
{
    public function __construct(
        protected JinaLocalDriver $jinaLocal,
    ) {}

    /**
     * Generate embeddings for indexing (passages / documents).
     *
     * @param  array<int, string>  $texts
     */
    public function embedForIndexing(array $texts, int $dimensions): EmbeddingResult
    {
        return match ($this->driver()) {
            'jina-local' => $this->jinaLocal->embedForIndexing($texts, $dimensions),
            'laravel-ai' => $this->viaLaravelAi($texts, $dimensions),
            default => throw new RuntimeException("Unknown embedding driver: {$this->driver()}"),
        };
    }

    /**
     * Generate a single embedding vector for a search query.
     *
     * @return array<int, float>
     */
    public function embedQuery(string $query, int $dimensions): array
    {
        return match ($this->driver()) {
            'jina-local' => $this->jinaLocal->embedQuery($query, $dimensions),
            'laravel-ai' => $this->viaLaravelAi([$query], $dimensions)->embeddings[0]
                ?? throw new RuntimeException('Laravel AI returned no embedding for query.'),
            default => throw new RuntimeException("Unknown embedding driver: {$this->driver()}"),
        };
    }

    /**
     * Whether the active driver is "jina-local".
     */
    public function isJinaLocal(): bool
    {
        return $this->driver() === 'jina-local';
    }

    protected function driver(): string
    {
        return (string) config('knowledge.embedding.driver', 'laravel-ai');
    }

    /**
     * @param  array<int, string>  $texts
     */
    protected function viaLaravelAi(array $texts, int $dimensions): EmbeddingResult
    {
        $response = Embeddings::for($texts)->dimensions($dimensions)->generate();

        $model = $response->meta->model ?? null;

        return new EmbeddingResult($response->embeddings, is_string($model) ? $model : null);
    }
}
