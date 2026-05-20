<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Fortify\Features;

abstract class TestCase extends BaseTestCase
{
    /**
     * Sync $_SERVER from $_ENV + hard-guard against running tests against
     * a non-sqlite DB.
     *
     * vlucas/phpdotenv's default adapter order is
     *   ServerConst -> EnvConst -> Putenv -> Apache
     * and Laravel's `env()` walks them in that order, returning the
     * first non-null. PHPUnit's <env force="true"/> writes the test
     * values to $_ENV + putenv() but NOT $_SERVER. Meanwhile docker's
     * env_file mechanism pre-populates $_SERVER with .env's values at
     * container boot. Result: ServerConst returns the .env value,
     * EnvConst's test value is never consulted, and migrate:fresh runs
     * against the real DB. Wiped twice (2026-05-18).
     *
     * Forcing $_SERVER to mirror $_ENV here closes the gap; the guard
     * below is the last fuse if anything else slips.
     */
    protected function setUp(): void
    {
        foreach ($_ENV as $name => $value) {
            if (is_string($value)) {
                $_SERVER[$name] = $value;
            }
        }

        // Boot the app (same as parent::setUp would) so config() works,
        // but BEFORE setUpTraits() — which is what calls
        // RefreshDatabase::refreshDatabase() → migrate:fresh.
        if (! $this->app) {
            $this->refreshApplication();
        }

        $driver = config('database.default');
        $name = config('database.connections.'.$driver.'.database');

        if ($driver !== 'sqlite' || $name !== ':memory:') {
            fwrite(
                STDERR,
                "\n\n*** TEST SAFETY GUARD ***\n"
                ."DB resolved to driver={$driver} name={$name}, not sqlite/:memory:.\n"
                ."Refusing to run — RefreshDatabase would migrate:fresh against this DB.\n"
                ."Check phpunit.xml <env force=\"true\"/> entries and tests/TestCase.php sync.\n\n"
            );
            exit(1);
        }

        parent::setUp();
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}
