<?php

return [

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
