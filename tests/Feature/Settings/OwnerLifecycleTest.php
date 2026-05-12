<?php

namespace Tests\Feature\Settings;

use App\Events\OrganizationDeleted;
use App\Models\Organization;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class OwnerLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_owner_cannot_self_delete_account(): void
    {
        $org = Organization::factory()->create();
        $owner = User::factory()->forOrganization($org)->create();
        $org->update(['owner_user_id' => $owner->id]);
        $owner->assignRole('Owner');

        $this->actingAs($owner)
            ->delete(route('profile.destroy'), ['password' => 'password'])
            ->assertForbidden();

        $this->assertDatabaseHas('users', ['id' => $owner->id, 'deleted_at' => null]);
    }

    public function test_non_owner_can_self_delete_account(): void
    {
        $member = User::factory()->create();

        $this->actingAs($member)
            ->delete(route('profile.destroy'), ['password' => 'password'])
            ->assertRedirect();

        $this->assertSoftDeleted('users', ['id' => $member->id]);
    }

    public function test_owner_can_delete_organization_and_users_cascade(): void
    {
        $org = Organization::factory()->create();
        $owner = User::factory()->forOrganization($org)->create();
        $org->update(['owner_user_id' => $owner->id]);
        $owner->assignRole('Owner');
        $member = User::factory()->forOrganization($org)->create();

        $this->actingAs($owner)
            ->delete(route('organization.destroy'), ['password' => 'password'])
            ->assertRedirect();

        $this->assertSoftDeleted('organizations', ['id' => $org->id]);
        $this->assertSoftDeleted('users', ['id' => $owner->id]);
        $this->assertSoftDeleted('users', ['id' => $member->id]);
    }

    public function test_non_owner_cannot_delete_organization(): void
    {
        $org = Organization::factory()->create();
        $owner = User::factory()->forOrganization($org)->create();
        $org->update(['owner_user_id' => $owner->id]);
        $owner->assignRole('Owner');
        $admin = User::factory()->forOrganization($org)->create();
        $admin->assignRole('Admin');

        $this->actingAs($admin)
            ->delete(route('organization.destroy'), ['password' => 'password'])
            ->assertForbidden();

        $this->assertDatabaseHas('organizations', ['id' => $org->id, 'deleted_at' => null]);
    }

    public function test_org_delete_broadcasts_organization_deleted(): void
    {
        Event::fake([OrganizationDeleted::class]);

        $org = Organization::factory()->create();
        $owner = User::factory()->forOrganization($org)->create();
        $org->update(['owner_user_id' => $owner->id]);
        $owner->assignRole('Owner');

        $this->actingAs($owner)
            ->delete(route('organization.destroy'), ['password' => 'password']);

        Event::assertDispatched(OrganizationDeleted::class);
    }
}
