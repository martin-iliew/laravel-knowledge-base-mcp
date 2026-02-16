<?php

return [
    'defaults' => [
        'chunk_size' => (int) env('KB_CHUNK_SIZE', 1000),
        'chunk_overlap' => (int) env('KB_CHUNK_OVERLAP', 200),
        'embedding_dimensions' => (int) env('KB_EMBED_DIMS', 1536),
        'min_similarity' => (float) env('KB_MIN_SIMILARITY', 0.35),
    ],

    'hybrid' => [
        'dense_k' => (int) env('KB_DENSE_K', 60),
        'sparse_k' => (int) env('KB_SPARSE_K', 60),
        'fused_k' => (int) env('KB_FUSED_K', 80),
        'rrf_k' => (int) env('KB_RRF_K', 60),
        'rerank_k' => (int) env('KB_RERANK_K', 50),
        'fts_config' => (string) env('KB_FTS_CONFIG', 'simple'),
    ],

    'limits' => [
        'items' => (int) env('KB_ITEMS_LIMIT', 5),
        'chunks_per_item' => (int) env('KB_MAX_CHUNKS_PER_ITEM', 3),
        'code_examples_per_item' => (int) env('KB_MAX_CODE_PER_ITEM', 3),
        'resources_per_item' => (int) env('KB_MAX_RES_PER_ITEM', 3),
    ],
];
