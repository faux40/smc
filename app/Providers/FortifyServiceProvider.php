<?php

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Laravel\Fortify\Features;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
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
        $this->configureActions();
        $this->configureViews();
        $this->configureRateLimiting();
        $this->configureGuestRouteThrottling();
    }

    /**
     * Configure Fortify actions.
     */
    private function configureActions(): void
    {
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::createUsersUsing(CreateNewUser::class);
    }

    /**
     * Configure Fortify views.
     */
    private function configureViews(): void
    {
        Fortify::loginView(fn (Request $request) => Inertia::render('auth/Login', [
            'canResetPassword' => Features::enabled(Features::resetPasswords()),
            'canRegister' => Features::enabled(Features::registration()),
            'status' => $request->session()->get('status'),
        ]));

        Fortify::resetPasswordView(fn (Request $request) => Inertia::render('auth/ResetPassword', [
            'email' => $request->email,
            'token' => $request->route('token'),
        ]));

        Fortify::requestPasswordResetLinkView(fn (Request $request) => Inertia::render('auth/ForgotPassword', [
            'status' => $request->session()->get('status'),
        ]));

        Fortify::verifyEmailView(fn (Request $request) => Inertia::render('auth/VerifyEmail', [
            'status' => $request->session()->get('status'),
        ]));

        Fortify::registerView(fn () => Inertia::render('auth/Register'));

        Fortify::twoFactorChallengeView(fn () => Inertia::render('auth/TwoFactorChallenge'));

        Fortify::confirmPasswordView(fn () => Inertia::render('auth/ConfirmPassword'));
    }

    /**
     * Configure rate limiting.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)->by($request->session()->get('login.id'));
        });

        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower($request->input(Fortify::username())).'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });

        // Guest endpoints below have no auth to protect them, so — unlike
        // login/two-factor — they're keyed on IP alone rather than
        // IP+identifier. Registration is scriptable mass org creation;
        // forgot/reset-password are floodable for email bombing and
        // enumeration timing. Keying by IP means an attacker can't dodge
        // the limiter by cycling through emails/tokens from one address,
        // which is the actual abuse case here.
        RateLimiter::for('register', function (Request $request) {
            return Limit::perMinute(5)->by($request->ip());
        });

        RateLimiter::for('forgot-password', function (Request $request) {
            return Limit::perMinute(6)->by($request->ip());
        });

        RateLimiter::for('reset-password', function (Request $request) {
            return Limit::perMinute(6)->by($request->ip());
        });
    }

    /**
     * Attach `throttle:` middleware to Fortify's registration and
     * password-reset routes.
     *
     * Unlike login/two-factor/verification/passkeys, Fortify's own routes
     * file (vendor/laravel/fortify/routes/routes.php) never consults
     * config('fortify.limiters.*') for the register/forgot-password/
     * reset-password routes — it registers them with no throttle
     * middleware at all, and there's no config knob to add one. Forking
     * the whole routes file just to insert three `throttle:` entries would
     * be far more invasive than the fix warrants, so instead we reach into
     * the already-registered routes and append the middleware ourselves.
     *
     * This runs in an `app()->booted()` callback so it executes after
     * Fortify's own boot() (where these routes are registered) has run —
     * package providers boot before app providers, so in practice this is
     * already true by the time our own boot() runs, but the callback is a
     * cheap safety net against that ordering assumption ever changing.
     *
     * We deliberately look up routes by iterating and comparing
     * `$route->getName()` rather than `RouteCollection::getByName()`.
     * `getByName()` reads a `$nameList` cache that Fortify's routes don't
     * populate until `refreshNameLookups()` runs (queued in a *second*,
     * later `booted()` callback registered by the framework's own routing
     * bootstrap) — which fires after this one, so `getByName()` would
     * still return null here even though the routes already exist and
     * already have their names set.
     */
    private function configureGuestRouteThrottling(): void
    {
        $this->app->booted(function () {
            $throttledRoutes = [
                'register.store' => 'register',
                'password.email' => 'forgot-password',
                'password.update' => 'reset-password',
            ];

            foreach (Route::getRoutes() as $route) {
                $limiter = $throttledRoutes[$route->getName()] ?? null;

                if ($limiter !== null) {
                    $route->middleware('throttle:'.$limiter);
                }
            }
        });
    }
}
