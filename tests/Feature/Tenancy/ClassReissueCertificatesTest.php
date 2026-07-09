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
 * Deliberate renumbering: on a re-opened (scheduled) class, "re-issue
 * certificates" NULLs the affected completions' cert_ids so the NEXT re-close
 * re-mints them from the current cert_code/date via the shared close-out path.
 * Scope is the whole class or a single topic. This is the ONLY way an existing
 * number changes — a plain re-open → re-close preserves numbers.
 */
class ClassReissueCertificatesTest extends TestCase
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

    /** Complete a two-topic class with one passed enrollee, then re-open it. */
    private function reopenedClass(Organization $org, User $manager): array
    {
        $class = TrainingClass::factory()->for($org, 'organization')->create();
        $t1 = Training::factory()->for($org, 'organization')->create(['cert_code' => null]);
        $t2 = Training::factory()->for($org, 'organization')->create(['cert_code' => null]);
        $ct1 = ClassTraining::factory()->for($class, 'trainingClass')->create(['training_id' => $t1->id, 'cert_code' => null]);
        $ct2 = ClassTraining::factory()->for($class, 'trainingClass')->create(['training_id' => $t2->id, 'cert_code' => null]);
        $user = User::factory()->for($org, 'organization')->create();
        $e = ClassEnrollment::factory()->for($class, 'trainingClass')->create(['user_id' => $user->id]);

        $this->actingAs($manager)->postJson("/api/classes/{$class->id}/complete", [
            'completion_date' => '2026-06-01',
            'enrollments' => [['id' => $e->id, 'results' => [
                ['class_training_id' => $ct1->id, 'result' => 'pass'],
                ['class_training_id' => $ct2->id, 'result' => 'pass'],
            ]]],
        ])->assertOk();

        $this->actingAs($manager)->postJson("/api/classes/{$class->id}/reopen")->assertOk();

        return compact('class', 'ct1', 'ct2', 'user', 'e');
    }

    private function reclose(TrainingClass $class, ClassEnrollment $e, ClassTraining $ct1, ClassTraining $ct2, User $manager): void
    {
        $this->actingAs($manager)->postJson("/api/classes/{$class->id}/complete", [
            'completion_date' => '2026-06-01',
            'enrollments' => [['id' => $e->id, 'results' => [
                ['class_training_id' => $ct1->id, 'result' => 'pass'],
                ['class_training_id' => $ct2->id, 'result' => 'pass'],
            ]]],
        ])->assertOk();
    }

    public function test_reissue_whole_class_nulls_all_certs_then_reclose_remints(): void
    {
        $org = Organization::factory()->create();
        $manager = $this->manager($org);
        ['class' => $class, 'ct1' => $ct1, 'ct2' => $ct2, 'user' => $user, 'e' => $e] = $this->reopenedClass($org, $manager);

        $before1 = Completion::where('class_training_id', $ct1->id)->firstOrFail()->cert_id;
        $before2 = Completion::where('class_training_id', $ct2->id)->firstOrFail()->cert_id;
        $this->assertNotNull($before1);
        $this->assertNotNull($before2);

        // Re-issue the whole class: both certs are cleared, not re-minted yet.
        $this->actingAs($manager)
            ->postJson("/api/classes/{$class->id}/reissue-certificates")
            ->assertOk()
            ->assertJsonPath('status', 'scheduled');

        $this->assertNull(Completion::where('class_training_id', $ct1->id)->firstOrFail()->cert_id);
        $this->assertNull(Completion::where('class_training_id', $ct2->id)->firstOrFail()->cert_id);

        // The next re-close re-mints both via the close-out path.
        $this->reclose($class, $e, $ct1, $ct2, $manager);

        $this->assertNotNull(Completion::where('class_training_id', $ct1->id)->firstOrFail()->cert_id);
        $this->assertNotNull(Completion::where('class_training_id', $ct2->id)->firstOrFail()->cert_id);
    }

    public function test_reissue_single_topic_scopes_to_that_training(): void
    {
        $org = Organization::factory()->create();
        $manager = $this->manager($org);
        ['class' => $class, 'ct1' => $ct1, 'ct2' => $ct2] = $this->reopenedClass($org, $manager);

        $keep = Completion::where('class_training_id', $ct2->id)->firstOrFail()->cert_id;

        $this->actingAs($manager)
            ->postJson("/api/classes/{$class->id}/reissue-certificates", [
                'class_training_id' => $ct1->id,
            ])
            ->assertOk();

        // Only the targeted topic's cert is cleared; the other keeps its number.
        $this->assertNull(Completion::where('class_training_id', $ct1->id)->firstOrFail()->cert_id);
        $this->assertSame($keep, Completion::where('class_training_id', $ct2->id)->firstOrFail()->cert_id);
    }

    public function test_reissue_then_reclose_applies_a_corrected_cert_code(): void
    {
        $org = Organization::factory()->create();
        $manager = $this->manager($org);
        ['class' => $class, 'ct1' => $ct1, 'ct2' => $ct2, 'e' => $e] = $this->reopenedClass($org, $manager);

        // Correct the code on topic 1, re-issue just that topic, re-close.
        $this->actingAs($manager)
            ->patchJson("/api/classes/{$class->id}/trainings/{$ct1->id}", ['cert_code' => 'FPCP'])
            ->assertOk();
        $this->actingAs($manager)
            ->postJson("/api/classes/{$class->id}/reissue-certificates", ['class_training_id' => $ct1->id])
            ->assertOk();
        $this->reclose($class, $e, $ct1, $ct2, $manager);

        // The re-minted number carries the corrected prefix.
        $this->assertStringStartsWith('FPCP20260601-', Completion::where('class_training_id', $ct1->id)->firstOrFail()->cert_id);
    }

    public function test_reissue_returns_refreshed_detail(): void
    {
        $org = Organization::factory()->create();
        $manager = $this->manager($org);
        ['class' => $class] = $this->reopenedClass($org, $manager);

        $this->actingAs($manager)
            ->postJson("/api/classes/{$class->id}/reissue-certificates")
            ->assertOk()
            ->assertJsonPath('id', $class->id)
            ->assertJsonStructure(['trainings', 'enrollments']);
    }

    public function test_class_training_id_must_belong_to_this_class(): void
    {
        $org = Organization::factory()->create();
        $manager = $this->manager($org);
        ['class' => $class] = $this->reopenedClass($org, $manager);

        // A class_training row from a DIFFERENT class is rejected.
        $other = TrainingClass::factory()->for($org, 'organization')->create();
        $foreignCt = ClassTraining::factory()->for($other, 'trainingClass')->create();

        $this->actingAs($manager)
            ->postJson("/api/classes/{$class->id}/reissue-certificates", [
                'class_training_id' => $foreignCt->id,
            ])
            ->assertStatus(422);
    }

    public function test_reissue_is_a_safe_noop_on_a_never_completed_class(): void
    {
        $org = Organization::factory()->create();
        $manager = $this->manager($org);
        $class = TrainingClass::factory()->for($org, 'organization')->create(['status' => 'scheduled']);

        $this->actingAs($manager)
            ->postJson("/api/classes/{$class->id}/reissue-certificates")
            ->assertOk();
    }

    public function test_cannot_reissue_on_a_completed_class(): void
    {
        $org = Organization::factory()->create();
        $manager = $this->manager($org);
        $class = TrainingClass::factory()->for($org, 'organization')->create(['status' => 'completed']);

        $this->actingAs($manager)
            ->postJson("/api/classes/{$class->id}/reissue-certificates")
            ->assertStatus(422);
    }

    public function test_non_manager_cannot_reissue(): void
    {
        $org = Organization::factory()->create();
        $none = User::factory()->for($org, 'organization')->withRole('None')->create();
        $class = TrainingClass::factory()->for($org, 'organization')->create(['status' => 'scheduled']);

        $this->actingAs($none)
            ->postJson("/api/classes/{$class->id}/reissue-certificates")
            ->assertForbidden();
    }
}
