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
 * Issue a single certificate to a missed person on a re-opened (scheduled)
 * class: enroll them if needed, mint the next number in the class's per-date
 * sequence via the shared close-out numbering, and set the topic result to
 * `pass` so the authoritative re-close preserves the credit. The heart of the
 * pass is the issue → re-close test: the cert must PERSIST with the SAME number.
 */
class ClassIssueCompletionTest extends TestCase
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

    /** Complete a single-topic class with one passed enrollee, then re-open it. */
    private function reopenedClass(Organization $org, User $manager): array
    {
        $training = Training::factory()->for($org, 'organization')->create(['cert_code' => 'FP']);
        $class = TrainingClass::factory()->for($org, 'organization')->create();
        $ct = ClassTraining::factory()->for($class, 'trainingClass')->create([
            'training_id' => $training->id,
            'cert_code' => 'FP',
            'repeating' => true,
            'repeat_days' => 365,
            'initial_only' => false,
            'as_needed' => false,
        ]);
        $passed = User::factory()->for($org, 'organization')->create();
        $eP = ClassEnrollment::factory()->for($class, 'trainingClass')->create(['user_id' => $passed->id]);

        $this->actingAs($manager)->postJson("/api/classes/{$class->id}/complete", [
            'completion_date' => '2026-06-01',
            'enrollments' => [
                ['id' => $eP->id, 'results' => [['class_training_id' => $ct->id, 'result' => 'pass']]],
            ],
        ])->assertOk();

        $this->actingAs($manager)->postJson("/api/classes/{$class->id}/reopen")->assertOk();

        return compact('class', 'ct', 'training', 'passed', 'eP');
    }

    public function test_issue_enrolls_the_person_mints_next_number_and_marks_pass(): void
    {
        $org = Organization::factory()->create();
        $manager = $this->manager($org);
        ['class' => $class, 'ct' => $ct, 'training' => $training] = $this->reopenedClass($org, $manager);

        // A person who was never on the roster.
        $missed = User::factory()->for($org, 'organization')->create();

        $this->actingAs($manager)
            ->postJson("/api/classes/{$class->id}/completions/issue", [
                'user_id' => $missed->id,
                'class_training_id' => $ct->id,
            ])
            ->assertOk()
            ->assertJsonPath('id', $class->id);

        // Enrolled now, with a pass result for the topic.
        $enrollment = ClassEnrollment::where('class_id', $class->id)->where('user_id', $missed->id)->firstOrFail();
        $this->assertSame('pass', ($enrollment->results ?? [])[$ct->id] ?? null);
        $this->assertSame('passed', $enrollment->status);

        // A completion crediting the training, numbered in the same date
        // sequence (continuing past the existing -001), never colliding.
        $completion = Completion::where('user_id', $missed->id)->firstOrFail();
        $this->assertSame(Training::class, $completion->module_type);
        $this->assertSame($training->id, $completion->module_id);
        $this->assertSame($ct->id, $completion->class_training_id);
        $this->assertStringStartsWith('FP20260601-', $completion->cert_id);
        $this->assertNotSame(
            Completion::where('class_training_id', $ct->id)->where('user_id', '!=', $missed->id)->first()?->cert_id,
            $completion->cert_id,
        );
    }

    /**
     * THE reconciliation test: after issuing a cert, re-closing the class must
     * PRESERVE it with the SAME number — because issue set the results entry to
     * pass.
     */
    public function test_issue_then_reclose_persists_with_the_same_number(): void
    {
        $org = Organization::factory()->create();
        $manager = $this->manager($org);
        ['class' => $class, 'ct' => $ct, 'eP' => $eP] = $this->reopenedClass($org, $manager);

        $missed = User::factory()->for($org, 'organization')->create();

        $this->actingAs($manager)
            ->postJson("/api/classes/{$class->id}/completions/issue", [
                'user_id' => $missed->id,
                'class_training_id' => $ct->id,
            ])
            ->assertOk();

        $issuedCertId = Completion::where('user_id', $missed->id)->firstOrFail()->cert_id;
        $issuedEnrollment = ClassEnrollment::where('class_id', $class->id)->where('user_id', $missed->id)->firstOrFail();

        // Re-close, marking both the original passer and the newly-issued person
        // (the modal pre-fills from the persisted results). The number holds.
        $this->actingAs($manager)->postJson("/api/classes/{$class->id}/complete", [
            'completion_date' => '2026-06-01',
            'enrollments' => [
                ['id' => $eP->id, 'results' => [['class_training_id' => $ct->id, 'result' => 'pass']]],
                ['id' => $issuedEnrollment->id, 'results' => [['class_training_id' => $ct->id, 'result' => 'pass']]],
            ],
        ])->assertOk();

        $after = Completion::where('user_id', $missed->id)->get();
        $this->assertCount(1, $after);
        $this->assertSame($issuedCertId, $after[0]->cert_id);
    }

    public function test_issuing_for_an_already_enrolled_person_reuses_the_enrollment(): void
    {
        $org = Organization::factory()->create();
        $manager = $this->manager($org);
        ['class' => $class, 'ct' => $ct] = $this->reopenedClass($org, $manager);

        // Enroll a second person who was not credited at close (marked fail).
        $missed = User::factory()->for($org, 'organization')->create();
        $e = ClassEnrollment::factory()->for($class, 'trainingClass')->create([
            'user_id' => $missed->id,
            'results' => [$ct->id => 'fail'],
            'status' => 'incomplete',
        ]);

        $this->actingAs($manager)
            ->postJson("/api/classes/{$class->id}/completions/issue", [
                'user_id' => $missed->id,
                'class_training_id' => $ct->id,
            ])
            ->assertOk();

        // No duplicate enrollment; the existing one flips to pass.
        $this->assertSame(1, ClassEnrollment::where('class_id', $class->id)->where('user_id', $missed->id)->count());
        $this->assertSame('pass', ($e->fresh()->results ?? [])[$ct->id] ?? null);
        $this->assertSame(1, Completion::where('user_id', $missed->id)->count());
    }

    public function test_cannot_issue_a_duplicate_for_a_live_cert(): void
    {
        $org = Organization::factory()->create();
        $manager = $this->manager($org);
        ['class' => $class, 'ct' => $ct, 'passed' => $passed] = $this->reopenedClass($org, $manager);

        // $passed already holds a live cert for this topic.
        $this->actingAs($manager)
            ->postJson("/api/classes/{$class->id}/completions/issue", [
                'user_id' => $passed->id,
                'class_training_id' => $ct->id,
            ])
            ->assertStatus(422);

        $this->assertSame(1, Completion::where('user_id', $passed->id)->count());
    }

    public function test_class_training_id_must_belong_to_this_class(): void
    {
        $org = Organization::factory()->create();
        $manager = $this->manager($org);
        ['class' => $class] = $this->reopenedClass($org, $manager);

        $other = TrainingClass::factory()->for($org, 'organization')->create();
        $foreignCt = ClassTraining::factory()->for($other, 'trainingClass')->create();
        $user = User::factory()->for($org, 'organization')->create();

        $this->actingAs($manager)
            ->postJson("/api/classes/{$class->id}/completions/issue", [
                'user_id' => $user->id,
                'class_training_id' => $foreignCt->id,
            ])
            ->assertStatus(422);
    }

    public function test_user_must_be_in_the_same_org(): void
    {
        $org = Organization::factory()->create();
        $manager = $this->manager($org);
        ['class' => $class, 'ct' => $ct] = $this->reopenedClass($org, $manager);

        $outsider = User::factory()->for(Organization::factory()->create(), 'organization')->create();

        $this->actingAs($manager)
            ->postJson("/api/classes/{$class->id}/completions/issue", [
                'user_id' => $outsider->id,
                'class_training_id' => $ct->id,
            ])
            ->assertStatus(422);
    }

    public function test_cannot_issue_on_a_completed_class(): void
    {
        $org = Organization::factory()->create();
        $manager = $this->manager($org);
        $training = Training::factory()->for($org, 'organization')->create();
        $class = TrainingClass::factory()->for($org, 'organization')->create(['status' => 'completed']);
        $ct = ClassTraining::factory()->for($class, 'trainingClass')->create(['training_id' => $training->id]);
        $user = User::factory()->for($org, 'organization')->create();

        $this->actingAs($manager)
            ->postJson("/api/classes/{$class->id}/completions/issue", [
                'user_id' => $user->id,
                'class_training_id' => $ct->id,
            ])
            ->assertStatus(422);
    }

    public function test_non_manager_cannot_issue(): void
    {
        $org = Organization::factory()->create();
        $manager = $this->manager($org);
        ['class' => $class, 'ct' => $ct] = $this->reopenedClass($org, $manager);
        $user = User::factory()->for($org, 'organization')->create();

        $none = User::factory()->for($org, 'organization')->withRole('None')->create();

        $this->actingAs($none)
            ->postJson("/api/classes/{$class->id}/completions/issue", [
                'user_id' => $user->id,
                'class_training_id' => $ct->id,
            ])
            ->assertForbidden();
    }
}
