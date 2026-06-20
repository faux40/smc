<?php

namespace Tests\Feature\Personas;

use App\Models\Completion;
use App\Models\StdFrequency;
use App\Models\Training;
use App\Models\TrainingAssignment;
use App\Models\User;
use Carbon\CarbonInterface;
use PHPUnit\Framework\Attributes\Group;

/**
 * Persona: the training manager — the person who runs the org's training
 * program day to day. Authoring (trainings, requirements) needs the Admin
 * role; the Manager role covers the operational subset (assign, record,
 * run classes, watch the dashboard) — the last test pins that boundary.
 *
 * The flagship question this persona asks the app: "who needs what, and
 * when — and how do I make that happen?"
 */
#[Group('persona')]
#[Group('persona-manager')]
class TrainingManagerPersonaTest extends PersonaTestCase
{
    public function test_creates_a_training_template_and_finds_it_in_the_catalog(): void
    {
        $manager = $this->actor('Admin');
        $freq = $this->annualFrequency();

        $this->actingAs($manager)
            ->postJson('/api/trainings', [
                'name' => 'Confined Space Entry',
                'description' => 'Permit-required entry.',
                'default_hours' => 6,
                'initial_only' => false,
                'repeating' => true,
                'as_needed' => false,
                'std_freq_id' => $freq->id,
            ])
            ->assertCreated();

        $names = collect($this->actingAs($manager)->getJson('/api/trainings')->assertOk()->json())
            ->pluck('name');
        $this->assertContains('Confined Space Entry', $names);
    }

    public function test_builds_a_requirement_with_adjusted_element_timing(): void
    {
        $manager = $this->actor('Admin');
        $annual = $this->annualFrequency();
        $quarterly = StdFrequency::create([
            'org_id' => $this->org->id, 'name' => 'Quarterly', 'repeat_days' => 90,
        ]);
        $training = $this->repeatingTraining('Fall Protection', $annual);

        $reqId = $this->actingAs($manager)
            ->postJson('/api/requirements', [
                'name' => 'Tower Crew',
                'description' => 'Elevated work baseline.',
            ])
            ->assertCreated()
            ->json('id');

        // The element copies the training's timing but can tighten it.
        $this->actingAs($manager)
            ->postJson("/api/requirements/{$reqId}/elements", [
                'module_type' => Training::class,
                'module_id' => $training->id,
                'name' => $training->name,
                'initial_only' => false,
                'repeating' => true,
                'as_needed' => false,
                'std_freq_id' => $quarterly->id,
            ])
            ->assertCreated();

        $elements = $this->actingAs($manager)
            ->getJson("/api/requirements/{$reqId}/elements")
            ->assertOk()
            ->json();
        $this->assertCount(1, $elements);
        $this->assertSame($quarterly->id, $elements[0]['std_freq_id']);
    }

    public function test_assigns_direct_via_requirement_and_in_bulk(): void
    {
        $manager = $this->actor('Admin');
        $freq = $this->annualFrequency();
        $training = $this->repeatingTraining('Forklift', $freq);
        $crew = User::factory()->count(3)->for($this->org, 'organization')->create();

        // Direct, one person.
        $this->actingAs($manager)
            ->postJson('/api/training-assignments', [
                'user_id' => $crew[0]->id, 'source_type' => 'direct', 'training_id' => $training->id,
            ])
            ->assertCreated();

        // Via a requirement: one TA per training element.
        $reqId = $this->buildRequirement($manager, 'Operators', [$training]);
        $this->actingAs($manager)
            ->postJson('/api/training-assignments', [
                'user_id' => $crew[1]->id, 'source_type' => 'requirement', 'requirement_id' => $reqId,
            ])
            ->assertCreated();

        // In bulk (the tag workflow resolves a tag to user_ids client-side).
        // Re-assigning someone who already holds the training is additive —
        // another source on the same assignment, never a duplicate row.
        $this->actingAs($manager)
            ->postJson('/api/bulk-training-assignments', [
                'user_ids' => $crew->pluck('id')->all(),
                'source_type' => 'direct',
                'training_id' => $training->id,
            ])
            ->assertCreated()
            ->assertJson(['created_count' => 3, 'skipped_count' => 0]);

        $rows = $this->actingAs($manager)->getJson('/api/training-assignments')->assertOk()->json();
        $this->assertCount(3, $rows, 'One assignment per crew member — no duplicates.');
    }

    public function test_records_a_manual_completion_and_the_user_goes_current(): void
    {
        $manager = $this->actor('Admin');
        $freq = $this->annualFrequency();
        $training = $this->repeatingTraining('Hazmat', $freq);
        $worker = User::factory()->for($this->org, 'organization')->create();

        $reqId = $this->buildRequirement($manager, 'Haz Crew', [$training]);
        $this->actingAs($manager)
            ->postJson('/api/training-assignments', [
                'user_id' => $worker->id, 'source_type' => 'requirement', 'requirement_id' => $reqId,
            ])
            ->assertCreated();

        $elementId = $this->actingAs($manager)
            ->getJson("/api/requirements/{$reqId}/elements")->json('0.id');

        // Before: not started.
        $before = $this->actingAs($manager)
            ->getJson("/api/users/{$worker->id}/training-compliance")->json('groups');
        $this->assertNotEmpty($before['not_started']);

        $this->actingAs($manager)
            ->postJson('/api/completions', [
                'user_id' => $worker->id,
                'module_type' => Training::class,
                'module_id' => $training->id,
                'completion_date' => now()->toDateString(),
                'rqmt_element_ids' => [$elementId],
            ])
            ->assertCreated();

        // After: current, with the derived expiry (completion + 365d).
        $after = $this->actingAs($manager)
            ->getJson("/api/users/{$worker->id}/training-compliance")->json('groups');
        $current = collect($after['current'])->firstWhere('training_name', 'Hazmat');
        $this->assertNotNull($current, 'Hazmat should be Current after the completion.');
        $this->assertSame(now()->addDays(365)->toDateString(), $current['expires_at']);
        $this->assertSame([], $after['not_started']);
    }

    public function test_runs_a_class_from_schedule_to_credit(): void
    {
        $manager = $this->actor('Manager'); // classes are Manager-territory
        $freq = $this->annualFrequency();
        $training = $this->repeatingTraining('First Aid', $freq);
        $training->update(['default_hours' => 2]);
        $student = User::factory()->for($this->org, 'organization')->create();

        $classId = $this->actingAs($manager)
            ->postJson('/api/classes', [
                'name' => 'First Aid Refresher',
                'scheduled_date' => now()->addDays(7)->toDateString(),
                'training_ids' => [$training->id],
            ])
            ->assertCreated()
            ->json('id');

        $detail = $this->actingAs($manager)
            ->postJson("/api/classes/{$classId}/enrollments", ['user_id' => $student->id])
            ->assertOk()
            ->json();
        $enrollmentId = $detail['enrollments'][0]['id'];
        $classTrainingId = $detail['trainings'][0]['id'];

        $closed = $this->actingAs($manager)
            ->postJson("/api/classes/{$classId}/complete", [
                'completion_date' => now()->toDateString(),
                'enrollments' => [[
                    'id' => $enrollmentId,
                    'results' => [['class_training_id' => $classTrainingId, 'passed' => true]],
                ]],
            ])
            ->assertOk()
            ->json();

        $this->assertSame('completed', $closed['status']);
        $this->assertSame('passed', $closed['enrollments'][0]['status']);

        // The credit landed: a cert-bearing completion, visible on the
        // student's record, and the student reads as Current.
        $credit = $closed['trainings'][0]['credits'][0];
        $this->assertSame($student->id, $credit['user_id']);
        $this->assertStringStartsWith('CERT', $credit['cert_id']);

        $this->actingAs($manager)
            ->postJson('/api/training-assignments', [
                'user_id' => $student->id, 'source_type' => 'direct', 'training_id' => $training->id,
            ])
            ->assertCreated();

        $groups = $this->actingAs($manager)
            ->getJson("/api/users/{$student->id}/training-compliance")->json('groups');
        $this->assertNotNull(collect($groups['current'])->firstWhere('training_name', 'First Aid'));
    }

    public function test_dashboard_answers_who_needs_what_and_when(): void
    {
        $manager = $this->actor('Manager');
        $freq = $this->annualFrequency();
        $training = $this->repeatingTraining('Fall Protection', $freq);
        $overdueUser = User::factory()->for($this->org, 'organization')->create(['f_name' => 'Olive', 'l_name' => 'Overdue']);
        $dueSoonUser = User::factory()->for($this->org, 'organization')->create(['f_name' => 'Dana', 'l_name' => 'Duesoon']);

        $this->seedAssignmentWithDates($overdueUser, $training, now()->subDays(400), now()->subDays(35));
        // Inside the 30-day expiring-soon window → the "forecast" rows.
        $this->seedAssignmentWithDates($dueSoonUser, $training, now()->subDays(345), now()->addDays(20));

        $rows = collect($this->actingAs($manager)->getJson('/api/dashboard/needs-action')->assertOk()->json());

        // Dashboard rows carry the sortable (last-name-first) display name.
        $olive = $rows->firstWhere('user_name', 'Overdue, Olive');
        $this->assertSame('overdue', $olive['status']);
        $this->assertSame('Fall Protection', $olive['training_name']);
        $this->assertLessThan(0, $olive['days_until_due']);

        // Forecasting upcoming demand: due-soon rows carry days_until_due.
        $dana = $rows->firstWhere('user_name', 'Duesoon, Dana');
        $this->assertSame('due_soon', $dana['status']);
        $this->assertSame(20, $dana['days_until_due']);

        // And the per-user drill-down is open to managers.
        $this->actingAs($manager)
            ->getJson("/api/users/{$overdueUser->id}/training-compliance")
            ->assertOk();

        $summary = $this->actingAs($manager)->getJson('/api/dashboard/summary')->assertOk()->json();
        $this->assertSame(1, $summary['counts']['overdue']);
        $this->assertSame(1, $summary['counts']['due_soon']);
        $this->assertSame(1, $summary['users_with_overdue']);
    }

    public function test_unwinds_assignments_remove_one_remove_a_set_break_one_out(): void
    {
        $admin = $this->actor('Admin');
        $freq = $this->annualFrequency();
        $fall = $this->repeatingTraining('Fall Protection', $freq);
        $loto = $this->repeatingTraining('Lockout/Tagout', $freq);
        $worker = User::factory()->for($this->org, 'organization')->create();

        $reqId = $this->buildRequirement($admin, 'Site Baseline', [$fall, $loto]);
        $this->actingAs($admin)
            ->postJson('/api/training-assignments', [
                'user_id' => $worker->id, 'source_type' => 'requirement', 'requirement_id' => $reqId,
            ])
            ->assertCreated();

        $tas = collect($this->actingAs($admin)->getJson('/api/training-assignments')->json());
        $this->assertCount(2, $tas);
        $fallTa = $tas->firstWhere('training_id', $fall->id);

        // Break Fall Protection out of the set: the broken-out assignment
        // (requirement was its only source) is removed, and its siblings
        // convert to direct so the rest of the set survives the break-up.
        $this->actingAs($admin)
            ->deleteJson("/api/training-assignments/{$fallTa['id']}/from-requirement", [
                'requirement_id' => $reqId,
            ])
            ->assertOk();

        $after = collect($this->actingAs($admin)->getJson('/api/training-assignments')->json());
        $this->assertNull($after->firstWhere('training_id', $fall->id), 'Broken-out assignment is removed.');
        $lotoTa = $after->firstWhere('training_id', $loto->id);
        $this->assertNotNull($lotoTa, 'Sibling survives the break-up…');
        $this->assertNull($lotoTa['active_sources'][0]['sourceable_type'], '…converted to direct.');

        // Remove the survivor directly → clean slate.
        $this->actingAs($admin)
            ->deleteJson("/api/training-assignments/{$lotoTa['id']}")
            ->assertOk();
        $this->assertSame([], $this->actingAs($admin)->getJson('/api/training-assignments')->json());

        // Fresh set, then remove the whole thing per (user, requirement) —
        // both assignments disappear together.
        $this->actingAs($admin)
            ->postJson('/api/training-assignments', [
                'user_id' => $worker->id, 'source_type' => 'requirement', 'requirement_id' => $reqId,
            ])
            ->assertCreated();
        $this->assertCount(2, $this->actingAs($admin)->getJson('/api/training-assignments')->json());

        $this->actingAs($admin)
            ->deleteJson('/api/training-assignments/by-requirement', [
                'user_id' => $worker->id, 'requirement_id' => $reqId,
            ])
            ->assertOk();
        $this->assertSame([], $this->actingAs($admin)->getJson('/api/training-assignments')->json());
    }

    public function test_one_completion_satisfies_every_source_of_a_multi_assigned_training(): void
    {
        $admin = $this->actor('Admin');
        $freq = $this->annualFrequency();
        $training = $this->repeatingTraining('Hearing Conservation', $freq);
        $worker = User::factory()->for($this->org, 'organization')->create();

        $reqId = $this->buildRequirement($admin, 'Plant Floor', [$training]);
        $this->actingAs($admin)->postJson('/api/training-assignments', [
            'user_id' => $worker->id, 'source_type' => 'direct', 'training_id' => $training->id,
        ])->assertCreated();
        $this->actingAs($admin)->postJson('/api/training-assignments', [
            'user_id' => $worker->id, 'source_type' => 'requirement', 'requirement_id' => $reqId,
        ])->assertCreated();

        // One training, two sources, still a single line on the user.
        $tas = $this->actingAs($admin)->getJson('/api/training-assignments')->json();
        $this->assertCount(1, $tas);
        $this->assertCount(2, $tas[0]['active_sources']);

        Completion::create([
            'org_id' => $this->org->id,
            'user_id' => $worker->id,
            'module_type' => Training::class,
            'module_id' => $training->id,
            'completion_date' => now()->toDateString(),
        ]);

        $groups = $this->actingAs($admin)
            ->getJson("/api/users/{$worker->id}/training-compliance")->json('groups');
        $current = collect($groups['current'])->firstWhere('training_name', 'Hearing Conservation');
        $this->assertNotNull($current, 'One completion should satisfy both sources.');
        $this->assertCount(2, $current['sources']);
        $this->assertSame([], $groups['not_started']);
        $this->assertSame([], $groups['overdue']);
    }

    public function test_manager_role_runs_operations_but_does_not_author(): void
    {
        $manager = $this->actor('Manager');
        $freq = $this->annualFrequency();
        $training = $this->repeatingTraining('Forklift', $freq);
        $worker = User::factory()->for($this->org, 'organization')->create();

        // Can: assign, see the dashboard (covered above), record completions.
        $this->actingAs($manager)
            ->postJson('/api/training-assignments', [
                'user_id' => $worker->id, 'source_type' => 'direct', 'training_id' => $training->id,
            ])
            ->assertCreated();

        // Cannot: author templates/requirements or delete assignments.
        $this->actingAs($manager)
            ->postJson('/api/trainings', [
                'name' => 'X', 'initial_only' => false, 'repeating' => true,
                'as_needed' => false, 'std_freq_id' => $freq->id,
            ])
            ->assertForbidden();
        $this->actingAs($manager)
            ->postJson('/api/requirements', ['name' => 'X'])
            ->assertForbidden();

        $taId = $this->actingAs($manager)->getJson('/api/training-assignments')->json('0.id');
        $this->actingAs($manager)->deleteJson("/api/training-assignments/{$taId}")->assertForbidden();
    }

    /** @param  array<int, Training>  $trainings */
    private function buildRequirement(User $author, string $name, array $trainings): string
    {
        $reqId = $this->actingAs($author)
            ->postJson('/api/requirements', ['name' => $name])
            ->assertCreated()
            ->json('id');

        foreach ($trainings as $training) {
            $this->actingAs($author)
                ->postJson("/api/requirements/{$reqId}/elements", [
                    'module_type' => Training::class,
                    'module_id' => $training->id,
                    'name' => $training->name,
                    'initial_only' => $training->initial_only,
                    'repeating' => $training->repeating,
                    'as_needed' => $training->as_needed,
                    'std_freq_id' => $training->std_freq_id,
                ])
                ->assertCreated();
        }

        return $reqId;
    }

    private function seedAssignmentWithDates(
        User $user,
        Training $training,
        CarbonInterface $completed,
        CarbonInterface $expires,
    ): void {
        Completion::create([
            'org_id' => $this->org->id,
            'user_id' => $user->id,
            'module_type' => Training::class,
            'module_id' => $training->id,
            'completion_date' => $completed->toDateString(),
            'expire_date' => $expires->toDateString(),
        ]);

        TrainingAssignment::create([
            'org_id' => $this->org->id,
            'user_id' => $user->id,
            'training_id' => $training->id,
            'name' => $training->name,
            'last_completed_at' => $completed->toDateString(),
            'expires_at' => $expires->toDateString(),
        ]);
    }
}
