<?php

namespace App\Services\Embeddings;

use Illuminate\Http\Client\Factory as HttpFactory;
use RuntimeException;

/**
 * Embedding driver for a locally-hosted jina-embeddings-v4 server (e.g. llama-server).
 *
 * Communicates via an OpenAI-compatible /v1/embeddings endpoint.
 *
 * Key jina-embeddings-v4 specifics handled here:
 * - Task prefixes: "Passage: " for indexing, "Query: " for search queries.
 * - Matryoshka truncation: the server returns full 2048-dim vectors; this driver
 *   truncates client-side when fewer dimensions are requested.
 */
class JinaLocalDriver
{
    public function __construct(
        protected HttpFactory $http,
    ) {}

    /**
     * Generate embeddings for indexing (documents / passages).
     *
     * @param  array<int, string>  $texts
     */
    public function embedForIndexing(array $texts, int $dimensions): EmbeddingResult
    {
        $prefixed = array_map(fn (string $text): string => 'Passage: '.$text, $texts);

        return $this->request($prefixed, $dimensions);
    }

    /**
     * Generate a single embedding for a search query.
     *
     * @return array<int, float>
     */
    public function embedQuery(string $query, int $dimensions): array
    {
        $result = $this->request(['Query: '.$query], $dimensions);

        return $result->embeddings[0] ?? throw new RuntimeException('Jina local server returned no embedding for query.');
    }

    /**
     * @param  array<int, string>  $inputs  Already-prefixed texts.
     */
    protected function request(array $inputs, int $dimensions): EmbeddingResult
    {
        $baseUrl = rtrim((string) config('knowledge.embedding.jina_local.base_url'), '/');
        $timeout = (int) config('knowledge.embedding.jina_local.timeout', 120);

        $response = $this->http
            ->timeout($timeout)
            ->post($baseUrl.'/v1/embeddings', [
                'input' => array_values($inputs),
            ]);

        if (! $response->successful()) {
            throw new RuntimeException(
                "Jina local embedding server returned HTTP {$response->status()}: {$response->body()}"
            );
        }

        $body = $response->json();

        if (! is_array($body) || ! isset($body['data']) || ! is_array($body['data'])) {
            throw new RuntimeException('Jina local embedding server returned an unexpected response format.');
        }

        $model = $body['model'] ?? null;

        // Sort by index to guarantee input order.
        $sorted = collect($body['data'])->sortBy('index')->values();

        $embeddings = $sorted->map(function (array $item) use ($dimensions): array {
            $vector = $item['embedding'] ?? [];

            if (! is_array($vector) || $vector === []) {
                throw new RuntimeException('Jina local embedding server returned an empty vector.');
            }

            // Matryoshka truncation: slice to requested dimensions.
            if (count($vector) > $dimensions) {
                $vector = array_slice($vector, 0, $dimensions);
            }

            return $vector;
        })->all();

        return new EmbeddingResult($embeddings, is_string($model) ? $model : null);
    }
}
