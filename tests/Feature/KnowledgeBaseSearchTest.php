<?php

use App\Models\KnowledgeAccountAccess;
use App\Models\User;
use App\Services\KnowledgeSearchService;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\KnowledgeTestFactory;

test('knowledge search page calls hybrid retrieval with current user scope', function () {
    $user = User::factory()->create();

    $searchMock = Mockery::mock(KnowledgeSearchService::class);
    $searchMock->shouldReceive('search')
        ->once()
        ->withArgs(function (
            string $query,
            int $limit,
            ?string $category,
            array $tags,
            bool $includeDrafts,
            ?int $userId
        ) use ($user): bool {
            return $query === 'routing'
                && $limit === 5
                && $category === 'Laravel'
                && $tags === ['mcp', 'routes']
                && $includeDrafts === true
                && $userId === $user->id;
        })
        ->andReturn([
            [
                'item' => [
                    'id' => 1,
                    'slug' => 'routing',
                    'title' => 'Routing',
                    'category' => 'Laravel',
                    'tags' => ['mcp', 'routes'],
                    'updated_at' => now()->toIso8601String(),
                ],
                'snippets' => [],
                'code_examples' => [],
                'resources' => [],
            ],
        ]);

    $this->app->instance(KnowledgeSearchService::class, $searchMock);

    $this->actingAs($user)
        ->get(route('knowledge-base.index', [
            'query' => 'routing',
            'category' => 'Laravel',
            'tags' => 'mcp, routes',
            'include_drafts' => 1,
            'limit' => 5,
        ]))
        ->assertInertia(fn (Assert $page) => $page
            ->component('knowledge-base/index')
            ->where('results.0.item.title', 'Routing')
        );
});

test('knowledge base search route applies granted access globally across all owners', function () {
    KnowledgeTestFactory::configureLexicalSearchOnly();

    $grantOwner = User::factory()->create();
    $unrelatedOwner = User::factory()->create();
    $grantee = User::factory()->create();

    KnowledgeAccountAccess::query()->create([
        'owner_user_id' => $grantOwner->id,
        'grantee_user_id' => $grantee->id,
        'permission' => 'viewer',
    ]);

    $item = KnowledgeTestFactory::createIndexedArticle(
        owner: $unrelatedOwner,
        slug: 'global-access-route-result',
        title: 'Global access shipment flow',
        content: 'Global access unique phrase for shipment creation lookup.',
        category: 'Integrations / Shipping',
        tags: ['provider-speedy', 'pattern-shipment']
    );

    $this->actingAs($grantee)
        ->get(route('knowledge-base.index', ['query' => 'global access unique phrase']))
        ->assertInertia(fn (Assert $page) => $page
            ->component('knowledge-base/index')
            ->where('results.0.item.slug', $item->slug)
        );
});

test('knowledge base search does not invoke retrieval for a blank query', function () {
    $user = User::factory()->create();
    $searchMock = Mockery::mock(KnowledgeSearchService::class);

    $searchMock->shouldReceive('search')->never();
    $this->app->instance(KnowledgeSearchService::class, $searchMock);

    $this->actingAs($user)
        ->get(route('knowledge-base.index', [
            'query' => '    ',
            'category' => 'Laravel',
            'tags' => 'mcp, routes',
        ]))
        ->assertInertia(fn (Assert $page) => $page
            ->component('knowledge-base/index')
            ->has('results', 0)
            ->where('filters.query', '')
            ->where('filters.category', 'Laravel')
            ->where('filters.tags', 'mcp, routes')
        );
});

test('legacy search route redirects to integrated knowledge base route', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('knowledge-search.index', ['query' => 'routing']))
        ->assertRedirect(route('knowledge-base.index', ['query' => 'routing']));
});
