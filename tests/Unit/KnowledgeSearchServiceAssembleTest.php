<?php

use Tests\Support\KnowledgeSearchAssembleFixtures;
use Tests\TestCase;

uses(TestCase::class);

test('assemble keeps rerank score as float when available', function () {
    $item = KnowledgeSearchAssembleFixtures::fakeItem(10, '2026-02-17T07:53:05+00:00');

    $chunks = collect([
        KnowledgeSearchAssembleFixtures::fakeArticleChunk($item, 0, 'scored snippet', 0, 0.91, 0),
    ]);

    $results = KnowledgeSearchAssembleFixtures::assemble($chunks);

    expect($results)->toHaveCount(1);
    expect($results[0]['snippets'])->toHaveCount(1);
    expect($results[0]['snippets'][0]['score'])->toBeFloat()->toBe(0.91);
});

test('assemble orders snippets by rerank score desc when scores exist', function () {
    $item = KnowledgeSearchAssembleFixtures::fakeItem(11, '2026-02-17T07:53:05+00:00');

    $chunks = collect([
        KnowledgeSearchAssembleFixtures::fakeArticleChunk($item, 0, 'lower score', 0, 0.2, 1),
        KnowledgeSearchAssembleFixtures::fakeArticleChunk($item, 1, 'higher score', 3, 0.9, 0),
    ]);

    $results = KnowledgeSearchAssembleFixtures::assemble($chunks);
    $texts = array_map(fn (array $s) => $s['text'], $results[0]['snippets']);

    expect($texts)->toBe(['higher score', 'lower score']);
});

test('assemble orders snippets by fused rank when rerank score is missing', function () {
    $item = KnowledgeSearchAssembleFixtures::fakeItem(12, '2026-02-17T07:53:05+00:00');

    $chunks = collect([
        KnowledgeSearchAssembleFixtures::fakeArticleChunk($item, 0, 'later fused rank', 4),
        KnowledgeSearchAssembleFixtures::fakeArticleChunk($item, 1, 'earlier fused rank', 1),
    ]);

    $results = KnowledgeSearchAssembleFixtures::assemble($chunks);
    $texts = array_map(fn (array $s) => $s['text'], $results[0]['snippets']);

    expect($texts)->toBe(['earlier fused rank', 'later fused rank']);
    expect($results[0]['snippets'][0]['score'])->toBeNull();
    expect($results[0]['snippets'][1]['score'])->toBeNull();
});

test('assemble returns updated_at in iso8601 with utc offset', function () {
    $item = KnowledgeSearchAssembleFixtures::fakeItem(13, '2026-02-17 01:53:05');

    $chunks = collect([
        KnowledgeSearchAssembleFixtures::fakeArticleChunk($item, 0, 'timezone check', 0),
    ]);

    $results = KnowledgeSearchAssembleFixtures::assemble($chunks);

    expect($results[0]['item']['updated_at'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/');
});
