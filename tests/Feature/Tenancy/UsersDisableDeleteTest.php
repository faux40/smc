<?php

namespace Tests\Feature\Tenancy;

use App\Events\UserSoftDeleted;
use App\Events\UserStatusChanged;
use App\Models\Organization;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class UsersDisableDeleteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private function ownerOf(Organization $org): User
    {
        $owner = User::factory()->forOrganization($org)->create();
        $org->update(['owner_user_id' => $owner->id]);
        $owner->assignRole('Owner');

        return $owner;
    }

    public function test_admin_can_disable_user(): void
    {
        $org = Organization::factory()->create();
        $admin = User::factory()->forOrganization($org)->withRole('Admin')->create();
        $target = User::factory()->forOrganization($org)->create();

        $this->actingAs($admin)
            ->post(route('users.disable', $target))
            ->assertRedirect(route('users.index'));

        $this->assertSame('disabled', $target->fresh()->status);
    }

    public function test_admin_can_enable_user(): void
    {
        $org = Organization::factory()->create();
        $admin = User::factory()->forOrganization($org)->withRole('Admin')->create();
        $target = User::factory()->forOrganization($org)->disabled()->create();

        $this->actingAs($admin)
            ->post(route('users.enable', $target))
            ->assertRedirect(route('users.index'));

        $this->assertSame('active', $target->fresh()->status);
    }

    public function test_admin_cannot_disable_owner(): void
    {
        $org = Organization::factory()->create();
        $owner = $this->ownerOf($org);
        $admin = User::factory()->forOrganization($org)->withRole('Admin')->create();

        $this->actingAs($admin)
            ->post(route('users.disable', $owner))
            ->assertForbidden();
    }

    public function test_admin_cannot_disable_self_via_admin_route(): void
    {
        $org = Organization::factory()->create();
        $admin = User::factory()->forOrganization($org)->withRole('Admin')->create();

        $this->actingAs($admin)
            ->post(route('users.disable', $admin))
            ->assertForbidden();
    }

    public function test_admin_can_soft_delete_user(): void
    {
        $org = Organization::factory()->create();
        $admin = User::factory()->forOrganization($org)->withRole('Admin')->create();
        $target = User::factory()->forOrganization($org)->create();

        $this->actingAs($admin)
            ->delete(route('users.destroy', $target))
            ->assertRedirect(route('users.index'));

        $this->assertSoftDeleted('users', ['id' => $target->id]);
    }

    public function test_admin_cannot_delete_owner(): void
    {
        $org = Organization::factory()->create();
        $owner = $this->ownerOf($org);
        $admin = User::factory()->forOrganization($org)->withRole('Admin')->create();

        $this->actingAs($admin)
            ->delete(route('users.destroy', $owner))
            ->assertForbidden();

        $this->assertDatabaseHas('users', ['id' => $owner->id, 'deleted_at' => null]);
    }

    public function test_admin_cannot_delete_self_via_admin_route(): void
    {
        $org = Organization::factory()->create();
        $admin = User::factory()->forOrganization($org)->withRole('Admin')->create();

        $this->actingAs($admin)
            ->delete(route('users.destroy', $admin))
            ->assertForbidden();
    }

    public function test_disable_dispatches_user_status_changed(): void
    {
        Event::fake([UserStatusChanged::class]);

        $org = Organization::factory()->create();
        $admin = User::factory()->forOrganization($org)->withRole('Admin')->create();
        $target = User::factory()->forOrganization($org)->create();

        $this->actingAs($admin)->post(route('users.disable', $target));

        Event::assertDispatched(UserStatusChanged::class);
    }

    public function test_destroy_dispatches_user_soft_deleted(): void
    {
        Event::fake([UserSoftDeleted::class]);

        $org = Organization::factory()->create();
        $admin = User::factory()->forOrganization($org)->withRole('Admin')->create();
        $target = User::factory()->forOrganization($org)->create();

        $this->actingAs($admin)->delete(route('users.destroy', $target));

        Event::assertDispatched(UserSoftDeleted::class);
    }

    public function test_manager_cannot_disable_or_delete(): void
    {
        $org = Organization::factory()->create();
        $manager = User::factory()->forOrganization($org)->withRole('Manager')->create();
        $target = User::factory()->forOrganization($org)->create();

        $this->actingAs($manager)->post(route('users.disable', $target))->assertForbidden();
        $this->actingAs($manager)->delete(route('users.destroy', $target))->assertForbidden();
    }
}
