<?php

namespace Tests\Feature\Tenancy;

use App\Models\ClassEnrollment;
use App\Models\ClassTraining;
use App\Models\Completion;
use App\Models\Organization;
use App\Models\Training;
use App\Models\TrainingClass;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Re-close is the lightweight counterpart to complete(): it just flips a
 * re-opened (previously-completed) class back to `completed` — no
 * reconciliation. Completions, cert_ids, enrollment results and class_training
 * expiries are left byte-for-byte identical; use complete() for a real
 * re-reconcile.
 */
class ClassRecloseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private function manager(Organization $org): User
    {
        return User::factory()->for($org, 'organization')->withRole('Manager')->create();
    }

    /** Complete a single-training class with one passed + one failed enrollee. */
    private function completedClass(Organization $org, User $manager): array
    {
        $training = Training::factory()->for($org, 'organization')->create();
        $class = TrainingClass::factory()->for($org, 'organization')->create();
        $ct = ClassTraining::factory()->for($class, 'trainingClass')->create([
            'training_id' => $training->id,
            'repeating' => true,
            'repeat_days' => 365,
            'initial_only' => false,
            'as_needed' => false,
        ]);
        $passed = User::factory()->for($org, 'organization')->create();
        $failed = User::factory()->for($org, 'organization')->create();
        $eP = ClassEnrollment::factory()->for($class, 'trainingClass')->create(['user_id' => $passed->id]);
        $eF = ClassEnrollment::factory()->for($class, 'trainingClass')->create(['user_id' => $failed->id]);

        $this->actingAs($manager)
            ->postJson("/api/classes/{$class->id}/complete", [
                'completion_date' => '2026-06-01',
                'enrollments' => [
                    ['id' => $eP->id, 'results' => [['class_training_id' => $ct->id, 'result' => 'pass']]],
                    ['id' => $eF->id, 'results' => [['class_training_id' => $ct->id, 'result' => 'fail']]],
                ],
            ])
            ->assertOk();

        return compact('training', 'class', 'ct', 'passed', 'failed', 'eP', 'eF');
    }

    public function test_reclose_flips_status_without_touching_anything_else(): void
    {
        $org = Organization::factory()->create();
        $manager = $this->manager($org);
        ['class' => $class, 'ct' => $ct, 'passed' => $passed, 'eP' => $eP] = $this->completedClass($org, $manager);

        $this->actingAs($manager)->postJson("/api/classes/{$class->id}/reopen")->assertOk();

        // Snapshot everything before re-close.
        $completionBefore = Completion::where('user_id', $passed->id)->firstOrFail();
        $certBefore = $completionBefore->cert_id;
        $resultsBefore = $eP->fresh()->results;
        $expireBefore = $ct->fresh()->expire_date;

        $this->actingAs($manager)
            ->postJson("/api/classes/{$class->id}/reclose")
            ->assertOk()
            ->assertJsonPath('status', 'completed');

        $class->refresh();
        $this->assertSame('completed', $class->status);
        $this->assertNotNull($class->completed_at);
        // completion_date is untouched by reclose.
        $this->assertSame('2026-06-01', $class->completion_date->toDateString());

        $completionAfter = Completion::where('user_id', $passed->id)->firstOrFail();
        $this->assertSame($completionBefore->id, $completionAfter->id);
        $this->assertSame($certBefore, $completionAfter->cert_id);
        $this->assertEquals($resultsBefore, $eP->fresh()->results);
        $this->assertEquals($expireBefore, $ct->fresh()->expire_date);
    }

    /**
     * The critical proof that reclose bypasses CompleteClass entirely: mark an
     * enrollee's results as `incomplete` for a topic they still hold a live
     * completion for (an inconsistent state a real complete() reconcile would
     * never leave — it would de-issue the completion). reclose() must NOT
     * touch it.
     */
    public function test_reclose_does_not_reconcile_even_when_results_disagree_with_completions(): void
    {
        $org = Organization::factory()->create();
        $manager = $this->manager($org);
        ['class' => $class, 'ct' => $ct, 'passed' => $passed, 'eP' => $eP] = $this->completedClass($org, $manager);

        $this->actingAs($manager)->postJson("/api/classes/{$class->id}/reopen")->assertOk();

        $completion = Completion::where('user_id', $passed->id)->firstOrFail();
        $this->assertNotNull($completion->cert_id);

        // Deliberately desync: still holds a completion, but results now say
        // incomplete. A real complete() reconcile would de-issue this.
        $eP->update(['results' => [$ct->id => 'incomplete']]);

        $this->actingAs($manager)
            ->postJson("/api/classes/{$class->id}/reclose")
            ->assertOk();

        $this->assertDatabaseHas('completions', ['id' => $completion->id, 'deleted_at' => null]);
        $this->assertSame(1, Completion::where('user_id', $passed->id)->count());
        $this->assertSame($completion->cert_id, $completion->fresh()->cert_id);
    }

    public function test_reclose_422_when_never_completed(): void
    {
        $org = Organization::factory()->create();
        $manager = $this->manager($org);
        $class = TrainingClass::factory()->for($org, 'organization')->create([
            'status' => 'scheduled',
            'completion_date' => null,
        ]);

        $this->actingAs($manager)
            ->postJson("/api/classes/{$class->id}/reclose")
            ->assertStatus(422)
            ->assertJsonPath('message', "This class hasn't been completed yet — use Complete.");
    }

    public function test_reclose_422_when_already_completed(): void
    {
        $org = Organization::factory()->create();
        $manager = $this->manager($org);
        ['class' => $class] = $this->completedClass($org, $manager);

        $this->actingAs($manager)
            ->postJson("/api/classes/{$class->id}/reclose")
            ->assertStatus(422)
            ->assertJsonPath('message', 'This class is already completed.');
    }

    public function test_non_manager_cannot_reclose(): void
    {
        $org = Organization::factory()->create();
        $none = User::factory()->for($org, 'organization')->withRole('None')->create();
        $class = TrainingClass::factory()->for($org, 'organization')->create([
            'status' => 'scheduled',
            'completion_date' => '2026-06-01',
        ]);

        $this->actingAs($none)
            ->postJson("/api/classes/{$class->id}/reclose")
            ->assertForbidden();
    }

    public function test_reclose_is_org_scoped(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $managerB = $this->manager($orgB);
        $classA = TrainingClass::factory()->for($orgA, 'organization')->create([
            'status' => 'scheduled',
            'completion_date' => '2026-06-01',
        ]);

        $this->actingAs($managerB)
            ->postJson("/api/classes/{$classA->id}/reclose")
            ->assertNotFound();
    }
}
