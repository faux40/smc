<?php

namespace Tests\Feature\Tenancy;

use App\Models\AssignmentSource;
use App\Models\Completion;
use App\Models\Organization;
use App\Models\Requirement;
use App\Models\Training;
use App\Models\TrainingAssignment;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * J3 — GET /api/users/{user}/training-compliance: the TA-engine replacement
 * for the legacy per-user compliance payload. Groups are mutually exclusive
 * and complete; rows carry name/status/dates/source chips; the user's full
 * completion history rides along.
 */
class UserTrainingComplianceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    /**
     * @return array{org: Organization, manager: User, subject: User, tas: array<string, TrainingAssignment>, req: Requirement}
     */
    private function makeScenario(): array
    {
        $org = Organization::factory()->create();
        $manager = User::factory()->for($org, 'organization')->withRole('Manager')->create();
        $subject = User::factory()->for($org, 'organization')->create();
        $req = Requirement::factory()->for($org, 'organization')->create(['name' => 'OSHA General']);

        $make = function (string $name, array $attrs) use ($org, $subject): TrainingAssignment {
            $training = Training::factory()->for($org, 'organization')->create(['name' => $name]);

            return TrainingAssignment::factory()->create(array_merge([
                'org_id' => $org->id,
                'user_id' => $subject->id,
                'training_id' => $training->id,
                'name' => $name,
            ], $attrs));
        };

        $tas = [
            'overdue' => $make('Fall Protection', [
                'last_completed_at' => now()->subYear(),
                'expires_at' => now()->subDays(10),
            ]),
            'due_soon' => $make('Forklift', [
                'last_completed_at' => now()->subMonths(11),
                'expires_at' => now()->addDays(10),
            ]),
            'current' => $make('First Aid', [
                'last_completed_at' => now()->subMonth(),
                'expires_at' => now()->addDays(200),
            ]),
            'not_started' => $make('Confined Space', [
                'last_completed_at' => null,
                'expires_at' => null,
            ]),
            'as_needed' => $make('Respirator Fit', [
                'last_completed_at' => null,
                'expires_at' => null,
                'as_needed_only' => true,
            ]),
        ];

        // Chips: overdue TA is direct; due_soon TA comes from the requirement.
        AssignmentSource::create([
            'training_assignment_id' => $tas['overdue']->id,
            'sourceable_type' => null,
            'sourceable_id' => null,
            'added_at' => now(),
        ]);
        AssignmentSource::create([
            'training_assignment_id' => $tas['due_soon']->id,
            'sourceable_type' => Requirement::class,
            'sourceable_id' => $req->id,
            'added_at' => now(),
        ]);

        return compact('org', 'manager', 'subject', 'tas', 'req');
    }

    public function test_groups_are_mutually_exclusive_and_complete(): void
    {
        ['manager' => $manager, 'subject' => $subject, 'tas' => $tas] = $this->makeScenario();

        $groups = $this->actingAs($manager)
            ->getJson("/api/users/{$subject->id}/training-compliance")
            ->assertOk()
            ->json('groups');

        foreach (['overdue', 'due_soon', 'current', 'not_started', 'as_needed'] as $bucket) {
            $this->assertArrayHasKey($bucket, $groups, "missing bucket {$bucket}");
            $ids = array_column($groups[$bucket], 'id');
            $this->assertSame([$tas[$bucket]->id], $ids, "bucket {$bucket} mismatch");
        }

        // Every assigned training appears exactly once across all buckets.
        $all = array_merge(...array_values($groups));
        $this->assertCount(count($tas), $all);
    }

    public function test_rows_carry_name_dates_days_and_source_chips(): void
    {
        ['manager' => $manager, 'subject' => $subject, 'req' => $req] = $this->makeScenario();

        $groups = $this->actingAs($manager)
            ->getJson("/api/users/{$subject->id}/training-compliance")
            ->assertOk()
            ->json('groups');

        $overdue = $groups['overdue'][0];
        $this->assertSame('Fall Protection', $overdue['training_name']);
        $this->assertSame('overdue', $overdue['status']);
        $this->assertSame(-10, $overdue['days_until_due']);
        $this->assertNotNull($overdue['expires_at']);
        $this->assertNotNull($overdue['last_completed_at']);
        $this->assertSame([['type' => 'direct', 'id' => null, 'name' => null]], $overdue['sources']);

        $dueSoon = $groups['due_soon'][0];
        $this->assertSame(10, $dueSoon['days_until_due']);
        $this->assertSame(
            [['type' => 'requirement', 'id' => $req->id, 'name' => 'OSHA General']],
            $dueSoon['sources'],
        );

        $this->assertNull($groups['not_started'][0]['days_until_due']);
    }

    public function test_completion_history_rides_along_with_training_names(): void
    {
        ['org' => $org, 'manager' => $manager, 'subject' => $subject] = $this->makeScenario();

        // Credit for a training the user is NOT assigned — retroactive credit
        // per the spec must still show in history.
        $unassigned = Training::factory()->for($org, 'organization')->create(['name' => 'CPR']);
        Completion::factory()->create([
            'org_id' => $org->id,
            'user_id' => $subject->id,
            'module_type' => Training::class,
            'module_id' => $unassigned->id,
            'completion_date' => '2026-03-01',
            'expire_date' => '2027-03-01',
            'cert_ident' => 'CPR-123',
        ]);

        $completions = $this->actingAs($manager)
            ->getJson("/api/users/{$subject->id}/training-compliance")
            ->assertOk()
            ->json('completions');

        $this->assertCount(1, $completions);
        $this->assertSame('CPR', $completions[0]['training_name']);
        $this->assertSame('2026-03-01', $completions[0]['completion_date']);
        $this->assertSame('2027-03-01', $completions[0]['expire_date']);
        $this->assertSame('CPR-123', $completions[0]['cert_ident']);
        // Manual completion → no source class.
        $this->assertNull($completions[0]['class_id']);
        $this->assertNull($completions[0]['class_name']);
    }

    public function test_class_issued_completion_carries_class_link(): void
    {
        ['org' => $org, 'manager' => $manager, 'subject' => $subject] = $this->makeScenario();

        $training = Training::factory()->for($org, 'organization')->create(['name' => 'Forklift Cert']);
        $class = \App\Models\TrainingClass::factory()->for($org, 'organization')
            ->create(['name' => 'June Safety Day']);
        $ct = \App\Models\ClassTraining::factory()->for($class, 'trainingClass')
            ->create(['training_id' => $training->id]);
        Completion::factory()->create([
            'org_id' => $org->id,
            'user_id' => $subject->id,
            'module_type' => Training::class,
            'module_id' => $training->id,
            'completion_date' => '2026-05-01',
            'class_training_id' => $ct->id,
        ]);

        $completions = $this->actingAs($manager)
            ->getJson("/api/users/{$subject->id}/training-compliance")
            ->assertOk()
            ->json('completions');

        $row = collect($completions)->firstWhere('class_training_id', $ct->id);
        $this->assertSame($class->id, $row['class_id']);
        $this->assertSame('June Safety Day', $row['class_name']);
    }

    public function test_user_can_view_their_own_compliance(): void
    {
        ['subject' => $subject] = $this->makeScenario();
        $subject->assignRole('SelfView');
        $subject->update(['password' => bcrypt('secret-pass')]);

        $this->actingAs($subject->fresh())
            ->getJson("/api/users/{$subject->id}/training-compliance")
            ->assertOk();
    }

    public function test_self_view_user_cannot_view_someone_else(): void
    {
        ['org' => $org, 'subject' => $subject] = $this->makeScenario();
        $peeker = User::factory()->for($org, 'organization')->withRole('SelfView')->create();

        $this->actingAs($peeker)
            ->getJson("/api/users/{$subject->id}/training-compliance")
            ->assertForbidden();
    }

    public function test_cross_org_actor_gets_404(): void
    {
        ['subject' => $subject] = $this->makeScenario();
        $otherOrg = Organization::factory()->create();
        $outsider = User::factory()->for($otherOrg, 'organization')->withRole('Admin')->create();

        $this->actingAs($outsider)
            ->getJson("/api/users/{$subject->id}/training-compliance")
            ->assertNotFound();
    }
}
