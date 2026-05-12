<?php

namespace Tests\Feature\Tenancy;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class UserSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_users_table_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('users', [
            'id', 'org_id', 'name', 'email', 'email_verified_at',
            'password', 'remember_token', 'status', 'deleted_at',
            'created_at', 'updated_at',
            'two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at',
        ]));
    }

    public function test_user_id_is_uuid(): void
    {
        $user = User::factory()->create();

        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
            $user->id,
        );
    }

    public function test_user_belongs_to_organization(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create(['org_id' => $org->id]);

        $this->assertSame($org->id, $user->organization->id);
    }

    public function test_user_factory_auto_creates_organization(): void
    {
        $user = User::factory()->create();

        $this->assertNotNull($user->org_id);
        $this->assertInstanceOf(Organization::class, $user->organization);
    }

    public function test_user_status_defaults_to_active(): void
    {
        $user = User::factory()->create();

        $this->assertSame('active', $user->fresh()->status);
    }

    public function test_user_supports_soft_delete(): void
    {
        $user = User::factory()->create();
        $user->delete();

        $this->assertSoftDeleted('users', ['id' => $user->id]);
    }

    public function test_no_login_user_can_have_null_email_and_null_password(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->noLogin()->create(['org_id' => $org->id]);

        $this->assertNull($user->email);
        $this->assertNull($user->password);
    }

    public function test_partial_unique_index_allows_recreate_after_soft_delete(): void
    {
        $org = Organization::factory()->create();
        $first = User::factory()->create(['org_id' => $org->id, 'email' => 'same@example.com']);
        $first->delete();

        // Should NOT throw — partial unique allows recreate while old row is soft-deleted.
        $second = User::factory()->create(['org_id' => $org->id, 'email' => 'same@example.com']);

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame('same@example.com', $second->email);
    }

    public function test_two_active_users_cannot_share_email(): void
    {
        $org = Organization::factory()->create();
        User::factory()->create(['org_id' => $org->id, 'email' => 'dupe@example.com']);

        $this->expectException(\Illuminate\Database\QueryException::class);
        User::factory()->create(['org_id' => $org->id, 'email' => 'dupe@example.com']);
    }
}
