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
 * Revoke a single certificate on a re-opened (scheduled) class: soft-delete the
 * completion (retaining revoke_reason + deleted_at for audit) and set that
 * enrollee's topic result to non-pass so the authoritative re-close reconcile
 * never resurrects it. The heart of the pass is the revoke → re-close test:
 * a revoked cert must STAY gone.
 */
class ClassRevokeCompletionTest extends TestCase
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
        $training = Training::factory()->for($org, 'organization')->create();
        $class = TrainingClass::factory()->for($org, 'organization')->create();
        $ct = ClassTraining::factory()->for($class, 'trainingClass')->create([
            'training_id' => $training->id,
            'repeating' => true,
            'repeat_days' => 365,
            'initial_only' => false,
            'as_needed' => false,
        ]);
        $user = User::factory()->for($org, 'organization')->create();
        $e = ClassEnrollment::factory()->for($class, 'trainingClass')->create(['user_id' => $user->id]);

        $this->actingAs($manager)->postJson("/api/classes/{$class->id}/complete", [
            'completion_date' => '2026-06-01',
            'enrollments' => [
                ['id' => $e->id, 'results' => [['class_training_id' => $ct->id, 'result' => 'pass']]],
            ],
        ])->assertOk();

        $this->actingAs($manager)->postJson("/api/classes/{$class->id}/reopen")->assertOk();

        return compact('class', 'ct', 'user', 'e');
    }

    public function test_revoke_soft_deletes_the_cert_stores_reason_and_marks_incomplete(): void
    {
        $org = Organization::factory()->create();
        $manager = $this->manager($org);
        ['class' => $class, 'ct' => $ct, 'user' => $user, 'e' => $e] = $this->reopenedClass($org, $manager);

        $completion = Completion::where('user_id', $user->id)->firstOrFail();

        $this->actingAs($manager)
            ->postJson("/api/classes/{$class->id}/completions/{$completion->id}/revoke", [
                'reason' => 'Attended the wrong session.',
            ])
            ->assertOk()
            ->assertJsonPath('id', $class->id);

        // Soft-deleted (not visible), but retained with the reason for auditors.
        $this->assertSame(0, Completion::where('user_id', $user->id)->count());
        $trashed = Completion::withTrashed()->where('user_id', $user->id)->firstOrFail();
        $this->assertNotNull($trashed->deleted_at);
        $this->assertSame('Attended the wrong session.', $trashed->revoke_reason);

        // Results map is in step: that topic is now non-pass for this enrollee.
        $this->assertSame('incomplete', ($e->fresh()->results ?? [])[$ct->id] ?? null);
        $this->assertSame('incomplete', $e->fresh()->status);
    }

    public function test_reason_is_optional(): void
    {
        $org = Organization::factory()->create();
        $manager = $this->manager($org);
        ['class' => $class, 'user' => $user] = $this->reopenedClass($org, $manager);

        $completion = Completion::where('user_id', $user->id)->firstOrFail();

        $this->actingAs($manager)
            ->postJson("/api/classes/{$class->id}/completions/{$completion->id}/revoke")
            ->assertOk();

        $this->assertNull(Completion::withTrashed()->where('user_id', $user->id)->firstOrFail()->revoke_reason);
    }

    /**
     * THE reconciliation test: after a revoke, re-closing the class with the
     * previously-passing marks must NOT resurrect the revoked cert — because
     * the revoke drove the results entry to non-pass.
     */
    public function test_revoke_then_reclose_keeps_the_cert_gone(): void
    {
        $org = Organization::factory()->create();
        $manager = $this->manager($org);
        ['class' => $class, 'ct' => $ct, 'user' => $user, 'e' => $e] = $this->reopenedClass($org, $manager);

        $completion = Completion::where('user_id', $user->id)->firstOrFail();

        $this->actingAs($manager)
            ->postJson("/api/classes/{$class->id}/completions/{$completion->id}/revoke")
            ->assertOk();

        // Re-close sending the ORIGINAL passing mark for this enrollee. Because
        // revoke set the persisted result to non-pass, the request's results
        // reflect the corrected roster (nothing marked) — the cert stays gone.
        $this->actingAs($manager)->postJson("/api/classes/{$class->id}/complete", [
            'completion_date' => '2026-06-01',
            'enrollments' => [
                ['id' => $e->id, 'results' => []],
            ],
        ])->assertOk();

        $this->assertSame(0, Completion::where('user_id', $user->id)->count());
        // The soft-deleted row is untouched by re-close (still just one, trashed).
        $this->assertSame(1, Completion::withTrashed()->where('user_id', $user->id)->count());
    }

    public function test_revoke_does_not_disturb_other_enrollees_certs(): void
    {
        $org = Organization::factory()->create();
        $manager = $this->manager($org);
        $training = Training::factory()->for($org, 'organization')->create();
        $class = TrainingClass::factory()->for($org, 'organization')->create();
        $ct = ClassTraining::factory()->for($class, 'trainingClass')->create(['training_id' => $training->id]);
        $a = User::factory()->for($org, 'organization')->create();
        $b = User::factory()->for($org, 'organization')->create();
        $eA = ClassEnrollment::factory()->for($class, 'trainingClass')->create(['user_id' => $a->id]);
        $eB = ClassEnrollment::factory()->for($class, 'trainingClass')->create(['user_id' => $b->id]);

        $this->actingAs($manager)->postJson("/api/classes/{$class->id}/complete", [
            'completion_date' => '2026-06-01',
            'enrollments' => [
                ['id' => $eA->id, 'results' => [['class_training_id' => $ct->id, 'result' => 'pass']]],
                ['id' => $eB->id, 'results' => [['class_training_id' => $ct->id, 'result' => 'pass']]],
            ],
        ])->assertOk();
        $this->actingAs($manager)->postJson("/api/classes/{$class->id}/reopen")->assertOk();

        $bCert = Completion::where('user_id', $b->id)->firstOrFail()->cert_id;
        $aCompletion = Completion::where('user_id', $a->id)->firstOrFail();

        $this->actingAs($manager)
            ->postJson("/api/classes/{$class->id}/completions/{$aCompletion->id}/revoke")
            ->assertOk();

        $this->assertSame(0, Completion::where('user_id', $a->id)->count());
        $this->assertSame($bCert, Completion::where('user_id', $b->id)->firstOrFail()->cert_id);
    }

    public function test_cannot_revoke_a_completion_from_another_class(): void
    {
        $org = Organization::factory()->create();
        $manager = $this->manager($org);
        ['class' => $class] = $this->reopenedClass($org, $manager);

        // A completion tied to a DIFFERENT class's topic.
        $other = TrainingClass::factory()->for($org, 'organization')->create();
        $otherCt = ClassTraining::factory()->for($other, 'trainingClass')->create();
        $stranger = Completion::factory()->for($org, 'organization')->create([
            'class_training_id' => $otherCt->id,
        ]);

        $this->actingAs($manager)
            ->postJson("/api/classes/{$class->id}/completions/{$stranger->id}/revoke")
            ->assertStatus(404);
    }

    public function test_cannot_revoke_on_a_completed_class(): void
    {
        $org = Organization::factory()->create();
        $manager = $this->manager($org);
        ['class' => $class, 'ct' => $ct, 'user' => $user, 'e' => $e] = $this->reopenedClass($org, $manager);
        $completion = Completion::where('user_id', $user->id)->firstOrFail();

        // Re-close (keeping the pass) so the class is locked again with the cert intact.
        $this->actingAs($manager)->postJson("/api/classes/{$class->id}/complete", [
            'completion_date' => '2026-06-01',
            'enrollments' => [
                ['id' => $e->id, 'results' => [['class_training_id' => $ct->id, 'result' => 'pass']]],
            ],
        ])->assertOk();

        $this->actingAs($manager)
            ->postJson("/api/classes/{$class->id}/completions/{$completion->id}/revoke")
            ->assertStatus(422);
    }

    public function test_non_manager_cannot_revoke(): void
    {
        $org = Organization::factory()->create();
        $manager = $this->manager($org);
        ['class' => $class, 'user' => $user] = $this->reopenedClass($org, $manager);
        $completion = Completion::where('user_id', $user->id)->firstOrFail();

        $none = User::factory()->for($org, 'organization')->withRole('None')->create();

        $this->actingAs($none)
            ->postJson("/api/classes/{$class->id}/completions/{$completion->id}/revoke")
            ->assertForbidden();
    }

    public function test_cannot_revoke_a_completion_from_another_org(): void
    {
        $org = Organization::factory()->create();
        $manager = $this->manager($org);
        ['class' => $class] = $this->reopenedClass($org, $manager);

        $otherOrg = Organization::factory()->create();
        $otherClass = TrainingClass::factory()->for($otherOrg, 'organization')->create();
        $otherCt = ClassTraining::factory()->for($otherClass, 'trainingClass')->create();
        $foreign = Completion::factory()->for($otherOrg, 'organization')->create([
            'class_training_id' => $otherCt->id,
        ]);

        // Cross-org route binding resolves to 404 (defense in depth).
        $this->actingAs($manager)
            ->postJson("/api/classes/{$class->id}/completions/{$foreign->id}/revoke")
            ->assertStatus(404);
    }
}
