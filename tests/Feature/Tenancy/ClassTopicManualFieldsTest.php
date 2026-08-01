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
 * The per-topic fields a class manager may need to correct by hand before
 * close-out — the expiry above all — plus the class's own completion date.
 *
 * Expiry is normally derived (completion date + the topic's frozen repeat
 * interval), but a third-party card or a regulator's fixed date overrides
 * that, and there was previously nowhere to say so: the column existed, the
 * close-out endpoint accepted an override, and no read or write path exposed
 * either one.
 */
class ClassTopicManualFieldsTest extends TestCase
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

    /** A scheduled class with one repeating topic. */
    private function scheduledClass(Organization $org): array
    {
        $training = Training::factory()->for($org, 'organization')->create();
        $class = TrainingClass::factory()->for($org, 'organization')->create([
            'status' => 'scheduled',
            'scheduled_date' => '2026-06-01',
            'completion_date' => null,
        ]);
        $ct = ClassTraining::factory()->for($class, 'trainingClass')->create([
            'training_id' => $training->id,
            'hours' => 4,
            'repeating' => true,
            'repeat_days' => 365,
            'initial_only' => false,
            'as_needed' => false,
            'cert_title' => 'Fall Protection',
        ]);

        return compact('training', 'class', 'ct');
    }

    public function test_detail_exposes_a_topics_expiry(): void
    {
        $org = Organization::factory()->create();
        $manager = $this->manager($org);
        ['class' => $class, 'ct' => $ct] = $this->scheduledClass($org);
        $ct->update(['expire_date' => '2027-06-01']);

        $this->actingAs($manager)
            ->getJson("/api/classes/{$class->id}")
            ->assertOk()
            ->assertJsonPath('trainings.0.expire_date', '2027-06-01');
    }

    public function test_a_topics_expiry_can_be_set_by_hand(): void
    {
        $org = Organization::factory()->create();
        $manager = $this->manager($org);
        ['class' => $class, 'ct' => $ct] = $this->scheduledClass($org);

        $this->actingAs($manager)
            ->patchJson("/api/classes/{$class->id}/trainings/{$ct->id}", [
                'expire_date' => '2029-07-15',
            ])
            ->assertOk()
            ->assertJsonPath('trainings.0.expire_date', '2029-07-15');

        $this->assertSame('2029-07-15', $ct->fresh()->expire_date->toDateString());
    }

    public function test_a_topics_expiry_can_be_cleared_back_to_derived(): void
    {
        $org = Organization::factory()->create();
        $manager = $this->manager($org);
        ['class' => $class, 'ct' => $ct] = $this->scheduledClass($org);
        $ct->update(['expire_date' => '2027-06-01']);

        // Blanking the field means "go back to whatever the frequency says",
        // which close-out then recomputes — not "never expires".
        $this->actingAs($manager)
            ->patchJson("/api/classes/{$class->id}/trainings/{$ct->id}", [
                'expire_date' => null,
            ])
            ->assertOk();

        $this->assertNull($ct->fresh()->expire_date);
    }

    public function test_setting_an_expiry_leaves_the_topics_other_fields_alone(): void
    {
        $org = Organization::factory()->create();
        $manager = $this->manager($org);
        ['class' => $class, 'ct' => $ct] = $this->scheduledClass($org);

        $this->actingAs($manager)
            ->patchJson("/api/classes/{$class->id}/trainings/{$ct->id}", [
                'expire_date' => '2029-07-15',
            ])
            ->assertOk();

        // The endpoint's contract is "only touch what was sent".
        $fresh = $ct->fresh();
        $this->assertSame('4.00', (string) $fresh->hours);
        $this->assertSame('Fall Protection', $fresh->cert_title);
    }

    public function test_a_completed_class_refuses_a_topic_expiry_edit(): void
    {
        $org = Organization::factory()->create();
        $manager = $this->manager($org);
        ['class' => $class, 'ct' => $ct] = $this->scheduledClass($org);
        $class->update(['status' => 'completed']);

        $this->actingAs($manager)
            ->patchJson("/api/classes/{$class->id}/trainings/{$ct->id}", [
                'expire_date' => '2029-07-15',
            ])
            ->assertStatus(422);

        $this->assertNull($ct->fresh()->expire_date);
    }

    public function test_a_completion_date_can_be_recorded_before_close_out(): void
    {
        $org = Organization::factory()->create();
        $manager = $this->manager($org);
        ['class' => $class] = $this->scheduledClass($org);

        // A multi-day class finishes on a different day than it starts, and
        // that date is known well before anyone closes the class out.
        $this->actingAs($manager)
            ->patchJson("/api/classes/{$class->id}", [
                'name' => $class->name,
                'scheduled_date' => '2026-06-01',
                'completion_date' => '2026-06-03',
            ])
            ->assertOk()
            ->assertJsonPath('completion_date', '2026-06-03');
    }

    /**
     * The reason this needed care at all: `completion_date` was doubling as
     * the "this class has been closed before" flag, so letting it be typed in
     * advance would have handed a never-completed class the re-close and
     * certificate-renumbering paths, both of which assume issued credit.
     */
    public function test_a_pre_entered_completion_date_does_not_make_a_class_recloseable(): void
    {
        $org = Organization::factory()->create();
        $manager = $this->manager($org);
        ['class' => $class] = $this->scheduledClass($org);

        $this->actingAs($manager)
            ->patchJson("/api/classes/{$class->id}", [
                'name' => $class->name,
                'scheduled_date' => '2026-06-01',
                'completion_date' => '2026-06-03',
            ])
            ->assertOk();

        $this->actingAs($manager)
            ->postJson("/api/classes/{$class->id}/reclose")
            ->assertStatus(422)
            ->assertJsonPath('message', "This class hasn't been completed yet — use Complete.");
    }

    public function test_detail_reports_whether_a_class_was_ever_completed(): void
    {
        $org = Organization::factory()->create();
        $manager = $this->manager($org);
        ['class' => $class, 'ct' => $ct] = $this->scheduledClass($org);
        $user = User::factory()->for($org, 'organization')->create();
        $enrollment = ClassEnrollment::factory()->for($class, 'trainingClass')->create(['user_id' => $user->id]);

        $this->actingAs($manager)
            ->getJson("/api/classes/{$class->id}")
            ->assertOk()
            ->assertJsonPath('was_completed', false);

        $this->actingAs($manager)
            ->postJson("/api/classes/{$class->id}/complete", [
                'completion_date' => '2026-06-01',
                'enrollments' => [
                    ['id' => $enrollment->id, 'results' => [['class_training_id' => $ct->id, 'result' => 'pass']]],
                ],
            ])
            ->assertOk();

        $this->actingAs($manager)->postJson("/api/classes/{$class->id}/reopen")->assertOk();

        // Re-opened, so editable again — but it has been closed before, and
        // the UI keys re-close and re-issue off exactly this.
        $this->actingAs($manager)
            ->getJson("/api/classes/{$class->id}")
            ->assertOk()
            ->assertJsonPath('status', 'scheduled')
            ->assertJsonPath('was_completed', true);
    }

    public function test_close_out_keeps_an_expiry_the_manager_confirmed(): void
    {
        $org = Organization::factory()->create();
        $manager = $this->manager($org);
        ['class' => $class, 'ct' => $ct] = $this->scheduledClass($org);
        $user = User::factory()->for($org, 'organization')->create();
        $enrollment = ClassEnrollment::factory()->for($class, 'trainingClass')->create(['user_id' => $user->id]);

        $this->actingAs($manager)
            ->postJson("/api/classes/{$class->id}/complete", [
                'completion_date' => '2026-06-01',
                'enrollments' => [
                    ['id' => $enrollment->id, 'results' => [['class_training_id' => $ct->id, 'result' => 'pass']]],
                ],
                'trainings' => [
                    ['id' => $ct->id, 'expire_date' => '2029-07-15'],
                ],
            ])
            ->assertOk();

        // Derived would have been 2027-06-01 (+365d); the confirmed date wins,
        // on the topic and on every certificate it issues.
        $this->assertSame('2029-07-15', $ct->fresh()->expire_date->toDateString());

        $completion = Completion::where('class_training_id', $ct->id)
            ->where('user_id', $user->id)
            ->firstOrFail();
        $this->assertSame('2029-07-15', $completion->expire_date->toDateString());
    }
}
