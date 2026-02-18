<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\StoreMcpTokenRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Sanctum\PersonalAccessToken;

class McpTokenController extends Controller
{
    /**
     * Display token management for the authenticated user.
     */
    public function index(Request $request): Response
    {
        $tokens = $request->user()
            ->tokens()
            ->latest('id')
            ->get()
            ->map(function (PersonalAccessToken $token): array {
                return [
                    'id' => $token->id,
                    'name' => $token->name,
                    'created_at' => $token->created_at?->toIso8601String(),
                    'last_used_at' => $token->last_used_at?->toIso8601String(),
                ];
            })
            ->values();

        return Inertia::render('settings/mcp-token', [
            'tokens' => $tokens,
            'plainTextToken' => session('plain_text_token'),
            'createdTokenName' => session('created_token_name'),
        ]);
    }

    /**
     * Create a new MCP token and flash the plain text value once.
     */
    public function store(StoreMcpTokenRequest $request): RedirectResponse
    {
        $tokenName = trim((string) ($request->validated('name') ?? ''));
        if ($tokenName === '') {
            $tokenName = 'Claude Code';
        }

        $token = $request->user()->createToken($tokenName, ['mcp']);

        return to_route('settings.mcp-token.index')
            ->with('plain_text_token', $token->plainTextToken)
            ->with('created_token_name', $tokenName)
            ->with('status', 'MCP token generated.');
    }

    /**
     * Revoke an existing token owned by the authenticated user.
     */
    public function destroy(Request $request, PersonalAccessToken $token): RedirectResponse
    {
        $currentUser = $request->user();

        abort_unless(
            (string) $token->tokenable_type === $currentUser::class
            && (int) $token->tokenable_id === (int) $currentUser->id,
            403
        );

        $token->delete();

        return to_route('settings.mcp-token.index')
            ->with('status', 'Token revoked.');
    }
}
