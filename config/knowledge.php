<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Embedding Driver
    |--------------------------------------------------------------------------
    |
    | Controls which embedding backend is used for indexing and search.
    |
    | Supported drivers:
    |   - "laravel-ai" (default): delegates to the Laravel AI Embeddings facade
    |     using the provider configured in config/ai.php.
    |   - "jina-local": calls a locally-hosted jina-embeddings-v4 server via
    |     its OpenAI-compatible /v1/embeddings endpoint.
    |
    */

    'embedding' => [
        'driver' => env('KB_EMBEDDING_DRIVER', 'laravel-ai'),

        'jina_local' => [
            'base_url' => env('JINA_LOCAL_BASE_URL', 'http://172.24.64.1:8081'),
            'timeout' => (int) env('JINA_LOCAL_TIMEOUT', 120),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Defaults
    |--------------------------------------------------------------------------
    |
    | Base parameters for ingestion and retrieval.
    |
    | - chunk_size: Target characters per chunk (before overlap).
    | - chunk_overlap: Characters overlapped between adjacent chunks.
    | - embedding_dimensions: Vector dimensions (must match your embedding model).
    | - min_similarity: Minimum cosine similarity threshold for dense retrieval.
    |
    */

    'defaults' => [
        'chunk_size' => (int) env('KB_CHUNK_SIZE', 1000),
        'chunk_overlap' => (int) env('KB_CHUNK_OVERLAP', 200),
        'embedding_dimensions' => (int) env('KB_EMBED_DIMS', 1536),
        'min_similarity' => (float) env('KB_MIN_SIMILARITY', 0.35),
    ],

    /*
    |--------------------------------------------------------------------------
    | Hybrid Retrieval
    |--------------------------------------------------------------------------
    |
    | Controls candidate generation and fusion when using dense + sparse retrieval.
    |
    | - dense_k: Number of candidates from vector search (pgvector).
    | - sparse_k: Number of candidates from keyword / FTS search.
    | - fused_k: Number of candidates kept after fusion (before rerank).
    | - rrf_k: Reciprocal Rank Fusion constant (higher reduces early-rank bias).
    | - rerank_k: Number of candidates sent to reranker (AI or heuristic).
    | - enable_ai_rerank: If true, uses provider reranking; else skip AI rerank.
    | - fts_config: PostgreSQL text search configuration (e.g. 'simple', 'english').
    |
    */

    'hybrid' => [
        'dense_k' => (int) env('KB_DENSE_K', 60),
        'sparse_k' => (int) env('KB_SPARSE_K', 60),
        'fused_k' => (int) env('KB_FUSED_K', 80),
        'rrf_k' => (int) env('KB_RRF_K', 60),
        'rerank_k' => (int) env('KB_RERANK_K', 50),
        'enable_ai_rerank' => (bool) env('KB_ENABLE_AI_RERANK', false),
        'fts_config' => (string) env('KB_FTS_CONFIG', 'simple'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Search V2
    |--------------------------------------------------------------------------
    |
    | Optional query-adaptive retrieval controls.
    |
    | - enabled: toggles adaptive profile selection.
    | - profiles.search_v2_single_token: lexical-heavy for short/ID-like queries.
    | - profiles.search_v2_short: balanced defaults for normal queries.
    | - profiles.search_v2_long: denser + broader fallback for long natural language.
    |
    */

    'search_v2' => [
        'enabled' => (bool) env('KB_SEARCH_V2_ENABLED', true),
        'profiles' => [
            'search_v2_single_token' => [
                'dense_k' => (int) env('KB_V2_SINGLE_DENSE_K', 35),
                'sparse_k' => (int) env('KB_V2_SINGLE_SPARSE_K', 140),
                'fused_k' => (int) env('KB_V2_SINGLE_FUSED_K', 100),
                'fallback_k' => (int) env('KB_V2_SINGLE_FALLBACK_K', 120),
                'min_similarity' => null,
                'dense_weight' => (float) env('KB_V2_SINGLE_DENSE_WEIGHT', 0.3),
                'sparse_weight' => (float) env('KB_V2_SINGLE_SPARSE_WEIGHT', 0.7),
            ],
            'search_v2_short' => [
                'dense_k' => (int) env('KB_V2_SHORT_DENSE_K', 60),
                'sparse_k' => (int) env('KB_V2_SHORT_SPARSE_K', 70),
                'fused_k' => (int) env('KB_V2_SHORT_FUSED_K', 90),
                'fallback_k' => (int) env('KB_V2_SHORT_FALLBACK_K', 100),
                'min_similarity' => (float) env('KB_V2_SHORT_MIN_SIMILARITY', 0.35),
                'dense_weight' => (float) env('KB_V2_SHORT_DENSE_WEIGHT', 0.5),
                'sparse_weight' => (float) env('KB_V2_SHORT_SPARSE_WEIGHT', 0.5),
            ],
            'search_v2_long' => [
                'dense_k' => (int) env('KB_V2_LONG_DENSE_K', 110),
                'sparse_k' => (int) env('KB_V2_LONG_SPARSE_K', 90),
                'fused_k' => (int) env('KB_V2_LONG_FUSED_K', 120),
                'fallback_k' => (int) env('KB_V2_LONG_FALLBACK_K', 130),
                'min_similarity' => (float) env('KB_V2_LONG_MIN_SIMILARITY', 0.3),
                'dense_weight' => (float) env('KB_V2_LONG_DENSE_WEIGHT', 0.6),
                'sparse_weight' => (float) env('KB_V2_LONG_SPARSE_WEIGHT', 0.4),
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | MCP
    |--------------------------------------------------------------------------
    |
    | Settings for MCP access behavior.
    |
    | - require_auth: If true, MCP routes must be authenticated.
    | - default_user_id: Fallback user ID for non-auth/dev mode.
    |
    */

    'mcp' => [
        'require_auth' => (bool) env('KB_MCP_REQUIRE_AUTH', false),
        'default_user_id' => env('KB_MCP_DEFAULT_USER_ID', 1),
    ],

    /*
    |--------------------------------------------------------------------------
    | Limits
    |--------------------------------------------------------------------------
    |
    | Hard caps used when assembling responses (cost + latency control).
    |
    | - items: Max knowledge items returned.
    | - chunks_per_item: Max chunks per item in the final payload.
    | - code_examples_per_item: Max code examples per item in the final payload.
    | - resources_per_item: Max resources per item in the final payload.
    |
    */

    'limits' => [
        'items' => (int) env('KB_ITEMS_LIMIT', 5),
        'chunks_per_item' => (int) env('KB_MAX_CHUNKS_PER_ITEM', 3),
        'code_examples_per_item' => (int) env('KB_MAX_CODE_PER_ITEM', 3),
        'resources_per_item' => (int) env('KB_MAX_RES_PER_ITEM', 3),
    ],
];
