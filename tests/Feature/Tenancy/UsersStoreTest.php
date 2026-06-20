<?php

namespace Tests\Feature\Tenancy;

use App\Events\UserRegistered;
use App\Models\Organization;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class UsersStoreTest extends TestCase
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

    public function test_admin_can_create_user_with_default_role_none(): void
    {
        $org = Organization::factory()->create();
        $admin = User::factory()->forOrganization($org)->withRole('Admin')->create();

        $this->actingAs($admin)
            ->post(route('users.store'), [
                'f_name' => 'Ada',
                'l_name' => 'Lovelace',
                'email' => 'ada@example.com',
            ])
            ->assertRedirect(route('users.index'));

        $created = User::where('email', 'ada@example.com')->first();
        $this->assertNotNull($created);
        $this->assertSame($org->id, $created->org_id);
        $this->assertSame('active', $created->status);
        $this->assertTrue($created->hasRole('None'));
    }

    public function test_admin_can_create_no_login_user(): void
    {
        $org = Organization::factory()->create();
        $admin = User::factory()->forOrganization($org)->withRole('Admin')->create();

        $this->actingAs($admin)
            ->post(route('users.store'), [
                'f_name' => 'Frank',
                'l_name' => 'Forklift',
            ])
            ->assertRedirect(route('users.index'));

        $created = User::where('f_name', 'Frank')->where('l_name', 'Forklift')->first();
        $this->assertNotNull($created);
        $this->assertNull($created->email);
        $this->assertNull($created->password);
    }

    public function test_manager_cannot_create_user(): void
    {
        $org = Organization::factory()->create();
        $manager = User::factory()->forOrganization($org)->withRole('Manager')->create();

        $this->actingAs($manager)
            ->post(route('users.store'), ['f_name' => 'No', 'l_name' => 'Way', 'email' => 'no@example.com'])
            ->assertForbidden();
    }

    public function test_email_must_be_globally_unique(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        User::factory()->forOrganization($orgA)->create(['email' => 'taken@example.com']);
        $admin = User::factory()->forOrganization($orgB)->withRole('Admin')->create();

        $this->actingAs($admin)
            ->from(route('users.index'))
            ->post(route('users.store'), ['f_name' => 'X', 'l_name' => 'Y', 'email' => 'taken@example.com'])
            ->assertSessionHasErrors('email');
    }

    public function test_name_is_required(): void
    {
        $org = Organization::factory()->create();
        $admin = User::factory()->forOrganization($org)->withRole('Admin')->create();

        $this->actingAs($admin)
            ->from(route('users.index'))
            ->post(route('users.store'), ['email' => 'a@b.com'])
            ->assertSessionHasErrors(['f_name', 'l_name']);
    }

    public function test_json_request_returns_the_created_user_row(): void
    {
        $org = Organization::factory()->create();
        $admin = User::factory()->forOrganization($org)->withRole('Admin')->create();
        $boss = User::factory()->forOrganization($org)
            ->create(['f_name' => 'Sam', 'm_name' => 'T', 'l_name' => 'Boss']);

        // expectsJson (postJson) → the endpoint returns the new user row (201)
        // instead of the Inertia redirect, so callers like the class roster can
        // create + enroll inline without navigating away.
        $response = $this->actingAs($admin)
            ->postJson(route('users.store'), [
                'f_name' => 'Ada',
                'm_name' => 'Augusta',
                'l_name' => 'Lovelace',
                'email' => 'ada@example.com',
                'supervisor_id' => $boss->id,
            ])
            ->assertCreated();

        $created = User::where('email', 'ada@example.com')->firstOrFail();

        $response->assertjson([
            'id' => $created->id,
            'name' => 'Ada Augusta Lovelace',
            'sort_name' => 'Lovelace, Ada Augusta',
            'email' => 'ada@example.com',
            'supervisor_id' => $boss->id,
            'supervisor_sort_name' => 'Boss, Sam T',
        ]);
    }

    public function test_create_dispatches_user_registered(): void
    {
        Event::fake([UserRegistered::class]);

        $org = Organization::factory()->create();
        $admin = User::factory()->forOrganization($org)->withRole('Admin')->create();

        $this->actingAs($admin)
            ->post(route('users.store'), ['f_name' => 'Ada', 'l_name' => 'L', 'email' => 'ada@example.com']);

        Event::assertDispatched(UserRegistered::class);
    }
}
