<?php

use App\Models\KnowledgeAccountAccess;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('knowledge access settings page is displayed', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('settings.knowledge-access.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/knowledge-access')
            ->has('grants')
            ->has('receivedAccess')
        );
});

test('owner can grant update and revoke knowledge access', function () {
    $owner = User::factory()->create();
    $grantee = User::factory()->create();

    $this->actingAs($owner)
        ->post(route('settings.knowledge-access.store'), [
            'email' => $grantee->email,
            'permission' => 'viewer',
        ])
        ->assertRedirect(route('settings.knowledge-access.index'));

    $grant = KnowledgeAccountAccess::query()->first();

    expect($grant)->not->toBeNull();
    expect($grant->owner_user_id)->toBe($owner->id);
    expect($grant->grantee_user_id)->toBe($grantee->id);
    expect($grant->permission)->toBe('viewer');

    $this->actingAs($owner)
        ->patch(route('settings.knowledge-access.update', $grant), [
            'permission' => 'editor',
        ])
        ->assertRedirect(route('settings.knowledge-access.index'));

    expect($grant->fresh()->permission)->toBe('editor');

    $this->actingAs($owner)
        ->delete(route('settings.knowledge-access.destroy', $grant))
        ->assertRedirect(route('settings.knowledge-access.index'));

    expect(KnowledgeAccountAccess::query()->exists())->toBeFalse();
});

test('users cannot manage grants they do not own', function () {
    $owner = User::factory()->create();
    $grantee = User::factory()->create();
    $otherUser = User::factory()->create();

    $grant = KnowledgeAccountAccess::query()->create([
        'owner_user_id' => $owner->id,
        'grantee_user_id' => $grantee->id,
        'permission' => 'viewer',
    ]);

    $this->actingAs($otherUser)
        ->patch(route('settings.knowledge-access.update', $grant), [
            'permission' => 'editor',
        ])
        ->assertForbidden();

    $this->actingAs($otherUser)
        ->delete(route('settings.knowledge-access.destroy', $grant))
        ->assertForbidden();
});

test('cannot grant access to current user account', function () {
    $owner = User::factory()->create();

    $this->actingAs($owner)
        ->from(route('settings.knowledge-access.index'))
        ->post(route('settings.knowledge-access.store'), [
            'email' => $owner->email,
            'permission' => 'viewer',
        ])
        ->assertRedirect(route('settings.knowledge-access.index'))
        ->assertSessionHasErrors('email');
});
