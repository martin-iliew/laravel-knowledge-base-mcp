<?php

use App\Models\KnowledgeAccountAccess;
use App\Models\User;
use App\Services\KnowledgeSearchService;
use Tests\Support\KnowledgeTestFactory;

test('search includes owner items shared with the active grantee', function () {
    KnowledgeTestFactory::configureLexicalSearchOnly();

    $owner = User::factory()->create();
    $grantee = User::factory()->create();

    KnowledgeAccountAccess::query()->create([
        'owner_user_id' => $owner->id,
        'grantee_user_id' => $grantee->id,
        'permission' => 'viewer',
    ]);

    $sharedItem = KnowledgeTestFactory::createIndexedArticle(
        owner: $owner,
        slug: 'shared-tbi-entry',
        title: 'TBI BNPL callback flow',
        content: 'Use TBI authorize token and sync application status callback.',
        category: 'Integrations / Financing',
        tags: ['provider-tbi', 'bnpl']
    );

    $results = app(KnowledgeSearchService::class)->search(
        query: 'tbi callback',
        limit: 5,
        includeDrafts: true,
        userId: $grantee->id
    );

    expect(KnowledgeTestFactory::resultSlugs($results))->toContain($sharedItem->slug);
});

test('search excludes owner items when no sharing grant exists', function () {
    KnowledgeTestFactory::configureLexicalSearchOnly();

    $owner = User::factory()->create();
    $unrelatedUser = User::factory()->create();

    $ownerItem = KnowledgeTestFactory::createIndexedArticle(
        owner: $owner,
        slug: 'private-owner-item',
        title: 'Stripe payout retries',
        content: 'Retry payout webhooks with idempotency keys.',
        category: 'Integrations / Payments',
        tags: ['provider-stripe']
    );

    $results = app(KnowledgeSearchService::class)->search(
        query: 'stripe payout retries',
        limit: 5,
        includeDrafts: true,
        userId: $unrelatedUser->id
    );

    expect(KnowledgeTestFactory::resultSlugs($results))->not->toContain($ownerItem->slug);
});

test('search supports long natural-language queries through relaxed lexical fallback', function () {
    KnowledgeTestFactory::configureLexicalSearchOnly();

    $owner = User::factory()->create();
    $item = KnowledgeTestFactory::createIndexedArticle(
        owner: $owner,
        slug: 'stripe-checkout-long-query',
        title: 'Stripe Checkout + webhook idempotent flow',
        content: 'Create checkout session, verify webhook signature, and process idempotent fulfillment.',
        category: 'Integrations / Payments',
        tags: ['provider-stripe', 'pattern-webhooks']
    );

    $results = app(KnowledgeSearchService::class)->search(
        query: 'how to create stripe checkout session and handle webhook idempotently',
        limit: 5,
        includeDrafts: true,
        userId: $owner->id
    );

    expect(KnowledgeTestFactory::resultSlugs($results))->toContain($item->slug);
});

test('search resolves typo queries through trigram fuzzy fallback', function () {
    KnowledgeTestFactory::configureLexicalSearchOnly();

    $owner = User::factory()->create();
    $item = KnowledgeTestFactory::createIndexedArticle(
        owner: $owner,
        slug: 'stripe-typo-entry',
        title: 'Stripe webhook handling',
        content: 'Stripe webhook signature verification and queueing strategy.',
        category: 'Integrations / Payments',
        tags: ['provider-stripe']
    );

    $results = app(KnowledgeSearchService::class)->search(
        query: 'strpie',
        limit: 5,
        includeDrafts: true,
        userId: $owner->id
    );

    expect(KnowledgeTestFactory::resultSlugs($results))->toContain($item->slug);
});

test('search matches quoted phrases despite mixed casing and punctuation', function () {
    KnowledgeTestFactory::configureLexicalSearchOnly();

    $owner = User::factory()->create();
    $item = KnowledgeTestFactory::createIndexedArticle(
        owner: $owner,
        slug: 'quoted-phrase-stripe',
        title: 'Webhook signature verification guide',
        content: 'Always verify webhook signature before processing events.',
        category: 'Integrations / Payments',
        tags: ['provider-stripe']
    );

    $results = app(KnowledgeSearchService::class)->search(
        query: '"WEBHOOK signature" !!!',
        limit: 5,
        includeDrafts: true,
        userId: $owner->id
    );

    expect(KnowledgeTestFactory::resultSlugs($results))->toContain($item->slug);
});

test('search applies category and tag filters while retrieving matches', function () {
    KnowledgeTestFactory::configureLexicalSearchOnly();

    $owner = User::factory()->create();

    $paymentsItem = KnowledgeTestFactory::createIndexedArticle(
        owner: $owner,
        slug: 'stripe-payments-filter-hit',
        title: 'Stripe subscription webhook flow',
        content: 'Stripe subscription webhook setup and retry policy.',
        category: 'Integrations / Payments',
        tags: ['provider-stripe', 'subscriptions']
    );

    KnowledgeTestFactory::createIndexedArticle(
        owner: $owner,
        slug: 'stripe-shipping-filter-miss',
        title: 'Stripe shipping label metadata',
        content: 'Stripe metadata mapped to shipping label payload.',
        category: 'Integrations / Shipping',
        tags: ['provider-stripe', 'logistics']
    );

    $results = app(KnowledgeSearchService::class)->search(
        query: 'stripe webhook',
        limit: 5,
        category: 'Integrations / Payments',
        tags: ['subscriptions'],
        includeDrafts: true,
        userId: $owner->id
    );

    expect(KnowledgeTestFactory::resultSlugs($results))
        ->toContain($paymentsItem->slug)
        ->not->toContain('stripe-shipping-filter-miss');
});
