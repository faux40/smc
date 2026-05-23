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
                'f_name' => 'Updated',
                'l_name' => 'Name',
                'email' => 'updated@example.com',
                'role' => 'None',
                'status' => 'active',
            ])
            ->assertRedirect(route('users.index'));

        $target->refresh();
        $this->assertSame('Updated Name', $target->name);
        $this->assertSame('updated@example.com', $target->email);
    }

    public function test_admin_can_set_employee_number(): void
    {
        $org = Organization::factory()->create();
        $admin = User::factory()->forOrganization($org)->withRole('Admin')->create();
        $target = User::factory()->forOrganization($org)->withRole('None')->create();

        $this->actingAs($admin)
            ->patch(route('users.update', $target), [
                'f_name' => $target->f_name,
                'l_name' => $target->l_name,
                'role' => 'None',
                'status' => 'active',
                'employee_number' => 'WVSD-002',
            ])
            ->assertRedirect(route('users.index'));

        $this->assertSame('WVSD-002', $target->refresh()->employee_number);
    }

    public function test_admin_can_change_role(): void
    {
        $org = Organization::factory()->create();
        $admin = User::factory()->forOrganization($org)->withRole('Admin')->create();
        $target = User::factory()->forOrganization($org)->withRole('None')->create();

        $this->actingAs($admin)
            ->patch(route('users.update', $target), [
                'f_name' => $target->f_name,
                'l_name' => $target->l_name,
                'role' => 'Manager',
                'status' => 'active',
            ])
            ->assertSessionHasNoErrors();

        $this->assertTrue($target->fresh()->hasRole('Manager'));
        $this->assertFalse($target->fresh()->hasRole('None'));
    }

    /** @return array<string, mixed> */
    private function basePayload(User $target): array
    {
        return [
            'f_name' => $target->f_name,
            'l_name' => $target->l_name,
            'role' => 'None',
            'status' => 'active',
        ];
    }

    public function test_admin_can_update_profile_fields(): void
    {
        $org = Organization::factory()->create();
        $admin = User::factory()->forOrganization($org)->withRole('Admin')->create();
        $supervisor = User::factory()->forOrganization($org)->withRole('Manager')->create();
        $target = User::factory()->forOrganization($org)->withRole('None')->create();

        $this->actingAs($admin)
            ->patch(route('users.update', $target), array_merge($this->basePayload($target), [
                'department' => 'Field Ops',
                'location' => 'Yard 3',
                'job_title' => 'Operator',
                'supervisor_id' => $supervisor->id,
                'start_date' => '2026-01-15',
                'end_date' => null,
            ]))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('users.index'));

        $target->refresh();
        $this->assertSame('Field Ops', $target->department);
        $this->assertSame('Yard 3', $target->location);
        $this->assertSame('Operator', $target->job_title);
        $this->assertSame($supervisor->id, $target->supervisor_id);
        $this->assertSame('2026-01-15', $target->start_date->toDateString());
    }

    public function test_supervisor_must_be_in_the_same_org(): void
    {
        $org = Organization::factory()->create();
        $otherOrg = Organization::factory()->create();
        $admin = User::factory()->forOrganization($org)->withRole('Admin')->create();
        $target = User::factory()->forOrganization($org)->withRole('None')->create();
        $foreign = User::factory()->forOrganization($otherOrg)->create();

        $this->actingAs($admin)
            ->patch(route('users.update', $target), array_merge($this->basePayload($target), [
                'supervisor_id' => $foreign->id,
            ]))
            ->assertSessionHasErrors('supervisor_id');
    }

    public function test_user_cannot_be_their_own_supervisor(): void
    {
        $org = Organization::factory()->create();
        $admin = User::factory()->forOrganization($org)->withRole('Admin')->create();
        $target = User::factory()->forOrganization($org)->withRole('None')->create();

        $this->actingAs($admin)
            ->patch(route('users.update', $target), array_merge($this->basePayload($target), [
                'supervisor_id' => $target->id,
            ]))
            ->assertSessionHasErrors('supervisor_id');
    }

    public function test_end_date_cannot_precede_start_date(): void
    {
        $org = Organization::factory()->create();
        $admin = User::factory()->forOrganization($org)->withRole('Admin')->create();
        $target = User::factory()->forOrganization($org)->withRole('None')->create();

        $this->actingAs($admin)
            ->patch(route('users.update', $target), array_merge($this->basePayload($target), [
                'start_date' => '2026-06-01',
                'end_date' => '2026-01-01',
            ]))
            ->assertSessionHasErrors('end_date');
    }

    public function test_admin_cannot_update_owner(): void
    {
        $org = Organization::factory()->create();
        $owner = $this->ownerOf($org);
        $admin = User::factory()->forOrganization($org)->withRole('Admin')->create();

        $this->actingAs($admin)
            ->patch(route('users.update', $owner), [
                'f_name' => 'Hacked',
                'l_name' => 'Hacker',
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
                'f_name' => $target->f_name,
                'l_name' => $target->l_name,
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
                'f_name' => 'New',
                'l_name' => 'Name',
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
                'f_name' => 'X',
                'l_name' => 'Y',
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
                'f_name' => 'New',
                'l_name' => 'Name',
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
                'f_name' => 'X',
                'l_name' => 'Y',
                'role' => 'None',
                'status' => 'active',
            ])
            ->assertForbidden();
    }
}
