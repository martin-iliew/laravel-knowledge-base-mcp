<?php

namespace App\Http\Controllers;

use App\Models\KnowledgeItem;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /**
     * Display account-level dashboard metrics for the knowledge base.
     */
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', KnowledgeItem::class);

        /** @var User $user */
        $user = $request->user();

        $accessibleItems = KnowledgeItem::query()->accessibleTo($user);

        $statusCounts = (clone $accessibleItems)
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $recentItems = (clone $accessibleItems)
            ->latest('updated_at')
            ->limit(6)
            ->get(['id', 'title', 'status', 'updated_at', 'category']);

        return Inertia::render('dashboard', [
            'summary' => [
                'total_items' => (clone $accessibleItems)->count(),
                'draft_items' => (int) ($statusCounts['draft'] ?? 0),
                'published_items' => (int) ($statusCounts['published'] ?? 0),
                'archived_items' => (int) ($statusCounts['archived'] ?? 0),
            ],
            'recentItems' => $recentItems->map(function (KnowledgeItem $item): array {
                return [
                    'id' => $item->id,
                    'title' => $item->title,
                    'status' => $item->status,
                    'category' => $item->category,
                    'updated_at' => $item->updated_at?->toIso8601String(),
                ];
            })->values(),
            'account' => [
                'user_name' => $user->name,
                'user_email' => $user->email,
                'app_name' => (string) config('app.name'),
            ],
        ]);
    }
}
