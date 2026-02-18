<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\StoreKnowledgeAccessGrantRequest;
use App\Http\Requests\Settings\UpdateKnowledgeAccessGrantRequest;
use App\Models\KnowledgeAccountAccess;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class KnowledgeAccessGrantController extends Controller
{
    /**
     * Display account-level knowledge access management.
     */
    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        return Inertia::render('settings/knowledge-access', [
            'grants' => $user->knowledgeAccessGrants()
                ->with('grantee:id,name,email')
                ->latest('id')
                ->get()
                ->map(function (KnowledgeAccountAccess $grant): array {
                    return [
                        'id' => $grant->id,
                        'permission' => $grant->permission,
                        'created_at' => $grant->created_at?->toIso8601String(),
                        'grantee' => [
                            'id' => $grant->grantee?->id,
                            'name' => $grant->grantee?->name,
                            'email' => $grant->grantee?->email,
                        ],
                    ];
                })
                ->values(),
            'receivedAccess' => $user->knowledgeAccessReceived()
                ->with('owner:id,name,email')
                ->latest('id')
                ->get()
                ->map(function (KnowledgeAccountAccess $grant): array {
                    return [
                        'id' => $grant->id,
                        'permission' => $grant->permission,
                        'created_at' => $grant->created_at?->toIso8601String(),
                        'owner' => [
                            'id' => $grant->owner?->id,
                            'name' => $grant->owner?->name,
                            'email' => $grant->owner?->email,
                        ],
                    ];
                })
                ->values(),
        ]);
    }

    /**
     * Grant access to another user by email.
     */
    public function store(StoreKnowledgeAccessGrantRequest $request): RedirectResponse
    {
        /** @var User $owner */
        $owner = $request->user();

        $validated = $request->validated();
        $grantee = User::query()->where('email', $validated['email'])->firstOrFail();

        $owner->knowledgeAccessGrants()->updateOrCreate(
            ['grantee_user_id' => $grantee->id],
            ['permission' => $validated['permission']]
        );

        return to_route('settings.knowledge-access.index')
            ->with('status', 'Knowledge access granted.');
    }

    /**
     * Update permission for an existing grant.
     */
    public function update(
        UpdateKnowledgeAccessGrantRequest $request,
        KnowledgeAccountAccess $knowledgeAccountAccess
    ): RedirectResponse {
        /** @var User $owner */
        $owner = $request->user();

        abort_unless(
            (int) $knowledgeAccountAccess->owner_user_id === (int) $owner->id,
            403
        );

        $knowledgeAccountAccess->update([
            'permission' => $request->validated('permission'),
        ]);

        return to_route('settings.knowledge-access.index')
            ->with('status', 'Knowledge access updated.');
    }

    /**
     * Revoke an existing grant.
     */
    public function destroy(Request $request, KnowledgeAccountAccess $knowledgeAccountAccess): RedirectResponse
    {
        /** @var User $owner */
        $owner = $request->user();

        abort_unless(
            (int) $knowledgeAccountAccess->owner_user_id === (int) $owner->id,
            403
        );

        $knowledgeAccountAccess->delete();

        return to_route('settings.knowledge-access.index')
            ->with('status', 'Knowledge access revoked.');
    }
}
