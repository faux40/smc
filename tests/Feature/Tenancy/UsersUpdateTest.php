<?php

namespace Tests\Feature\Tenancy;

use App\Events\UserUpdated;
use App\Models\Organization;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class UsersUpdateTest extends TestCase
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

    public function test_admin_can_update_name_and_email(): void
    {
        $org = Organization::factory()->create();
        $admin = User::factory()->forOrganization($org)->withRole('Admin')->create();
        $target = User::factory()->forOrganization($org)->withRole('None')->create();

        $this->actingAs($admin)
            ->patch(route('users.update', $target), [
                'name' => 'Updated Name',
                'email' => 'updated@example.com',
                'role' => 'None',
                'status' => 'active',
            ])
            ->assertRedirect(route('users.index'));

        $target->refresh();
        $this->assertSame('Updated Name', $target->name);
        $this->assertSame('updated@example.com', $target->email);
    }

    public function test_admin_can_change_role(): void
    {
        $org = Organization::factory()->create();
        $admin = User::factory()->forOrganization($org)->withRole('Admin')->create();
        $target = User::factory()->forOrganization($org)->withRole('None')->create();

        $this->actingAs($admin)
            ->patch(route('users.update', $target), [
                'name' => $target->name,
                'role' => 'Manager',
                'status' => 'active',
            ])
            ->assertSessionHasNoErrors();

        $this->assertTrue($target->fresh()->hasRole('Manager'));
        $this->assertFalse($target->fresh()->hasRole('None'));
    }

    public function test_admin_cannot_update_owner(): void
    {
        $org = Organization::factory()->create();
        $owner = $this->ownerOf($org);
        $admin = User::factory()->forOrganization($org)->withRole('Admin')->create();

        $this->actingAs($admin)
            ->patch(route('users.update', $owner), [
                'name' => 'Hacked',
                'role' => 'None',
                'status' => 'active',
            ])
            ->assertForbidden();
    }

    public function test_role_cannot_be_owner_via_edit(): void
    {
        // Owner is reserved for the ownership-transfer flow (future phase).
        $org = Organization::factory()->create();
        $admin = User::factory()->forOrganization($org)->withRole('Admin')->create();
        $target = User::factory()->forOrganization($org)->withRole('None')->create();

        $this->actingAs($admin)
            ->from(route('users.index'))
            ->patch(route('users.update', $target), [
                'name' => $target->name,
                'role' => 'Owner',
                'status' => 'active',
            ])
            ->assertSessionHasErrors('role');
    }

    public function test_email_uniqueness_ignores_self(): void
    {
        $org = Organization::factory()->create();
        $admin = User::factory()->forOrganization($org)->withRole('Admin')->create();
        $target = User::factory()->forOrganization($org)->create(['email' => 'mine@example.com']);

        // Re-submitting the same email should NOT trip the unique rule.
        $this->actingAs($admin)
            ->patch(route('users.update', $target), [
                'name' => 'New Name',
                'email' => 'mine@example.com',
                'role' => 'None',
                'status' => 'active',
            ])
            ->assertSessionHasNoErrors();
    }

    public function test_email_uniqueness_blocks_collision(): void
    {
        $org = Organization::factory()->create();
        $admin = User::factory()->forOrganization($org)->withRole('Admin')->create();
        $target = User::factory()->forOrganization($org)->create(['email' => 'mine@example.com']);
        User::factory()->forOrganization($org)->create(['email' => 'taken@example.com']);

        $this->actingAs($admin)
            ->from(route('users.index'))
            ->patch(route('users.update', $target), [
                'name' => 'X',
                'email' => 'taken@example.com',
                'role' => 'None',
                'status' => 'active',
            ])
            ->assertSessionHasErrors('email');
    }

    public function test_update_dispatches_user_updated(): void
    {
        Event::fake([UserUpdated::class]);

        $org = Organization::factory()->create();
        $admin = User::factory()->forOrganization($org)->withRole('Admin')->create();
        $target = User::factory()->forOrganization($org)->withRole('None')->create();

        $this->actingAs($admin)
            ->patch(route('users.update', $target), [
                'name' => 'New',
                'role' => 'None',
                'status' => 'active',
            ]);

        Event::assertDispatched(UserUpdated::class);
    }

    public function test_manager_cannot_update_user(): void
    {
        $org = Organization::factory()->create();
        $manager = User::factory()->forOrganization($org)->withRole('Manager')->create();
        $target = User::factory()->forOrganization($org)->create();

        $this->actingAs($manager)
            ->patch(route('users.update', $target), [
                'name' => 'X',
                'role' => 'None',
                'status' => 'active',
            ])
            ->assertForbidden();
    }
}
