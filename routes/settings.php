<?php

use App\Http\Controllers\Settings\KnowledgeAccessGrantController;
use App\Http\Controllers\Settings\McpTokenController;
use App\Http\Controllers\Settings\PasswordController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\TwoFactorAuthenticationController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', '/settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('settings/password', [PasswordController::class, 'edit'])->name('user-password.edit');

    Route::put('settings/password', [PasswordController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('user-password.update');

    Route::get('settings/mcp-token', [McpTokenController::class, 'index'])
        ->name('settings.mcp-token.index');
    Route::post('settings/mcp-token', [McpTokenController::class, 'store'])
        ->name('settings.mcp-token.store');
    Route::delete('settings/mcp-token/{token}', [McpTokenController::class, 'destroy'])
        ->name('settings.mcp-token.destroy');

    Route::get('settings/knowledge-access', [KnowledgeAccessGrantController::class, 'index'])
        ->name('settings.knowledge-access.index');
    Route::post('settings/knowledge-access', [KnowledgeAccessGrantController::class, 'store'])
        ->name('settings.knowledge-access.store');
    Route::patch('settings/knowledge-access/{knowledgeAccountAccess}', [KnowledgeAccessGrantController::class, 'update'])
        ->name('settings.knowledge-access.update');
    Route::delete('settings/knowledge-access/{knowledgeAccountAccess}', [KnowledgeAccessGrantController::class, 'destroy'])
        ->name('settings.knowledge-access.destroy');

    Route::get('settings/appearance', function () {
        return Inertia::render('settings/appearance');
    })->name('appearance.edit');

    Route::get('settings/two-factor', [TwoFactorAuthenticationController::class, 'show'])
        ->name('two-factor.show');
});
