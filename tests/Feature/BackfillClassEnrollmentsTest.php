<?php

namespace Tests\Feature;

use App\Actions\BackfillClassEnrollments;
use App\Models\ClassEnrollment;
use App\Models\ClassTraining;
use App\Models\Completion;
use App\Models\Organization;
use App\Models\Training;
use App\Models\TrainingClass;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BackfillClassEnrollmentsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{class: TrainingClass, ct: ClassTraining, org: Organization}
     */
    private function classWithTopic(?Organization $org = null): array
    {
        $org = $org ?? Organization::factory()->create();
        $class = TrainingClass::factory()->for($org, 'organization')
            ->create(['status' => 'completed', 'completion_date' => '2026-01-10']);
        $training = Training::factory()->for($org, 'organization')->create();
        $ct = ClassTraining::factory()->for($class, 'trainingClass')
            ->create(['training_id' => $training->id]);

        return compact('class', 'ct', 'org');
    }

    private function completionFor(Organization $org, User $user, ClassTraining $ct): Completion
    {
        return Completion::factory()->for($org, 'organization')->for($user, 'user')
            ->state([
                'module_type' => Training::class,
                'module_id' => $ct->training_id,
                'class_training_id' => $ct->id,
            ])
            ->create();
    }

    public function test_creates_enrollments_for_users_with_class_linked_completions(): void
    {
        ['class' => $class, 'ct' => $ct, 'org' => $org] = $this->classWithTopic();
        $a = User::factory()->for($org, 'organization')->create();
        $b = User::factory()->for($org, 'organization')->create();
        $this->completionFor($org, $a, $ct);
        $this->completionFor($org, $b, $ct);

        $created = app(BackfillClassEnrollments::class)->handle();

        $this->assertSame(2, $created);
        $this->assertDatabaseHas('class_enrollments', [
            'class_id' => $class->id, 'user_id' => $a->id, 'status' => 'passed',
        ]);
        $this->assertDatabaseHas('class_enrollments', [
            'class_id' => $class->id, 'user_id' => $b->id, 'status' => 'passed',
        ]);
    }

    public function test_is_idempotent(): void
    {
        ['class' => $class, 'ct' => $ct, 'org' => $org] = $this->classWithTopic();
        $a = User::factory()->for($org, 'organization')->create();
        $this->completionFor($org, $a, $ct);

        $first = app(BackfillClassEnrollments::class)->handle();
        $second = app(BackfillClassEnrollments::class)->handle();

        $this->assertSame(1, $first);
        $this->assertSame(0, $second);
        $this->assertSame(1, ClassEnrollment::where('class_id', $class->id)->count());
    }

    public function test_does_not_duplicate_an_existing_enrollment(): void
    {
        ['class' => $class, 'ct' => $ct, 'org' => $org] = $this->classWithTopic();
        $a = User::factory()->for($org, 'organization')->create();
        $this->completionFor($org, $a, $ct);
        ClassEnrollment::create([
            'class_id' => $class->id, 'user_id' => $a->id, 'status' => 'enrolled',
        ]);

        $created = app(BackfillClassEnrollments::class)->handle();

        $this->assertSame(0, $created);
        // Existing row is left as-is (not flipped to passed).
        $this->assertDatabaseHas('class_enrollments', [
            'class_id' => $class->id, 'user_id' => $a->id, 'status' => 'enrolled',
        ]);
        $this->assertSame(1, ClassEnrollment::where('class_id', $class->id)->count());
    }

    public function test_ignores_manual_completions_without_a_class_link(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->for($org, 'organization')->create();
        $training = Training::factory()->for($org, 'organization')->create();
        // Manual completion: no class_training_id.
        Completion::factory()->for($org, 'organization')->for($user, 'user')
            ->state([
                'module_type' => Training::class,
                'module_id' => $training->id,
                'class_training_id' => null,
            ])
            ->create();

        $created = app(BackfillClassEnrollments::class)->handle();

        $this->assertSame(0, $created);
        $this->assertSame(0, ClassEnrollment::count());
    }

    public function test_respects_org_scope(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        ['ct' => $ctA, 'org' => $orgA] = $this->classWithTopic($orgA);
        ['class' => $classB, 'ct' => $ctB] = $this->classWithTopic($orgB);
        $this->completionFor($orgA, User::factory()->for($orgA, 'organization')->create(), $ctA);
        $this->completionFor($orgB, User::factory()->for($orgB, 'organization')->create(), $ctB);

        $created = app(BackfillClassEnrollments::class)->handle($orgA->id);

        $this->assertSame(1, $created);
        // Org B untouched.
        $this->assertSame(0, ClassEnrollment::where('class_id', $classB->id)->count());
    }

    public function test_command_runs_the_backfill(): void
    {
        ['org' => $org, 'ct' => $ct] = $this->classWithTopic();
        $this->completionFor($org, User::factory()->for($org, 'organization')->create(), $ct);

        $this->artisan('tw:backfill-enrollments')
            ->assertSuccessful();

        $this->assertSame(1, ClassEnrollment::count());
    }
}
