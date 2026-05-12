<?php

namespace App\Providers;

use App\Events\AssignmentCreated;
use App\Events\CompletionCreated;
use App\Listeners\NotifyAssignmentCreated;
use App\Listeners\NotifyCompletionRecorded;
use Carbon\CarbonImmutable;
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
        $this->registerEventListeners();
    }

    /**
     * Phase 15.1: per-user notification listeners. Registered here
     * (rather than via an EventServiceProvider since Laravel 11
     * doesn't ship one) so the wiring lives next to other app-boot
     * concerns.
     */
    protected function registerEventListeners(): void
    {
        Event::listen(AssignmentCreated::class, NotifyAssignmentCreated::class);
        Event::listen(CompletionCreated::class, NotifyCompletionRecorded::class);
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
