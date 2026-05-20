<?php

namespace Tests\Feature\Resilience;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Phase 16.3 — DB constraint → 422 safety net.
 *
 * Forms validate uniqueness up front, but a race (two near-simultaneous
 * submits) can still slip past validation and hit a DB unique constraint.
 * For JSON callers that should surface as a user-correctable 422, not a 500.
 * Non-unique / non-JSON paths keep default handling.
 */
class DbConstraintTest extends TestCase
{
    use RefreshDatabase;

    private function registerDuplicateInsertRoute(string $uri): void
    {
        Route::get($uri, function () {
            // Two identical inserts; the second trips roles' unique
            // (name, guard_name) index → QueryException.
            foreach ([1, 2] as $_) {
                DB::table('roles')->insert([
                    'name' => 'dupe',
                    'guard_name' => 'web',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });
    }

    public function test_unique_violation_renders_422_for_json_callers(): void
    {
        $this->registerDuplicateInsertRoute('/__test/dup-json');

        $this->getJson('/__test/dup-json')
            ->assertStatus(422)
            ->assertJsonStructure(['message']);
    }

    public function test_unique_violation_keeps_default_500_for_non_json(): void
    {
        $this->registerDuplicateInsertRoute('/__test/dup-html');

        // Non-JSON callers fall through to default handling (no 422 rewrite).
        $this->get('/__test/dup-html')->assertStatus(500);
    }
}
