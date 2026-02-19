<?php

use App\Jobs\SyncKnowledgeItemIndex;
use App\Models\CodeExample;
use App\Models\KnowledgeAccountAccess;
use App\Models\KnowledgeItem;
use App\Models\KnowledgeResource;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\KnowledgeTestFactory;

test('dashboard shows summary counts for accessible knowledge items', function () {
    $owner = User::factory()->create();
    $otherUser = User::factory()->create();

    $mine = KnowledgeTestFactory::createOwnedItem($owner, 'mine-item');
    KnowledgeTestFactory::createOwnedItem($otherUser, 'other-item');

    $this->actingAs($owner)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('dashboard')
            ->where('summary.total_items', 1)
            ->where('summary.draft_items', 1)
            ->where('summary.published_items', 0)
            ->where('summary.archived_items', 0)
            ->has('recentItems', 1)
            ->where('recentItems.0.id', $mine->id)
        );
});

test('knowledge base page shows latest cards only for accessible items', function () {
    $owner = User::factory()->create();
    $otherUser = User::factory()->create();

    $mine = KnowledgeTestFactory::createOwnedItem($owner, 'mine-card', [
        'status' => 'published',
        'published_at' => now(),
    ]);
    KnowledgeTestFactory::createOwnedItem($otherUser, 'other-card', [
        'status' => 'published',
        'published_at' => now(),
    ]);

    $this->actingAs($owner)
        ->get(route('knowledge-base.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('knowledge-base/index')
            ->missing('tagOptions')
            ->has('latestItems', 1)
            ->where('latestItems.0.id', $mine->id)
        );
});

test('knowledge base list supports category and tag filters without a query', function () {
    $owner = User::factory()->create();

    $matchingItem = KnowledgeTestFactory::createOwnedItem($owner, 'laravel-routes', [
        'category' => 'Laravel',
        'tags' => ['mcp', 'routes'],
        'status' => 'published',
        'published_at' => now(),
    ]);

    KnowledgeTestFactory::createOwnedItem($owner, 'laravel-api', [
        'category' => 'Laravel',
        'tags' => ['mcp'],
        'status' => 'published',
        'published_at' => now(),
    ]);

    KnowledgeTestFactory::createOwnedItem($owner, 'billing-routes', [
        'category' => 'Billing',
        'tags' => ['mcp', 'routes'],
        'status' => 'published',
        'published_at' => now(),
    ]);

    $this->actingAs($owner)
        ->get(route('knowledge-base.index', [
            'category' => 'Laravel',
            'tags' => 'mcp, routes',
        ]))
        ->assertInertia(fn (Assert $page) => $page
            ->component('knowledge-base/index')
            ->has('results', 0)
            ->has('latestItems', 1)
            ->where('latestItems.0.id', $matchingItem->id)
        );
});

test('knowledge base list hides drafts when include_drafts is disabled', function () {
    $owner = User::factory()->create();

    KnowledgeTestFactory::createOwnedItem($owner, 'draft-only', [
        'status' => 'draft',
    ]);

    $publishedItem = KnowledgeTestFactory::createOwnedItem($owner, 'published-only', [
        'status' => 'published',
        'published_at' => now(),
    ]);

    $this->actingAs($owner)
        ->get(route('knowledge-base.index', [
            'include_drafts' => 0,
        ]))
        ->assertInertia(fn (Assert $page) => $page
            ->component('knowledge-base/index')
            ->has('latestItems', 1)
            ->where('latestItems.0.id', $publishedItem->id)
        );
});

test('knowledge item create form starts at details step', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('knowledge-items.create'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('knowledge-items/form')
            ->where('mode', 'create')
            ->where('step', 'details')
        );
});

test('knowledge item edit form supports details and enhancements steps', function () {
    $user = User::factory()->create();
    $item = KnowledgeTestFactory::createOwnedItem($user, 'multi-step-item');

    $this->actingAs($user)
        ->get(route('knowledge-items.edit', $item))
        ->assertInertia(fn (Assert $page) => $page
            ->component('knowledge-items/form')
            ->where('mode', 'edit')
            ->where('step', 'details')
        );

    $this->actingAs($user)
        ->get(route('knowledge-items.edit', [
            'knowledgeItem' => $item,
            'step' => 'enhancements',
        ]))
        ->assertInertia(fn (Assert $page) => $page
            ->component('knowledge-items/form')
            ->where('mode', 'edit')
            ->where('step', 'enhancements')
        );
});

test('knowledge item can be created for authenticated user with generated slug', function () {
    Queue::fake();

    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('knowledge-items.store'), [
        'title' => 'My New Item',
        'content_markdown' => 'Body',
        'category' => 'Guides',
        'tags' => 'laravel, mcp',
        'status' => 'draft',
    ]);

    $created = KnowledgeItem::query()->where('created_by', $user->id)->first();

    expect($created)->not->toBeNull();
    expect($created->slug)->toBe('my-new-item');
    expect($created->tags)->toBe(['laravel', 'mcp']);
    expect($created->status)->toBe('published');
    expect($created->published_at)->not->toBeNull();

    $response->assertRedirect(route('knowledge-items.edit', [
        'knowledgeItem' => $created,
        'step' => 'enhancements',
    ]));

    Queue::assertPushed(SyncKnowledgeItemIndex::class);
});

test('knowledge markdown preview endpoint renders sanitized html', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->post(route('knowledge-items.markdown-preview'), [
            'content_markdown' => "# Heading\n\n<script>alert('xss')</script>\n\n**bold**",
        ]);

    $response->assertOk();
    $response->assertJsonStructure(['html']);

    $html = (string) $response->json('html');

    expect($html)->toContain('<h1>Heading</h1>');
    expect($html)->toContain('<strong>bold</strong>');
    expect($html)->not->toContain('<script>');
});

test('knowledge item update and delete are owner only', function () {
    $owner = User::factory()->create();
    $otherUser = User::factory()->create();
    $item = KnowledgeTestFactory::createOwnedItem($owner, 'owner-item');

    $this->actingAs($otherUser)
        ->patch(route('knowledge-items.update', $item), [
            'title' => 'Nope',
            'content_markdown' => 'Nope',
            'category' => null,
            'tags' => [],
            'status' => 'draft',
        ])
        ->assertForbidden();

    $this->actingAs($otherUser)
        ->delete(route('knowledge-items.destroy', $item))
        ->assertForbidden();
});

test('knowledge reader page is available for authorized user', function () {
    $user = User::factory()->create();
    $item = KnowledgeTestFactory::createOwnedItem($user, 'reader-item');

    $this->actingAs($user)
        ->get(route('knowledge-base.show', $item))
        ->assertInertia(fn (Assert $page) => $page
            ->component('knowledge-base/show')
            ->where('item.id', $item->id)
            ->where('permissions.can_update', true)
        );
});

test('code examples and resources are owner only', function () {
    $owner = User::factory()->create();
    $otherUser = User::factory()->create();
    $item = KnowledgeTestFactory::createOwnedItem($owner, 'owner-with-assets');

    $codeExample = CodeExample::withoutEvents(fn () => CodeExample::query()->create([
        'knowledge_item_id' => $item->id,
        'sort_order' => 1,
        'language' => 'php',
        'code' => '<?php echo "ok";',
    ]));

    $resource = KnowledgeResource::withoutEvents(fn () => KnowledgeResource::query()->create([
        'knowledge_item_id' => $item->id,
        'type' => 'link',
        'url' => 'https://example.com',
    ]));

    $this->actingAs($otherUser)
        ->patch(route('knowledge-items.code-examples.update', [$item, $codeExample]), [
            'language' => 'php',
            'code' => 'forbidden',
        ])
        ->assertForbidden();

    $this->actingAs($otherUser)
        ->patch(route('knowledge-items.resources.update', [$item, $resource]), [
            'type' => 'link',
            'url' => 'https://example.com/new',
        ])
        ->assertForbidden();
});

test('shared viewer can see owner item on dashboard but cannot update it', function () {
    $owner = User::factory()->create();
    $viewer = User::factory()->create();
    $item = KnowledgeTestFactory::createOwnedItem($owner, 'shared-viewer-item', [
        'status' => 'published',
        'published_at' => now(),
    ]);

    KnowledgeAccountAccess::query()->create([
        'owner_user_id' => $owner->id,
        'grantee_user_id' => $viewer->id,
        'permission' => 'viewer',
    ]);

    $this->actingAs($viewer)
        ->get(route('knowledge-base.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('knowledge-base/index')
            ->has('latestItems', 1)
            ->where('latestItems.0.id', $item->id)
        );

    $this->actingAs($viewer)
        ->get(route('knowledge-base.show', $item))
        ->assertInertia(fn (Assert $page) => $page
            ->component('knowledge-base/show')
            ->where('permissions.access_level', 'viewer')
            ->where('permissions.can_update', false)
        );

    $this->actingAs($viewer)
        ->patch(route('knowledge-items.update', $item), [
            'title' => 'Viewer change attempt',
            'content_markdown' => 'No access',
            'category' => 'Shared',
            'tags' => ['shared'],
            'status' => 'draft',
        ])
        ->assertForbidden();
});

test('viewer grant remains read-only for shared items but can create own items', function () {
    $grantOwner = User::factory()->create();
    $viewer = User::factory()->create();
    $firstAuthor = User::factory()->create();
    $secondAuthor = User::factory()->create();

    KnowledgeAccountAccess::query()->create([
        'owner_user_id' => $grantOwner->id,
        'grantee_user_id' => $viewer->id,
        'permission' => 'viewer',
    ]);

    $firstItem = KnowledgeTestFactory::createOwnedItem($firstAuthor, 'viewer-global-first', [
        'status' => 'published',
        'published_at' => now(),
    ]);
    $secondItem = KnowledgeTestFactory::createOwnedItem($secondAuthor, 'viewer-global-second', [
        'status' => 'published',
        'published_at' => now(),
    ]);

    $this->actingAs($viewer)
        ->get(route('knowledge-base.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('knowledge-base/index')
            ->where('latestItems', fn ($items): bool => collect($items)
                ->pluck('id')
                ->intersect([$firstItem->id, $secondItem->id])
                ->count() === 2)
        );

    $this->actingAs($viewer)
        ->get(route('knowledge-base.show', $firstItem))
        ->assertInertia(fn (Assert $page) => $page
            ->component('knowledge-base/show')
            ->where('permissions.access_level', 'viewer')
            ->where('permissions.can_update', false)
        );

    $this->actingAs($viewer)
        ->patch(route('knowledge-items.update', $secondItem), [
            'title' => 'Viewer cannot update',
            'content_markdown' => 'Still read only',
            'category' => 'Nope',
            'tags' => ['nope'],
            'status' => 'draft',
        ])
        ->assertForbidden();

    $this->actingAs($viewer)
        ->post(route('knowledge-items.store'), [
            'title' => 'Viewer can create own item',
            'content_markdown' => 'Own content remains editable',
        ])
        ->assertSessionHasNoErrors();

    $createdByViewer = KnowledgeItem::query()
        ->where('created_by', $viewer->id)
        ->latest('id')
        ->first();

    expect($createdByViewer)->not->toBeNull();
    expect($createdByViewer?->status)->toBe('published');
});

test('access level label is owner only for authored items', function () {
    $owner = User::factory()->create();
    $editor = User::factory()->create();

    $ownedByEditor = KnowledgeTestFactory::createOwnedItem($editor, 'editor-owned-item', [
        'status' => 'published',
        'published_at' => now(),
    ]);
    $sharedFromOwner = KnowledgeTestFactory::createOwnedItem($owner, 'owner-shared-item', [
        'status' => 'published',
        'published_at' => now(),
    ]);

    KnowledgeAccountAccess::query()->create([
        'owner_user_id' => $owner->id,
        'grantee_user_id' => $editor->id,
        'permission' => 'editor',
    ]);

    $this->actingAs($editor)
        ->get(route('knowledge-items.edit', $ownedByEditor))
        ->assertInertia(fn (Assert $page) => $page
            ->component('knowledge-items/form')
            ->where('permissions.access_level', 'owner')
        );

    $this->actingAs($editor)
        ->get(route('knowledge-items.edit', $sharedFromOwner))
        ->assertInertia(fn (Assert $page) => $page
            ->component('knowledge-items/form')
            ->where('permissions.access_level', 'editor')
        );

    $this->actingAs($editor)
        ->get(route('knowledge-base.show', $sharedFromOwner))
        ->assertInertia(fn (Assert $page) => $page
            ->component('knowledge-base/show')
            ->where('permissions.access_level', 'editor')
        );
});

test('editor grant is not downgraded by additional viewer grants', function () {
    $viewerGrantOwner = User::factory()->create();
    $editorGrantOwner = User::factory()->create();
    $grantee = User::factory()->create();
    $unrelatedOwner = User::factory()->create();

    KnowledgeAccountAccess::query()->create([
        'owner_user_id' => $viewerGrantOwner->id,
        'grantee_user_id' => $grantee->id,
        'permission' => 'viewer',
    ]);

    KnowledgeAccountAccess::query()->create([
        'owner_user_id' => $editorGrantOwner->id,
        'grantee_user_id' => $grantee->id,
        'permission' => 'editor',
    ]);

    $item = KnowledgeTestFactory::createOwnedItem($unrelatedOwner, 'mixed-grants-editor-item', [
        'status' => 'published',
        'published_at' => now(),
    ]);

    $this->actingAs($grantee)
        ->get(route('knowledge-base.show', $item))
        ->assertInertia(fn (Assert $page) => $page
            ->component('knowledge-base/show')
            ->where('permissions.access_level', 'editor')
            ->where('permissions.can_update', true)
        );

    $this->actingAs($grantee)
        ->patch(route('knowledge-items.update', $item), [
            'title' => 'Updated by mixed grants editor',
            'content_markdown' => 'Editor grant wins',
            'category' => 'Engineering',
            'tags' => ['mixed-grants'],
        ])
        ->assertSessionHasNoErrors();

    expect($item->fresh()->title)->toBe('Updated by mixed grants editor');
});

test('shared editor can update owner item and related assets', function () {
    $owner = User::factory()->create();
    $editor = User::factory()->create();
    $unrelatedOwner = User::factory()->create();
    $item = KnowledgeTestFactory::createOwnedItem($owner, 'shared-editor-item');
    $unrelatedOwnerItem = KnowledgeTestFactory::createOwnedItem($unrelatedOwner, 'shared-editor-unrelated-owner-item');

    KnowledgeAccountAccess::query()->create([
        'owner_user_id' => $owner->id,
        'grantee_user_id' => $editor->id,
        'permission' => 'editor',
    ]);

    $codeExample = CodeExample::withoutEvents(fn () => CodeExample::query()->create([
        'knowledge_item_id' => $item->id,
        'sort_order' => 0,
        'language' => 'php',
        'code' => '<?php echo "before";',
    ]));

    $this->actingAs($editor)
        ->patch(route('knowledge-items.update', $item), [
            'title' => 'Edited by shared editor',
            'content_markdown' => '# Updated content',
            'category' => 'Engineering',
            'tags' => ['shared', 'editor'],
            'status' => 'draft',
        ])
        ->assertRedirect(route('knowledge-items.edit', [
            'knowledgeItem' => $item,
            'step' => 'details',
        ]));

    $this->actingAs($editor)
        ->patch(route('knowledge-items.update', $unrelatedOwnerItem), [
            'title' => 'Edited across all owners',
            'content_markdown' => '# Global editor access',
            'category' => 'Engineering',
            'tags' => ['global', 'editor'],
            'status' => 'draft',
        ])
        ->assertRedirect(route('knowledge-items.edit', [
            'knowledgeItem' => $unrelatedOwnerItem,
            'step' => 'details',
        ]));

    $this->actingAs($editor)
        ->get(route('knowledge-items.edit', $item))
        ->assertInertia(fn (Assert $page) => $page
            ->component('knowledge-items/form')
            ->where('permissions.access_level', 'editor')
            ->where('permissions.can_update', true)
            ->where('permissions.can_delete', false)
        );

    $this->actingAs($editor)
        ->patch(route('knowledge-items.code-examples.update', [$item, $codeExample]), [
            'language' => 'php',
            'code' => '<?php echo "after";',
        ])
        ->assertSessionHasNoErrors();

    expect($item->fresh()->title)->toBe('Edited by shared editor');
    expect($unrelatedOwnerItem->fresh()->title)->toBe('Edited across all owners');
    expect($codeExample->fresh()->code)->toContain('after');
});

test('code examples and resources auto-append sort order and preserve it on updates', function () {
    $owner = User::factory()->create();
    $item = KnowledgeTestFactory::createOwnedItem($owner, 'auto-sort-order-item');

    CodeExample::withoutEvents(fn () => CodeExample::query()->create([
        'knowledge_item_id' => $item->id,
        'sort_order' => 2,
        'language' => 'php',
        'code' => '<?php echo "existing";',
    ]));

    $existingResource = KnowledgeResource::withoutEvents(fn () => KnowledgeResource::query()->create([
        'knowledge_item_id' => $item->id,
        'sort_order' => 7,
        'type' => 'link',
        'url' => 'https://example.com/existing',
    ]));

    $this->actingAs($owner)
        ->post(route('knowledge-items.code-examples.store', $item), [
            'language' => 'php',
            'code' => '<?php echo "new";',
        ])
        ->assertSessionHasNoErrors();

    $newCodeExample = CodeExample::query()
        ->where('knowledge_item_id', $item->id)
        ->latest('id')
        ->firstOrFail();

    expect($newCodeExample->sort_order)->toBe(3);

    $this->actingAs($owner)
        ->patch(route('knowledge-items.code-examples.update', [$item, $newCodeExample]), [
            'language' => 'php',
            'code' => '<?php echo "updated";',
        ])
        ->assertSessionHasNoErrors();

    expect($newCodeExample->fresh()->sort_order)->toBe(3);

    $this->actingAs($owner)
        ->post(route('knowledge-items.resources.store', $item), [
            'type' => 'link',
            'url' => 'https://example.com/new',
        ])
        ->assertSessionHasNoErrors();

    $newResource = KnowledgeResource::query()
        ->where('knowledge_item_id', $item->id)
        ->latest('id')
        ->firstOrFail();

    expect($newResource->sort_order)->toBe(8);

    $this->actingAs($owner)
        ->patch(route('knowledge-items.resources.update', [$item, $newResource]), [
            'type' => 'link',
            'url' => 'https://example.com/updated',
        ])
        ->assertSessionHasNoErrors();

    expect($newResource->fresh()->sort_order)->toBe(8);
    expect($existingResource->fresh()->sort_order)->toBe(7);
});
