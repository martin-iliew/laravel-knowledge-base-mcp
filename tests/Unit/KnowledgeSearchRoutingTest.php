<?php

use App\Services\KnowledgeSearchService;

function invokePrivateMethod(object $instance, string $method, array $args = []): mixed
{
    $reflection = new ReflectionMethod($instance, $method);
    $reflection->setAccessible(true);

    return $reflection->invokeArgs($instance, $args);
}

test('query profile routes one-token queries to lexical-first profile with no dense cutoff', function () {
    $service = new KnowledgeSearchService;

    $cfg = [
        'search_v2_single_token' => [
            'dense_k' => 30,
            'sparse_k' => 200,
            'fused_k' => 120,
            'min_similarity' => 0.1,
            'dense_weight' => 0.25,
            'sparse_weight' => 0.75,
            'fallback_k' => 80,
        ],
        'search_v2_short' => [],
        'search_v2_long' => [],
    ];

    $profile = invokePrivateMethod($service, 'queryProfile', ['stripe', $cfg]);

    expect($profile['is_single_token'])->toBeTrue();
    expect($profile['sparse_weight'])->toBeGreaterThan($profile['dense_weight']);
    expect($profile['min_similarity'])->toBeNull();
});

test('query profile routes long questions to dense-heavy profile', function () {
    $service = new KnowledgeSearchService;

    $cfg = [
        'search_v2_single_token' => [],
        'search_v2_short' => [],
        'search_v2_long' => [
            'dense_k' => 140,
            'sparse_k' => 80,
            'fused_k' => 120,
            'min_similarity' => 0.35,
            'dense_weight' => 0.6,
            'sparse_weight' => 0.4,
        ],
    ];

    $profile = invokePrivateMethod(
        $service,
        'queryProfile',
        ['how do i generate and store embeddings in laravel with pgvector?', $cfg]
    );

    expect($profile['is_single_token'])->toBeFalse();
    expect($profile['dense_weight'])->toBeGreaterThan($profile['sparse_weight']);
    expect($profile['dense_k'])->toBe(140);
});

test('weighted rrf prioritizes whichever signal has the higher weight', function () {
    $service = new KnowledgeSearchService;

    $lexicalHeavy = invokePrivateMethod(
        $service,
        'weightedRrf',
        [[1, 2, 3], [3, 2, 1], 0.25, 0.75, 60, 3]
    );

    $denseHeavy = invokePrivateMethod(
        $service,
        'weightedRrf',
        [[1, 2, 3], [3, 2, 1], 0.75, 0.25, 60, 3]
    );

    expect($lexicalHeavy[0])->toBe(3);
    expect($denseHeavy[0])->toBe(1);
});
