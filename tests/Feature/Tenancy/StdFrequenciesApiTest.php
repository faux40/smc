<?php

namespace Tests\Feature\Tenancy;

use App\Events\StdFrequencyCreated;
use App\Events\StdFrequencyDeleted;
use App\Events\StdFrequencyUpdated;
use App\Models\Organization;
use App\Models\StdFrequency;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class StdFrequenciesApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_anyone_in_org_can_list(): void
    {
        $org = Organization::factory()->create();
        $member = User::factory()->for($org, 'organization')->withRole('None')->create();
        StdFrequency::factory()->for($org, 'organization')->count(3)->create();

        $this->actingAs($member)
            ->getJson('/api/std-frequencies')
            ->assertOk()
            ->assertJsonCount(3);
    }

    public function test_list_is_org_scoped(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $member = User::factory()->for($orgA, 'organization')->create();
        StdFrequency::factory()->for($orgA, 'organization')->create();
        StdFrequency::factory()->for($orgB, 'organization')->count(2)->create();

        $this->actingAs($member)
            ->getJson('/api/std-frequencies')
            ->assertOk()
            ->assertJsonCount(1);
    }

    public function test_admin_can_create(): void
    {
        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();

        $this->actingAs($admin)
            ->postJson('/api/std-frequencies', ['name' => 'Annual', 'repeat_days' => 365])
            ->assertCreated();

        $this->assertDatabaseHas('std_frequencies', [
            'org_id' => $org->id,
            'name' => 'Annual',
            'repeat_days' => 365,
        ]);
    }

    public function test_manager_cannot_create(): void
    {
        $org = Organization::factory()->create();
        $manager = User::factory()->for($org, 'organization')->withRole('Manager')->create();

        $this->actingAs($manager)
            ->postJson('/api/std-frequencies', ['name' => 'X', 'repeat_days' => 30])
            ->assertForbidden();
    }

    public function test_create_validates(): void
    {
        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();

        $this->actingAs($admin)
            ->postJson('/api/std-frequencies', ['name' => '', 'repeat_days' => 0])
            ->assertStatus(422);

        $this->actingAs($admin)
            ->postJson('/api/std-frequencies', ['name' => 'X', 'repeat_days' => -1])
            ->assertStatus(422);
    }

    public function test_admin_can_update(): void
    {
        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();
        $freq = StdFrequency::factory()->for($org, 'organization')->create(['name' => 'Old', 'repeat_days' => 7]);

        $this->actingAs($admin)
            ->patchJson("/api/std-frequencies/{$freq->id}", ['name' => 'Renamed', 'repeat_days' => 14])
            ->assertOk();

        $freq->refresh();
        $this->assertSame('Renamed', $freq->name);
        $this->assertSame(14, $freq->repeat_days);
    }

    public function test_cross_org_update_blocked(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $adminA = User::factory()->for($orgA, 'organization')->withRole('Admin')->create();
        $freqB = StdFrequency::factory()->for($orgB, 'organization')->create();

        $this->actingAs($adminA)
            ->patchJson("/api/std-frequencies/{$freqB->id}", ['name' => 'hacked', 'repeat_days' => 1])
            ->assertNotFound();
    }

    public function test_admin_can_delete(): void
    {
        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();
        $freq = StdFrequency::factory()->for($org, 'organization')->create();

        $this->actingAs($admin)
            ->deleteJson("/api/std-frequencies/{$freq->id}")
            ->assertOk();

        $this->assertSoftDeleted('std_frequencies', ['id' => $freq->id]);
    }

    public function test_create_update_delete_broadcast(): void
    {
        Event::fake([StdFrequencyCreated::class, StdFrequencyUpdated::class, StdFrequencyDeleted::class]);

        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();

        $created = $this->actingAs($admin)
            ->postJson('/api/std-frequencies', ['name' => 'Annual', 'repeat_days' => 365])
            ->json();
        $this->actingAs($admin)->patchJson("/api/std-frequencies/{$created['id']}", ['name' => 'Yearly', 'repeat_days' => 365]);
        $this->actingAs($admin)->deleteJson("/api/std-frequencies/{$created['id']}");

        Event::assertDispatched(StdFrequencyCreated::class);
        Event::assertDispatched(StdFrequencyUpdated::class);
        Event::assertDispatched(StdFrequencyDeleted::class);
    }

    public function test_new_org_registration_seeds_default_frequencies(): void
    {
        $this->post(route('register'), [
            'f_name' => 'Ada',
            'l_name' => 'Lovelace',
            'org_name' => 'Acme',
            'email' => 'ada@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertRedirect();

        $org = Organization::where('name', 'Acme')->firstOrFail();
        $names = StdFrequency::query()
            ->withoutGlobalScope('organization')
            ->where('org_id', $org->id)
            ->pluck('name')
            ->all();

        $this->assertContains('Annual', $names);
        $this->assertContains('Semi-Annual', $names);
        $this->assertContains('Quarterly', $names);
        $this->assertContains('Monthly', $names);
        $this->assertContains('Every 10 days', $names);
    }
}
