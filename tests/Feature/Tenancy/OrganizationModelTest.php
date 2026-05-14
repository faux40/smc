<?php

namespace Tests\Feature\Tenancy;

use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class OrganizationModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_organizations_table_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('organizations', [
            'id', 'owner_user_id', 'name', 'timezone', 'manager_digest_sent_at',
            'created_at', 'updated_at', 'deleted_at',
        ]));
    }

    public function test_timezone_defaults_to_utc(): void
    {
        // Phase 15.6 — the digest command relies on this column always
        // resolving to a valid IANA identifier.
        $org = Organization::factory()->create();

        $this->assertSame('UTC', $org->fresh()->timezone);
    }

    public function test_organizations_id_is_uuid(): void
    {
        $org = Organization::factory()->create();

        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
            $org->id,
        );
    }

    public function test_organization_factory_can_create(): void
    {
        $org = Organization::factory()->create(['name' => 'Acme Co']);

        $this->assertSame('Acme Co', $org->fresh()->name);
    }

    public function test_organization_supports_soft_delete(): void
    {
        $org = Organization::factory()->create();
        $org->delete();

        $this->assertSoftDeleted('organizations', ['id' => $org->id]);
        $this->assertNotNull(Organization::withTrashed()->find($org->id));
    }
}
