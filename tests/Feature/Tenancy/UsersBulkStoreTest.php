<?php

namespace Tests\Feature\Tenancy;

use App\Events\UserRegistered;
use App\Models\Organization;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Bulk user creation (BULK USER ADD). Contract:
 *   POST /users/bulk  { users: [ {row}, ... ] }
 * Per-row best-effort: valid rows are created, invalid rows are skipped
 * (never block the batch); response reports per-row status + errors. Role
 * is settable per row (Admin+, never Owner); email is unique globally AND
 * within the batch. Admin+ only.
 */
class UsersBulkStoreTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private function admin(Organization $org): User
    {
        return User::factory()->forOrganization($org)->withRole('Admin')->create();
    }

    public function test_admin_bulk_creates_multiple_users_with_per_row_roles(): void
    {
        $org = Organization::factory()->create();
        $admin = $this->admin($org);

        $res = $this->actingAs($admin)->postJson(route('users.bulk'), [
            'users' => [
                ['f_name' => 'Ada', 'l_name' => 'Lovelace', 'email' => 'ada@example.com', 'role' => 'Manager'],
                ['f_name' => 'Grace', 'l_name' => 'Hopper'], // no email (no-login), default role
            ],
        ])->assertOk();

        $res->assertJson(['created' => 2, 'skipped' => 0]);

        $ada = User::where('email', 'ada@example.com')->first();
        $this->assertNotNull($ada);
        $this->assertSame($org->id, $ada->org_id);
        $this->assertSame('active', $ada->status);
        $this->assertTrue($ada->hasRole('Manager'));

        $grace = User::where('f_name', 'Grace')->where('l_name', 'Hopper')->first();
        $this->assertNotNull($grace);
        $this->assertNull($grace->email);
        $this->assertNull($grace->password);
        $this->assertTrue($grace->hasRole('None')); // default
    }

    public function test_best_effort_skips_invalid_rows_and_creates_the_rest(): void
    {
        $org = Organization::factory()->create();
        $admin = $this->admin($org);
        User::factory()->forOrganization($org)->create(['email' => 'taken@example.com']);

        $res = $this->actingAs($admin)->postJson(route('users.bulk'), [
            'users' => [
                ['f_name' => 'Good', 'l_name' => 'One', 'email' => 'good@example.com'],
                ['f_name' => 'Dup', 'l_name' => 'Email', 'email' => 'taken@example.com'], // global dup
                ['l_name' => 'NoFirst'],                                                  // missing f_name
            ],
        ])->assertOk();

        $res->assertJson(['created' => 1, 'skipped' => 2]);
        $this->assertDatabaseHas('users', ['email' => 'good@example.com']);
        $this->assertDatabaseMissing('users', ['email' => 'taken@example.com', 'f_name' => 'Dup']);

        // Per-row results carry index + status + field errors for the skips.
        $results = collect($res->json('results'));
        $this->assertSame('created', $results->firstWhere('index', 0)['status']);
        $this->assertSame('skipped', $results->firstWhere('index', 1)['status']);
        $this->assertArrayHasKey('email', $results->firstWhere('index', 1)['errors']);
        $this->assertSame('skipped', $results->firstWhere('index', 2)['status']);
        $this->assertArrayHasKey('f_name', $results->firstWhere('index', 2)['errors']);
    }

    public function test_within_batch_duplicate_email_skips_the_second(): void
    {
        $org = Organization::factory()->create();
        $admin = $this->admin($org);

        $res = $this->actingAs($admin)->postJson(route('users.bulk'), [
            'users' => [
                ['f_name' => 'First', 'l_name' => 'Wins', 'email' => 'dup@example.com'],
                ['f_name' => 'Second', 'l_name' => 'Loses', 'email' => 'dup@example.com'],
            ],
        ])->assertOk();

        $res->assertJson(['created' => 1, 'skipped' => 1]);
        $this->assertSame(1, User::where('email', 'dup@example.com')->count());
    }

    public function test_owner_role_is_rejected_per_row(): void
    {
        $org = Organization::factory()->create();
        $admin = $this->admin($org);

        $res = $this->actingAs($admin)->postJson(route('users.bulk'), [
            'users' => [
                ['f_name' => 'Sneaky', 'l_name' => 'Owner', 'email' => 'sneaky@example.com', 'role' => 'Owner'],
            ],
        ])->assertOk();

        $res->assertJson(['created' => 0, 'skipped' => 1]);
        $this->assertArrayHasKey('role', collect($res->json('results'))->firstWhere('index', 0)['errors']);
        $this->assertDatabaseMissing('users', ['email' => 'sneaky@example.com']);
    }

    public function test_supervisor_must_be_same_org(): void
    {
        $org = Organization::factory()->create();
        $otherOrg = Organization::factory()->create();
        $admin = $this->admin($org);
        $foreignSup = User::factory()->forOrganization($otherOrg)->create();

        $res = $this->actingAs($admin)->postJson(route('users.bulk'), [
            'users' => [
                ['f_name' => 'Has', 'l_name' => 'ForeignSup', 'email' => 'fs@example.com', 'supervisor_id' => $foreignSup->id],
            ],
        ])->assertOk();

        $res->assertJson(['created' => 0, 'skipped' => 1]);
        $this->assertArrayHasKey('supervisor_id', collect($res->json('results'))->firstWhere('index', 0)['errors']);
    }

    public function test_manager_cannot_bulk_create(): void
    {
        $org = Organization::factory()->create();
        $manager = User::factory()->forOrganization($org)->withRole('Manager')->create();

        $this->actingAs($manager)->postJson(route('users.bulk'), [
            'users' => [['f_name' => 'No', 'l_name' => 'Way', 'email' => 'no@example.com']],
        ])->assertForbidden();
    }

    public function test_empty_batch_is_unprocessable(): void
    {
        $org = Organization::factory()->create();
        $admin = $this->admin($org);

        $this->actingAs($admin)->postJson(route('users.bulk'), ['users' => []])
            ->assertUnprocessable()->assertJsonValidationErrors('users');
    }

    public function test_dispatches_user_registered_per_created_user(): void
    {
        Event::fake([UserRegistered::class]);
        $org = Organization::factory()->create();
        $admin = $this->admin($org);

        $this->actingAs($admin)->postJson(route('users.bulk'), [
            'users' => [
                ['f_name' => 'A', 'l_name' => 'One', 'email' => 'a1@example.com'],
                ['f_name' => 'B', 'l_name' => 'Two', 'email' => 'b2@example.com'],
            ],
        ])->assertOk();

        Event::assertDispatched(UserRegistered::class, 2);
    }
}
