<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Knowledge\CodeExampleController;
use App\Http\Controllers\Knowledge\KnowledgeBaseController;
use App\Http\Controllers\Knowledge\KnowledgeItemController;
use App\Http\Controllers\Knowledge\KnowledgeResourceController;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Laravel\Fortify\Features;

Route::get('/', function () {
    return Inertia::render('welcome', [
        'canRegister' => Features::enabled(Features::registration()),
    ]);
})->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');

    Route::get('knowledge-base', [KnowledgeBaseController::class, 'index'])->name('knowledge-base.index');
    Route::get('knowledge-base/{knowledgeItem}', [KnowledgeBaseController::class, 'show'])->name('knowledge-base.show');

    Route::get('knowledge/search', function (Request $request): RedirectResponse {
        return to_route('knowledge-base.index', $request->query());
    })->name('knowledge-search.index');

    Route::post('knowledge-items/markdown-preview', [KnowledgeItemController::class, 'previewMarkdown'])
        ->name('knowledge-items.markdown-preview');

    Route::get('knowledge-items/create', [KnowledgeItemController::class, 'create'])->name('knowledge-items.create');
    Route::post('knowledge-items', [KnowledgeItemController::class, 'store'])->name('knowledge-items.store');
    Route::get('knowledge-items/{knowledgeItem}/edit', [KnowledgeItemController::class, 'edit'])->name('knowledge-items.edit');
    Route::patch('knowledge-items/{knowledgeItem}', [KnowledgeItemController::class, 'update'])->name('knowledge-items.update');
    Route::delete('knowledge-items/{knowledgeItem}', [KnowledgeItemController::class, 'destroy'])->name('knowledge-items.destroy');

    Route::post('knowledge-items/{knowledgeItem}/code-examples', [CodeExampleController::class, 'store'])
        ->name('knowledge-items.code-examples.store');
    Route::patch('knowledge-items/{knowledgeItem}/code-examples/{codeExample}', [CodeExampleController::class, 'update'])
        ->name('knowledge-items.code-examples.update');
    Route::delete('knowledge-items/{knowledgeItem}/code-examples/{codeExample}', [CodeExampleController::class, 'destroy'])
        ->name('knowledge-items.code-examples.destroy');

    Route::post('knowledge-items/{knowledgeItem}/resources', [KnowledgeResourceController::class, 'store'])
        ->name('knowledge-items.resources.store');
    Route::patch('knowledge-items/{knowledgeItem}/resources/{knowledgeResource}', [KnowledgeResourceController::class, 'update'])
        ->name('knowledge-items.resources.update');
    Route::delete('knowledge-items/{knowledgeItem}/resources/{knowledgeResource}', [KnowledgeResourceController::class, 'destroy'])
        ->name('knowledge-items.resources.destroy');
});

require __DIR__.'/settings.php';
