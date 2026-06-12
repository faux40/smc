<?php

namespace App\Providers;

use App\Auth\SafeEloquentUserProvider;
use App\Events\CompletionCreated;
use App\Listeners\NotifyCompletionRecorded;
use App\Models\Completion;
use App\Observers\CompletionObserver;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

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
        $this->registerAuthProvider();
        $this->registerEventListeners();
        $this->registerObservers();
    }

    /**
     * Phase 16.2: override the built-in "eloquent" provider driver so every
     * UUID-keyed guard tolerates a malformed identifier (e.g. a stale recaller
     * cookie) by degrading to "not authenticated" instead of a 500.
     * config/auth.php keeps `'driver' => 'eloquent'`.
     */
    protected function registerAuthProvider(): void
    {
        Auth::provider('eloquent', function ($app, array $config) {
            return new SafeEloquentUserProvider($app['hash'], $config['model']);
        });
    }

    /**
     * Phase 15.1: per-user notification listeners. Registered here
     * (rather than via an EventServiceProvider since Laravel 11
     * doesn't ship one) so the wiring lives next to other app-boot
     * concerns.
     */
    protected function registerEventListeners(): void
    {
        // AssignmentCreatedForYou is dispatched directly by
        // TrainingAssignmentService (J5) — no event indirection needed.
        Event::listen(CompletionCreated::class, NotifyCompletionRecorded::class);
    }

    protected function registerObservers(): void
    {
        Completion::observe(CompletionObserver::class);
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
            : null,
        );
    }
}
