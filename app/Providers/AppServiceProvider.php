<?php

namespace App\Providers;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

use App\Models\CodeExample;
use App\Models\KnowledgeItem;
use App\Models\KnowledgeResource;
use App\Models\User;

use App\Observers\CodeExampleObserver;
use App\Observers\KnowledgeItemObserver;
use App\Observers\KnowledgeResourceObserver;
use Illuminate\Database\Eloquent\Relations\Relation;


class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        Relation::enforceMorphMap([
            'user' => User::class,
            'code' => CodeExample::class,
            'resource' => KnowledgeResource::class,
        ]);

        KnowledgeItem::observe(KnowledgeItemObserver::class);
        CodeExample::observe(CodeExampleObserver::class);
        KnowledgeResource::observe(KnowledgeResourceObserver::class);
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null
        );
    }
}
