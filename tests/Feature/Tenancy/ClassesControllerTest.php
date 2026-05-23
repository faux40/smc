<?php

namespace Tests\Feature\Tenancy;

use App\Models\ClassEnrollment;
use App\Models\ClassTraining;
use App\Models\Organization;
use App\Models\Training;
use App\Models\TrainingClass;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClassesControllerTest extends TestCase
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

    public function test_manager_can_create_a_class(): void
    {
        $org = Organization::factory()->create();
        $manager = $this->manager($org);

        $this->actingAs($manager)
            ->postJson('/api/classes', [
                'name' => 'Fall Protection — Spring',
                'scheduled_date' => '2026-06-01',
                'location' => 'Yard 3',
                'total_hours' => 4,
            ])
            ->assertCreated()
            ->assertJsonPath('name', 'Fall Protection — Spring')
            ->assertJsonPath('status', 'scheduled');

        $this->assertDatabaseHas('classes', ['org_id' => $org->id, 'name' => 'Fall Protection — Spring']);
    }

    public function test_create_with_training_ids_snapshots_each_training(): void
    {
        $org = Organization::factory()->create();
        $manager = $this->manager($org);
        $a = Training::factory()->for($org, 'organization')->create(['name' => 'Fall Protection']);
        $b = Training::factory()->for($org, 'organization')->create(['name' => 'First Aid']);

        $this->actingAs($manager)
            ->postJson('/api/classes', [
                'name' => 'Combined Class',
                'scheduled_date' => '2026-06-01',
                'training_ids' => [$a->id, $b->id],
            ])
            ->assertCreated()
            ->assertJsonCount(2, 'trainings');

        $class = TrainingClass::where('org_id', $org->id)->firstOrFail();
        $names = ClassTraining::where('class_id', $class->id)->pluck('training_name')->all();
        $this->assertEqualsCanonicalizing(['Fall Protection', 'First Aid'], $names);
    }

    public function test_create_rejects_a_cross_org_training_id(): void
    {
        $org = Organization::factory()->create();
        $otherOrg = Organization::factory()->create();
        $manager = $this->manager($org);
        $foreign = Training::factory()->for($otherOrg, 'organization')->create();

        $this->actingAs($manager)
            ->postJson('/api/classes', [
                'name' => 'x',
                'scheduled_date' => '2026-06-01',
                'training_ids' => [$foreign->id],
            ])
            ->assertStatus(422);

        $this->assertDatabaseMissing('classes', ['name' => 'x']);
    }

    public function test_non_manager_cannot_create_a_class(): void
    {
        $org = Organization::factory()->create();
        $none = User::factory()->for($org, 'organization')->withRole('None')->create();

        $this->actingAs($none)
            ->postJson('/api/classes', ['name' => 'x', 'scheduled_date' => '2026-06-01'])
            ->assertForbidden();
    }

    public function test_index_is_org_scoped(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $managerA = $this->manager($orgA);
        TrainingClass::factory()->for($orgA, 'organization')->create();
        TrainingClass::factory()->for($orgB, 'organization')->count(2)->create();

        $this->actingAs($managerA)->getJson('/api/classes')->assertOk()->assertJsonCount(1);
    }

    public function test_attaching_a_training_snapshots_its_fields(): void
    {
        $org = Organization::factory()->create();
        $manager = $this->manager($org);
        $class = TrainingClass::factory()->for($org, 'organization')->create();
        $training = Training::factory()->for($org, 'organization')->create(['name' => 'Original Name']);

        $this->actingAs($manager)
            ->postJson("/api/classes/{$class->id}/trainings", ['training_id' => $training->id, 'hours' => 2.5])
            ->assertOk()
            ->assertJsonPath('trainings.0.training_name', 'Original Name');

        // Renaming the training must not rewrite the class's stored snapshot.
        $training->update(['name' => 'Renamed Later']);

        $snapshot = ClassTraining::where('class_id', $class->id)->firstOrFail();
        $this->assertSame('Original Name', $snapshot->training_name);
        $this->assertSame('2.50', (string) $snapshot->hours);
    }

    public function test_attaching_defaults_hours_from_the_trainings_default_hours(): void
    {
        $org = Organization::factory()->create();
        $manager = $this->manager($org);
        $class = TrainingClass::factory()->for($org, 'organization')->create();
        $training = Training::factory()->for($org, 'organization')->create(['default_hours' => 3.5]);

        // No hours sent → should fall back to the training's default_hours.
        $this->actingAs($manager)
            ->postJson("/api/classes/{$class->id}/trainings", ['training_id' => $training->id])
            ->assertOk()
            ->assertJsonPath('trainings.0.hours', '3.50');
    }

    public function test_can_update_an_attached_trainings_hours(): void
    {
        $org = Organization::factory()->create();
        $manager = $this->manager($org);
        $class = TrainingClass::factory()->for($org, 'organization')->create();
        $ct = ClassTraining::factory()->for($class, 'trainingClass')->create(['hours' => 2]);

        $this->actingAs($manager)
            ->patchJson("/api/classes/{$class->id}/trainings/{$ct->id}", ['hours' => 5.25])
            ->assertOk()
            ->assertJsonPath('trainings.0.hours', '5.25');

        $this->assertSame('5.25', (string) $ct->fresh()->hours);
    }

    public function test_cannot_attach_a_cross_org_training(): void
    {
        $org = Organization::factory()->create();
        $otherOrg = Organization::factory()->create();
        $manager = $this->manager($org);
        $class = TrainingClass::factory()->for($org, 'organization')->create();
        $foreignTraining = Training::factory()->for($otherOrg, 'organization')->create();

        $this->actingAs($manager)
            ->postJson("/api/classes/{$class->id}/trainings", ['training_id' => $foreignTraining->id])
            ->assertStatus(422);
    }

    public function test_enroll_adds_a_user_and_rejects_duplicates(): void
    {
        $org = Organization::factory()->create();
        $manager = $this->manager($org);
        $class = TrainingClass::factory()->for($org, 'organization')->create();
        $student = User::factory()->for($org, 'organization')->create();

        $this->actingAs($manager)
            ->postJson("/api/classes/{$class->id}/enrollments", ['user_id' => $student->id])
            ->assertOk()
            ->assertJsonPath('enrollments.0.user_id', $student->id);

        // Duplicate enrollment rejected.
        $this->actingAs($manager)
            ->postJson("/api/classes/{$class->id}/enrollments", ['user_id' => $student->id])
            ->assertStatus(422);
    }

    public function test_unenroll_and_detach_remove_rows(): void
    {
        $org = Organization::factory()->create();
        $manager = $this->manager($org);
        $class = TrainingClass::factory()->for($org, 'organization')->create();
        $enrollment = ClassEnrollment::factory()->for($class, 'trainingClass')
            ->create(['user_id' => User::factory()->for($org, 'organization')]);
        $ct = ClassTraining::factory()->for($class, 'trainingClass')->create();

        $this->actingAs($manager)
            ->deleteJson("/api/classes/{$class->id}/enrollments/{$enrollment->id}")
            ->assertOk();
        $this->assertDatabaseMissing('class_enrollments', ['id' => $enrollment->id]);

        $this->actingAs($manager)
            ->deleteJson("/api/classes/{$class->id}/trainings/{$ct->id}")
            ->assertOk();
        $this->assertDatabaseMissing('class_training', ['id' => $ct->id]);
    }

    public function test_cross_org_class_is_not_found(): void
    {
        $org = Organization::factory()->create();
        $otherOrg = Organization::factory()->create();
        $manager = $this->manager($org);
        $foreignClass = TrainingClass::factory()->for($otherOrg, 'organization')->create();

        $this->actingAs($manager)
            ->getJson("/api/classes/{$foreignClass->id}")
            ->assertNotFound();
    }
}
