<?php

namespace Tests\Feature\Tenancy;

use App\Events\StdFrequencyCreated;
use App\Events\StdFrequencyDeleted;
use App\Events\StdFrequencyUpdated;
use App\Events\TrainingAssignmentCreated;
use App\Models\AssignmentSource;
use App\Models\Completion;
use App\Models\Organization;
use App\Models\Requirement;
use App\Models\RqmtElement;
use App\Models\StdFrequency;
use App\Models\Training;
use App\Models\TrainingAssignment;
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

    /**
     * J2 recalc-trigger scenario: one frequency drives two assignments — as
     * the template on userA's directly-assigned training, and through a
     * requirement element on userB's training (whose own template is 730d).
     * Both completed 2026-01-01, so both expire 2027-01-01 via the 365-day
     * frequency until it changes.
     *
     * @return array{admin: User, freq: StdFrequency, taDirect: TrainingAssignment, taViaReq: TrainingAssignment}
     */
    private function makeRecalcScenario(): array
    {
        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();
        $freq = StdFrequency::factory()->for($org, 'organization')->create(['repeat_days' => 365]);
        $freq730 = StdFrequency::factory()->for($org, 'organization')->create(['repeat_days' => 730]);

        // userA: direct assignment; training template uses $freq.
        $userA = User::factory()->for($org, 'organization')->create();
        $trainingA = Training::factory()->for($org, 'organization')->create([
            'repeating' => true,
            'std_freq_id' => $freq->id,
        ]);
        $taDirect = TrainingAssignment::factory()->create([
            'org_id' => $org->id,
            'user_id' => $userA->id,
            'training_id' => $trainingA->id,
        ]);
        AssignmentSource::create([
            'training_assignment_id' => $taDirect->id,
            'sourceable_type' => null,
            'sourceable_id' => null,
            'added_at' => now(),
        ]);

        // userB: requirement-sourced; the element uses $freq, template 730d.
        $userB = User::factory()->for($org, 'organization')->create();
        $trainingB = Training::factory()->for($org, 'organization')->create([
            'repeating' => true,
            'std_freq_id' => $freq730->id,
        ]);
        $req = Requirement::factory()->for($org, 'organization')->create();
        RqmtElement::factory()
            ->for($org, 'organization')->for($req, 'requirement')
            ->state([
                'module_type' => Training::class,
                'module_id' => $trainingB->id,
                'initial_only' => false,
                'repeating' => true,
                'std_freq_id' => $freq->id,
                'as_needed' => false,
            ])
            ->create();
        $taViaReq = TrainingAssignment::factory()->create([
            'org_id' => $org->id,
            'user_id' => $userB->id,
            'training_id' => $trainingB->id,
        ]);
        AssignmentSource::create([
            'training_assignment_id' => $taViaReq->id,
            'sourceable_type' => Requirement::class,
            'sourceable_id' => $req->id,
            'added_at' => now(),
        ]);

        foreach ([[$userA, $trainingA], [$userB, $trainingB]] as [$user, $training]) {
            Completion::factory()->create([
                'org_id' => $org->id,
                'user_id' => $user->id,
                'module_type' => Training::class,
                'module_id' => $training->id,
                'completion_date' => '2026-01-01',
                'expire_date' => null,
            ]);
        }

        $this->assertEquals('2027-01-01', $taDirect->refresh()->expires_at->toDateString());
        $this->assertEquals('2027-01-01', $taViaReq->refresh()->expires_at->toDateString());

        return compact('admin', 'freq', 'taDirect', 'taViaReq');
    }

    public function test_updating_repeat_days_recalculates_affected_assignments(): void
    {
        ['admin' => $admin, 'freq' => $freq, 'taDirect' => $taDirect, 'taViaReq' => $taViaReq]
            = $this->makeRecalcScenario();

        Event::fake([TrainingAssignmentCreated::class]);

        $this->actingAs($admin)
            ->patchJson("/api/std-frequencies/{$freq->id}", ['name' => $freq->name, 'repeat_days' => 30])
            ->assertOk();

        // Both the template-driven and element-driven assignments tighten.
        $this->assertEquals('2026-01-31', $taDirect->refresh()->expires_at->toDateString());
        $this->assertEquals('2026-01-31', $taViaReq->refresh()->expires_at->toDateString());
        Event::assertDispatched(
            TrainingAssignmentCreated::class,
            fn ($e) => $e->trainingAssignmentId === $taDirect->id,
        );
        Event::assertDispatched(
            TrainingAssignmentCreated::class,
            fn ($e) => $e->trainingAssignmentId === $taViaReq->id,
        );
    }

    public function test_deleting_frequency_recalculates_affected_assignments(): void
    {
        ['admin' => $admin, 'freq' => $freq, 'taDirect' => $taDirect] = $this->makeRecalcScenario();

        Event::fake([TrainingAssignmentCreated::class]);

        $this->actingAs($admin)
            ->deleteJson("/api/std-frequencies/{$freq->id}")
            ->assertOk();

        // Trashed frequency → no computable cycle → no expiry (J1 fallback).
        $this->assertNull($taDirect->refresh()->expires_at);
        Event::assertDispatched(
            TrainingAssignmentCreated::class,
            fn ($e) => $e->trainingAssignmentId === $taDirect->id,
        );
    }
}
