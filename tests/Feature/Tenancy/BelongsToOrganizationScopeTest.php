<?php

namespace Tests\Feature\Tenancy;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Behavior tests for the `BelongsToOrganization` global scope, now that
 * User is the first tenant-scoped model.
 */
class BelongsToOrganizationScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_scope_filters_by_current_org_id_when_bound(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();

        User::factory()->create(['org_id' => $orgA->id]);
        User::factory()->create(['org_id' => $orgA->id]);
        User::factory()->create(['org_id' => $orgB->id]);

        app()->instance('currentOrgId', $orgA->id);

        $this->assertSame(2, User::query()->count());
    }

    public function test_scope_is_noop_when_current_org_id_unbound(): void
    {
        Organization::factory()->count(2)->create();
        User::factory()->count(3)->create();

        // Don't bind currentOrgId — auth resolution and site-admin tools rely
        // on the unscoped behavior in this case.
        $this->assertSame(3, User::query()->count());
    }

    public function test_scope_bypassable_via_without_global_scope(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();

        User::factory()->create(['org_id' => $orgA->id]);
        User::factory()->create(['org_id' => $orgB->id]);

        app()->instance('currentOrgId', $orgA->id);

        $this->assertSame(2, User::query()->withoutGlobalScope('organization')->count());
    }
}
