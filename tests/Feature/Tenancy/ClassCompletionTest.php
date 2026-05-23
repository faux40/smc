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
 * Phase B — closing a class generates completions for its passed enrollees
 * against its associated trainings, with class-level dates, then locks the
 * class. Generated completions are standalone (no pivot) — they credit by
 * module identity (see UserComplianceTest).
 */
class ClassCompletionTest extends TestCase
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

    public function test_completing_generates_completions_for_passed_enrollees_with_computed_expiry(): void
    {
        $org = Organization::factory()->create();
        $manager = $this->manager($org);
        $training = Training::factory()->for($org, 'organization')->create();
        $class = TrainingClass::factory()->for($org, 'organization')->create();
        $ct = ClassTraining::factory()->for($class, 'trainingClass')->create([
            'training_id' => $training->id,
            'repeating' => true,
            'initial_only' => false,
            'as_needed' => false,
            'repeat_days' => 365,
        ]);
        $passed = User::factory()->for($org, 'organization')->create();
        $failed = User::factory()->for($org, 'organization')->create();
        $eP = ClassEnrollment::factory()->for($class, 'trainingClass')->create(['user_id' => $passed->id]);
        $eF = ClassEnrollment::factory()->for($class, 'trainingClass')->create(['user_id' => $failed->id]);

        $this->actingAs($manager)
            ->postJson("/api/classes/{$class->id}/complete", [
                'completion_date' => '2026-06-01',
                'enrollments' => [
                    ['id' => $eP->id, 'results' => [['class_training_id' => $ct->id, 'passed' => true]]],
                    ['id' => $eF->id, 'notes' => 'failed the test', 'results' => [['class_training_id' => $ct->id, 'passed' => false]]],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('status', 'completed');

        // Passed enrollee → one completion, module-poly to the training, with
        // completion_date = class date and expire_date = date + repeat_days.
        $c = Completion::where('user_id', $passed->id)->get();
        $this->assertCount(1, $c);
        $this->assertSame(Training::class, $c[0]->module_type);
        $this->assertSame($training->id, $c[0]->module_id);
        $this->assertSame('2026-06-01', $c[0]->completion_date->toDateString());
        $this->assertSame('2027-06-01', $c[0]->expire_date->toDateString());

        // Failed enrollee → no completion.
        $this->assertSame(0, Completion::where('user_id', $failed->id)->count());

        // Roll-up status: passed all → passed; passed none → incomplete.
        $this->assertSame('passed', $eP->fresh()->status);
        $this->assertSame('incomplete', $eF->fresh()->status);
        $this->assertSame('failed the test', $eF->fresh()->notes);
    }

    public function test_partial_pass_issues_certs_only_for_the_passed_trainings(): void
    {
        $org = Organization::factory()->create();
        $manager = $this->manager($org);
        $first = Training::factory()->for($org, 'organization')->create();
        $fall = Training::factory()->for($org, 'organization')->create();
        $class = TrainingClass::factory()->for($org, 'organization')->create();
        $ctFirst = ClassTraining::factory()->for($class, 'trainingClass')->create(['training_id' => $first->id]);
        $ctFall = ClassTraining::factory()->for($class, 'trainingClass')->create(['training_id' => $fall->id]);
        $john = User::factory()->for($org, 'organization')->create();
        $eJohn = ClassEnrollment::factory()->for($class, 'trainingClass')->create(['user_id' => $john->id]);

        // John passes Fall Protection but is incomplete for First Aid.
        $this->actingAs($manager)
            ->postJson("/api/classes/{$class->id}/complete", [
                'completion_date' => '2026-06-01',
                'enrollments' => [
                    ['id' => $eJohn->id, 'results' => [
                        ['class_training_id' => $ctFirst->id, 'passed' => false],
                        ['class_training_id' => $ctFall->id, 'passed' => true],
                    ]],
                ],
            ])
            ->assertOk();

        // Exactly one completion — for Fall Protection, not First Aid.
        $comps = Completion::where('user_id', $john->id)->get();
        $this->assertCount(1, $comps);
        $this->assertSame($fall->id, $comps[0]->module_id);
        $this->assertSame($ctFall->id, $comps[0]->class_training_id);

        // Passed some-but-not-all → status rolls up to 'partial'.
        $this->assertSame('partial', $eJohn->fresh()->status);
    }

    public function test_completing_generates_cert_ids_per_passed_student_and_topic(): void
    {
        $org = Organization::factory()->create();
        $manager = $this->manager($org);
        $training = Training::factory()->for($org, 'organization')->create();
        $class = TrainingClass::factory()->for($org, 'organization')->create();
        $ct = ClassTraining::factory()->for($class, 'trainingClass')->create([
            'training_id' => $training->id,
            'cert_code' => 'FPAP',
        ]);
        $a = User::factory()->for($org, 'organization')->create();
        $b = User::factory()->for($org, 'organization')->create();
        $eA = ClassEnrollment::factory()->for($class, 'trainingClass')->create(['user_id' => $a->id]);
        $eB = ClassEnrollment::factory()->for($class, 'trainingClass')->create(['user_id' => $b->id]);

        $this->actingAs($manager)
            ->postJson("/api/classes/{$class->id}/complete", [
                'completion_date' => '2026-06-01',
                'enrollments' => [
                    ['id' => $eA->id, 'results' => [['class_training_id' => $ct->id, 'passed' => true]]],
                    ['id' => $eB->id, 'results' => [['class_training_id' => $ct->id, 'passed' => true]]],
                ],
            ])
            ->assertOk();

        $certs = Completion::query()
            ->whereIn('user_id', [$a->id, $b->id])
            ->pluck('cert_id')
            ->sort()
            ->values();

        // One sequential, snapshot-coded cert id per passed student × topic.
        $this->assertEqualsCanonicalizing(
            ['FPAP20260601-001', 'FPAP20260601-002'],
            $certs->all(),
        );

        // Each completion links back to the class_training snapshot.
        $this->assertSame(
            2,
            Completion::where('class_training_id', $ct->id)->count(),
        );
    }

    public function test_initial_only_training_yields_a_completion_with_no_expiry(): void
    {
        $org = Organization::factory()->create();
        $manager = $this->manager($org);
        $training = Training::factory()->for($org, 'organization')->create();
        $class = TrainingClass::factory()->for($org, 'organization')->create();
        $ct = ClassTraining::factory()->for($class, 'trainingClass')->create([
            'training_id' => $training->id,
            'repeating' => false,
            'initial_only' => true,
            'as_needed' => false,
            'repeat_days' => null,
        ]);
        $user = User::factory()->for($org, 'organization')->create();
        $e = ClassEnrollment::factory()->for($class, 'trainingClass')->create(['user_id' => $user->id]);

        $this->actingAs($manager)
            ->postJson("/api/classes/{$class->id}/complete", [
                'completion_date' => '2026-06-01',
                'enrollments' => [['id' => $e->id, 'results' => [['class_training_id' => $ct->id, 'passed' => true]]]],
            ])
            ->assertOk();

        $this->assertNull(Completion::where('user_id', $user->id)->firstOrFail()->expire_date);
    }

    public function test_a_completed_class_cannot_be_completed_again(): void
    {
        $org = Organization::factory()->create();
        $manager = $this->manager($org);
        $class = TrainingClass::factory()->for($org, 'organization')->create(['status' => 'completed']);

        $this->actingAs($manager)
            ->postJson("/api/classes/{$class->id}/complete", [
                'completion_date' => '2026-06-01',
                'enrollments' => [],
            ])
            ->assertStatus(422);
    }

    public function test_completed_class_is_read_only(): void
    {
        $org = Organization::factory()->create();
        $manager = $this->manager($org);
        $class = TrainingClass::factory()->for($org, 'organization')->create(['status' => 'completed']);
        $student = User::factory()->for($org, 'organization')->create();

        // Enrolling onto a completed class is rejected (view-only).
        $this->actingAs($manager)
            ->postJson("/api/classes/{$class->id}/enrollments", ['user_id' => $student->id])
            ->assertStatus(422);
    }

    public function test_non_manager_cannot_complete_a_class(): void
    {
        $org = Organization::factory()->create();
        $none = User::factory()->for($org, 'organization')->withRole('None')->create();
        $class = TrainingClass::factory()->for($org, 'organization')->create();

        $this->actingAs($none)
            ->postJson("/api/classes/{$class->id}/complete", [
                'completion_date' => '2026-06-01',
                'enrollments' => [],
            ])
            ->assertForbidden();
    }
}
