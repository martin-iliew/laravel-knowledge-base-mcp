<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class DemoLoginController extends Controller
{
    /**
     * Authenticate as a configured demo user in local/testing environments.
     */
    public function __invoke(Request $request, string $account): RedirectResponse
    {
        abort_unless(app()->environment(['local', 'testing']), 404);

        $entry = collect(config('knowledge.demo_accounts', []))
            ->first(fn ($candidate) => is_array($candidate) && ($candidate['key'] ?? null) === $account);

        $email = is_array($entry) ? ($entry['email'] ?? null) : null;

        abort_unless(is_string($email) && $email !== '', 404);

        $user = User::query()->where('email', $email)->firstOrFail();

        Auth::login($user, remember: true);
        $request->session()->regenerate();

        return to_route('dashboard');
    }
}
