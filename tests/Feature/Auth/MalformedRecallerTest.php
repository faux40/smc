<?php

namespace Tests\Feature\Auth;

use App\Auth\SafeEloquentUserProvider;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 16.2 — graceful auth failure.
 *
 * Users have UUID primary keys. A stale/tampered "remember me" recaller
 * cookie can carry a non-UUID identifier; the default EloquentUserProvider
 * passes it straight into `where id = ?` and Postgres rejects it (SQLSTATE
 * 22P02), turning an otherwise-anonymous request into a 500. The web guard
 * must use SafeEloquentUserProvider, which treats a malformed identifier as
 * "not remembered" (returns null) → the login page, not a crash.
 */
class MalformedRecallerTest extends TestCase
{
    use RefreshDatabase;

    public function test_web_guard_uses_the_safe_provider(): void
    {
        $this->assertInstanceOf(
            SafeEloquentUserProvider::class,
            Auth::guard('web')->getProvider(),
        );
    }

    public function test_retrieve_by_id_with_malformed_uuid_does_not_hit_the_database(): void
    {
        // Asserting "no query issued" rather than "returns null": the test DB
        // is SQLite, which tolerates a non-UUID in `where id = ?` and returns
        // null anyway. The real failure is Postgres rejecting the cast (22P02
        // → 500), so the contract is that a malformed id never reaches the DB.
        $provider = Auth::guard('web')->getProvider();
        DB::flushQueryLog();
        DB::enableQueryLog();

        $result = $provider->retrieveById('not-a-valid-uuid');

        $this->assertNull($result);
        $this->assertCount(
            0,
            DB::getQueryLog(),
            'Malformed identifier must short-circuit before any query.',
        );
    }

    public function test_retrieve_by_token_with_malformed_uuid_does_not_hit_the_database(): void
    {
        $provider = Auth::guard('web')->getProvider();
        DB::flushQueryLog();
        DB::enableQueryLog();

        $result = $provider->retrieveByToken('not-a-valid-uuid', 'some-token');

        $this->assertNull($result);
        $this->assertCount(
            0,
            DB::getQueryLog(),
            'Malformed identifier must short-circuit before any query.',
        );
    }

    public function test_retrieve_by_id_still_resolves_a_valid_user(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->for($org, 'organization')->create();

        $provider = Auth::guard('web')->getProvider();
        $resolved = $provider->retrieveById($user->id);

        $this->assertNotNull($resolved);
        $this->assertSame($user->id, $resolved->getAuthIdentifier());
    }
}
