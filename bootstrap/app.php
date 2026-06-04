<?php

use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetCurrentOrgId;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        // Browsers POST CSP violation reports with no CSRF token.
        $middleware->validateCsrfTokens(except: ['api/csp-report']);

        $middleware->web(append: [
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
            SetCurrentOrgId::class,
            SecurityHeaders::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // A unique-constraint violation that slips past form validation (e.g.
        // a double-submit race) is user-correctable, not a server fault. For
        // JSON/API callers, surface it as a 422 the UI can show instead of a
        // 500. HTML/Inertia requests keep default handling.
        $exceptions->render(function (UniqueConstraintViolationException $e, Request $request) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'That value conflicts with an existing record — it may already be in use.',
                ], 422);
            }

            return null;
        });
    })->create();
