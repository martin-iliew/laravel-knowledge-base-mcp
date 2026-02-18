<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('mcp token settings page is displayed', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('settings.mcp-token.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/mcp-token')
            ->has('tokens')
        );
});

test('user can generate mcp token', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->post(route('settings.mcp-token.store'), [
            'name' => 'Claude Code',
        ]);

    $response->assertRedirect(route('settings.mcp-token.index'));
    $response->assertSessionHas('plain_text_token');

    $token = $user->fresh()->tokens()->first();

    expect($token)->not->toBeNull();
    expect($token->name)->toBe('Claude Code');
    expect($token->abilities)->toContain('mcp');
});

test('user can revoke only their own token', function () {
    $owner = User::factory()->create();
    $otherUser = User::factory()->create();

    $token = $owner->createToken('Claude Code', ['mcp']);
    $tokenId = $owner->tokens()->first()->id;

    $this->actingAs($otherUser)
        ->delete(route('settings.mcp-token.destroy', $tokenId))
        ->assertForbidden();

    expect($owner->fresh()->tokens()->whereKey($tokenId)->exists())->toBeTrue();

    $this->actingAs($owner)
        ->delete(route('settings.mcp-token.destroy', $tokenId))
        ->assertRedirect(route('settings.mcp-token.index'));

    expect($owner->fresh()->tokens()->whereKey($tokenId)->exists())->toBeFalse();
});
