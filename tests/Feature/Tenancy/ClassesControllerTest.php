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

    public function test_create_persists_times_address_and_signature_flag(): void
    {
        $org = Organization::factory()->create();
        $manager = $this->manager($org);

        $this->actingAs($manager)
            ->postJson('/api/classes', [
                'name' => 'Confined Space',
                'scheduled_date' => '2026-06-01',
                'start_time' => '08:00',
                'end_time' => '12:30',
                'location' => 'Training Room A',
                'address' => "450 Ryder St\nVallejo, CA",
                'show_signature' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('start_time', '08:00')
            ->assertJsonPath('end_time', '12:30')
            ->assertJsonPath('address', "450 Ryder St\nVallejo, CA")
            ->assertJsonPath('show_signature', true);

        $this->assertDatabaseHas('classes', [
            'org_id' => $org->id,
            'name' => 'Confined Space',
            'start_time' => '08:00',
            'end_time' => '12:30',
            'show_signature' => true,
        ]);
    }

    public function test_create_rejects_a_malformed_time(): void
    {
        $org = Organization::factory()->create();
        $manager = $this->manager($org);

        $this->actingAs($manager)
            ->postJson('/api/classes', [
                'name' => 'Bad Time',
                'scheduled_date' => '2026-06-01',
                'start_time' => '8am',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('start_time');
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

        $this->actingAs($managerA)->getJson('/api/classes')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_index_filters_by_training_and_status_for_the_add_to_class_picker(): void
    {
        $org = Organization::factory()->create();
        $manager = $this->manager($org);
        $training = Training::factory()->for($org, 'organization')->create();

        // A scheduled class that includes the training → eligible.
        $eligible = TrainingClass::factory()->for($org, 'organization')->create(['status' => 'scheduled']);
        ClassTraining::factory()->for($eligible, 'trainingClass')->create(['training_id' => $training->id]);
        // A completed class with the training → excluded by status.
        $done = TrainingClass::factory()->for($org, 'organization')->create(['status' => 'completed']);
        ClassTraining::factory()->for($done, 'trainingClass')->create(['training_id' => $training->id]);
        // A scheduled class WITHOUT the training → excluded by training filter.
        TrainingClass::factory()->for($org, 'organization')->create(['status' => 'scheduled']);

        $rows = $this->actingAs($manager)
            ->getJson("/api/classes?training_id={$training->id}&status=scheduled")
            ->assertOk()
            ->json('data');

        $this->assertSame([$eligible->id], collect($rows)->pluck('id')->all());
    }

    public function test_index_paginated_returns_data_and_meta(): void
    {
        $org = Organization::factory()->create();
        $manager = $this->manager($org);
        TrainingClass::factory()->for($org, 'organization')->count(7)->create();

        $res = $this->actingAs($manager)
            ->getJson('/api/classes?page=1&per_page=3')
            ->assertOk();

        $res->assertJsonCount(3, 'data');
        $res->assertJsonPath('meta.total', 7);
        $res->assertJsonPath('meta.last_page', 3);
        $res->assertJsonPath('meta.current_page', 1);
    }

    public function test_index_paginated_q_filters_by_name(): void
    {
        $org = Organization::factory()->create();
        $manager = $this->manager($org);
        TrainingClass::factory()->for($org, 'organization')->create(['name' => 'Forklift Refresher']);
        TrainingClass::factory()->for($org, 'organization')->create(['name' => 'Fall Protection']);

        $res = $this->actingAs($manager)
            ->getJson('/api/classes?page=1&q=forklift')
            ->assertOk();

        $res->assertJsonPath('meta.total', 1);
        $res->assertJsonPath('data.0.name', 'Forklift Refresher');
    }

    public function test_index_always_returns_the_paged_envelope(): void
    {
        $org = Organization::factory()->create();
        $manager = $this->manager($org);
        TrainingClass::factory()->for($org, 'organization')->count(2)->create();

        // The flat-array contract is gone — every list response is {data, meta}.
        $this->actingAs($manager)->getJson('/api/classes')
            ->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.total', 2);
    }

    // ------------------------------------------------------------------
    // Sort coverage for all header-clickable columns
    // ------------------------------------------------------------------

    public function test_sort_by_instructor_orders_alphabetically(): void
    {
        $org = Organization::factory()->create();
        $manager = $this->manager($org);
        TrainingClass::factory()->for($org, 'organization')->create(['instructor' => 'Zoe Young']);
        TrainingClass::factory()->for($org, 'organization')->create(['instructor' => 'Ada Xu']);

        $asc = $this->actingAs($manager)
            ->getJson('/api/classes?sort=instructor&dir=asc')
            ->assertOk();
        $this->assertSame('Ada Xu', $asc->json('data.0.instructor'));

        $desc = $this->actingAs($manager)
            ->getJson('/api/classes?sort=instructor&dir=desc')
            ->assertOk();
        $this->assertSame('Zoe Young', $desc->json('data.0.instructor'));
    }

    public function test_sort_by_location_orders_alphabetically(): void
    {
        $org = Organization::factory()->create();
        $manager = $this->manager($org);
        TrainingClass::factory()->for($org, 'organization')->create(['location' => 'Yard 3']);
        TrainingClass::factory()->for($org, 'organization')->create(['location' => 'Main Hall']);

        $asc = $this->actingAs($manager)
            ->getJson('/api/classes?sort=location&dir=asc')
            ->assertOk();
        $this->assertSame('Main Hall', $asc->json('data.0.location'));

        $desc = $this->actingAs($manager)
            ->getJson('/api/classes?sort=location&dir=desc')
            ->assertOk();
        $this->assertSame('Yard 3', $desc->json('data.0.location'));
    }

    public function test_sort_by_trainings_count_orders_by_count(): void
    {
        $org = Organization::factory()->create();
        $manager = $this->manager($org);
        $few = TrainingClass::factory()->for($org, 'organization')->create(['name' => 'Few']);
        $many = TrainingClass::factory()->for($org, 'organization')->create(['name' => 'Many']);
        // Attach 2 topics to $many, 0 to $few.
        ClassTraining::factory()->for($many, 'trainingClass')->count(2)->create();

        $asc = $this->actingAs($manager)
            ->getJson('/api/classes?sort=class_trainings_count&dir=asc')
            ->assertOk();
        $this->assertSame('Few', $asc->json('data.0.name'));
        $this->assertSame('Many', $asc->json('data.1.name'));

        $desc = $this->actingAs($manager)
            ->getJson('/api/classes?sort=class_trainings_count&dir=desc')
            ->assertOk();
        $this->assertSame('Many', $desc->json('data.0.name'));
    }

    public function test_sort_by_enrollments_count_orders_by_count(): void
    {
        $org = Organization::factory()->create();
        $manager = $this->manager($org);
        $few = TrainingClass::factory()->for($org, 'organization')->create(['name' => 'Few']);
        $many = TrainingClass::factory()->for($org, 'organization')->create(['name' => 'Many']);
        // Enroll 3 users in $many, 0 in $few.
        ClassEnrollment::factory()->for($many, 'trainingClass')->count(3)->create();

        $asc = $this->actingAs($manager)
            ->getJson('/api/classes?sort=enrollments_count&dir=asc')
            ->assertOk();
        $this->assertSame('Few', $asc->json('data.0.name'));
        $this->assertSame('Many', $asc->json('data.1.name'));

        $desc = $this->actingAs($manager)
            ->getJson('/api/classes?sort=enrollments_count&dir=desc')
            ->assertOk();
        $this->assertSame('Many', $desc->json('data.0.name'));
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

    public function test_class_total_hours_auto_sums_the_topic_hours(): void
    {
        $org = Organization::factory()->create();
        $manager = $this->manager($org);
        $class = TrainingClass::factory()->for($org, 'organization')->create(['total_hours' => null]);
        $a = Training::factory()->for($org, 'organization')->create(['default_hours' => 2]);
        $b = Training::factory()->for($org, 'organization')->create(['default_hours' => 3]);

        $this->actingAs($manager)
            ->postJson("/api/classes/{$class->id}/trainings", ['training_id' => $a->id])
            ->assertOk();
        $this->actingAs($manager)
            ->postJson("/api/classes/{$class->id}/trainings", ['training_id' => $b->id])
            ->assertOk()
            ->assertJsonPath('total_hours', '5.00'); // 2 + 3

        $ctA = ClassTraining::where('class_id', $class->id)->where('training_id', $a->id)->firstOrFail();

        // Editing a topic's hours re-sums the class total.
        $this->actingAs($manager)
            ->patchJson("/api/classes/{$class->id}/trainings/{$ctA->id}", ['hours' => 4])
            ->assertOk()
            ->assertJsonPath('total_hours', '7.00'); // 4 + 3

        // Detaching a topic re-sums too.
        $this->actingAs($manager)
            ->deleteJson("/api/classes/{$class->id}/trainings/{$ctA->id}")
            ->assertOk()
            ->assertJsonPath('total_hours', '3.00');
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

    public function test_can_edit_an_attached_trainings_cert_fields(): void
    {
        $org = Organization::factory()->create();
        $manager = $this->manager($org);
        $class = TrainingClass::factory()->for($org, 'organization')->create();
        $ct = ClassTraining::factory()->for($class, 'trainingClass')->create([
            'cert_title' => 'Snapshotted Title',
            'cert_text' => 'Snapshotted text',
            'cert_code' => 'OLD',
        ]);

        $this->actingAs($manager)
            ->patchJson("/api/classes/{$class->id}/trainings/{$ct->id}", [
                'cert_title' => 'Per-class Title',
                'cert_text' => "Edited for **this** class\n\nSecond line",
                'cert_code' => 'NEW',
            ])
            ->assertOk()
            ->assertJsonPath('trainings.0.cert_title', 'Per-class Title')
            ->assertJsonPath('trainings.0.cert_code', 'NEW');

        $fresh = $ct->fresh();
        $this->assertSame('Per-class Title', $fresh->cert_title);
        $this->assertSame("Edited for **this** class\n\nSecond line", $fresh->cert_text);
        $this->assertSame('NEW', $fresh->cert_code);
    }

    public function test_editing_cert_fields_is_blocked_on_a_completed_class(): void
    {
        $org = Organization::factory()->create();
        $manager = $this->manager($org);
        $class = TrainingClass::factory()->for($org, 'organization')->create(['status' => 'completed']);
        $ct = ClassTraining::factory()->for($class, 'trainingClass')->create(['cert_title' => 'Locked']);

        $this->actingAs($manager)
            ->patchJson("/api/classes/{$class->id}/trainings/{$ct->id}", ['cert_title' => 'Nope'])
            ->assertStatus(422);

        $this->assertSame('Locked', $ct->fresh()->cert_title);
    }

    public function test_attaching_snapshots_cert_fields_and_prefills_class_venue(): void
    {
        $org = Organization::factory()->create();
        $manager = $this->manager($org);
        $class = TrainingClass::factory()->for($org, 'organization')->create([
            'instructor' => null,
            'location' => null,
            'address' => null,
        ]);
        $training = Training::factory()->for($org, 'organization')->create([
            'cert_title' => 'FP Authorized',
            'cert_text' => 'Satisfies **Cal/OSHA**',
            'cert_code' => 'FPAP',
            'default_trainer' => 'John B',
            'default_location' => 'Room A',
            'default_address' => '450 Ryder St',
        ]);

        $this->actingAs($manager)
            ->postJson("/api/classes/{$class->id}/trainings", ['training_id' => $training->id])
            ->assertOk();

        // Cert content is snapshotted onto the class_training row.
        $snap = ClassTraining::where('class_id', $class->id)->firstOrFail();
        $this->assertSame('FP Authorized', $snap->cert_title);
        $this->assertSame('Satisfies **Cal/OSHA**', $snap->cert_text);
        $this->assertSame('FPAP', $snap->cert_code);

        // Empty class venue fields are pre-filled from the training defaults.
        $class->refresh();
        $this->assertSame('John B', $class->instructor);
        $this->assertSame('Room A', $class->location);
        $this->assertSame('450 Ryder St', $class->address);
    }

    public function test_attaching_does_not_overwrite_existing_class_venue(): void
    {
        $org = Organization::factory()->create();
        $manager = $this->manager($org);
        $class = TrainingClass::factory()->for($org, 'organization')->create([
            'instructor' => 'Set Already',
        ]);
        $training = Training::factory()->for($org, 'organization')->create([
            'default_trainer' => 'Default Trainer',
        ]);

        $this->actingAs($manager)
            ->postJson("/api/classes/{$class->id}/trainings", ['training_id' => $training->id])
            ->assertOk();

        $this->assertSame('Set Already', $class->refresh()->instructor);
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

    public function test_bulk_enrollment_adds_and_removes_in_one_request(): void
    {
        $org = Organization::factory()->create();
        $manager = $this->manager($org);
        $class = TrainingClass::factory()->for($org, 'organization')->create();
        $keep = User::factory()->for($org, 'organization')->create();
        $drop = User::factory()->for($org, 'organization')->create();
        $addA = User::factory()->for($org, 'organization')->create();
        $addB = User::factory()->for($org, 'organization')->create();

        // Seed an existing roster: keep + drop.
        $keepEnrollment = ClassEnrollment::factory()->for($class, 'trainingClass')->create(['user_id' => $keep->id]);
        $dropEnrollment = ClassEnrollment::factory()->for($class, 'trainingClass')->create(['user_id' => $drop->id]);

        $this->actingAs($manager)
            ->postJson("/api/classes/{$class->id}/enrollments/bulk", [
                'enroll' => [$addA->id, $addB->id],
                'unenroll' => [$dropEnrollment->id],
            ])
            ->assertOk()
            ->assertJsonCount(3, 'enrollments');

        $this->assertDatabaseHas('class_enrollments', ['class_id' => $class->id, 'user_id' => $addA->id]);
        $this->assertDatabaseHas('class_enrollments', ['class_id' => $class->id, 'user_id' => $addB->id]);
        $this->assertDatabaseHas('class_enrollments', ['id' => $keepEnrollment->id]);
        $this->assertDatabaseMissing('class_enrollments', ['id' => $dropEnrollment->id]);
    }

    public function test_bulk_enroll_is_idempotent_for_already_enrolled_users(): void
    {
        $org = Organization::factory()->create();
        $manager = $this->manager($org);
        $class = TrainingClass::factory()->for($org, 'organization')->create();
        $student = User::factory()->for($org, 'organization')->create();
        ClassEnrollment::factory()->for($class, 'trainingClass')->create(['user_id' => $student->id]);

        // Re-enrolling an already-enrolled user is a no-op, not a 422.
        $this->actingAs($manager)
            ->postJson("/api/classes/{$class->id}/enrollments/bulk", [
                'enroll' => [$student->id],
                'unenroll' => [],
            ])
            ->assertOk()
            ->assertJsonCount(1, 'enrollments');

        $this->assertSame(1, ClassEnrollment::where('class_id', $class->id)->where('user_id', $student->id)->count());
    }

    public function test_bulk_unenroll_deissues_that_users_certs(): void
    {
        $org = Organization::factory()->create();
        $manager = $this->manager($org);
        $class = TrainingClass::factory()->for($org, 'organization')->create();
        $student = User::factory()->for($org, 'organization')->create();
        $enrollment = ClassEnrollment::factory()->for($class, 'trainingClass')->create(['user_id' => $student->id]);
        $ct = ClassTraining::factory()->for($class, 'trainingClass')->create();
        $completion = Completion::factory()
            ->for($org, 'organization')
            ->for($student, 'user')
            ->create(['class_training_id' => $ct->id]);

        $this->actingAs($manager)
            ->postJson("/api/classes/{$class->id}/enrollments/bulk", [
                'enroll' => [],
                'unenroll' => [$enrollment->id],
            ])
            ->assertOk();

        $this->assertDatabaseMissing('class_enrollments', ['id' => $enrollment->id]);
        $this->assertSoftDeleted('completions', ['id' => $completion->id]);
    }

    public function test_bulk_enrollment_rejects_cross_org_user(): void
    {
        $org = Organization::factory()->create();
        $other = Organization::factory()->create();
        $manager = $this->manager($org);
        $class = TrainingClass::factory()->for($org, 'organization')->create();
        $outsider = User::factory()->for($other, 'organization')->create();

        $this->actingAs($manager)
            ->postJson("/api/classes/{$class->id}/enrollments/bulk", [
                'enroll' => [$outsider->id],
                'unenroll' => [],
            ])
            ->assertStatus(422);
    }

    public function test_bulk_unenroll_rejects_enrollment_from_another_class(): void
    {
        $org = Organization::factory()->create();
        $manager = $this->manager($org);
        $class = TrainingClass::factory()->for($org, 'organization')->create();
        $otherClass = TrainingClass::factory()->for($org, 'organization')->create();
        $foreign = ClassEnrollment::factory()->for($otherClass, 'trainingClass')
            ->create(['user_id' => User::factory()->for($org, 'organization')]);

        $this->actingAs($manager)
            ->postJson("/api/classes/{$class->id}/enrollments/bulk", [
                'enroll' => [],
                'unenroll' => [$foreign->id],
            ])
            ->assertStatus(422);

        $this->assertDatabaseHas('class_enrollments', ['id' => $foreign->id]);
    }

    public function test_bulk_full_clear_of_multi_person_roster_requires_confirmation(): void
    {
        $org = Organization::factory()->create();
        $manager = $this->manager($org);
        $class = TrainingClass::factory()->for($org, 'organization')->create();
        $a = ClassEnrollment::factory()->for($class, 'trainingClass')->create(['user_id' => User::factory()->for($org, 'organization')]);
        $b = ClassEnrollment::factory()->for($class, 'trainingClass')->create(['user_id' => User::factory()->for($org, 'organization')]);

        // Wiping an entire multi-person roster with no additions and no
        // confirm_clear is treated as an accidental mass de-enroll and rejected.
        $this->actingAs($manager)
            ->postJson("/api/classes/{$class->id}/enrollments/bulk", [
                'enroll' => [],
                'unenroll' => [$a->id, $b->id],
            ])
            ->assertStatus(422);

        // Nobody was removed.
        $this->assertDatabaseHas('class_enrollments', ['id' => $a->id]);
        $this->assertDatabaseHas('class_enrollments', ['id' => $b->id]);
    }

    public function test_bulk_full_clear_succeeds_with_explicit_confirmation(): void
    {
        $org = Organization::factory()->create();
        $manager = $this->manager($org);
        $class = TrainingClass::factory()->for($org, 'organization')->create();
        $a = ClassEnrollment::factory()->for($class, 'trainingClass')->create(['user_id' => User::factory()->for($org, 'organization')]);
        $b = ClassEnrollment::factory()->for($class, 'trainingClass')->create(['user_id' => User::factory()->for($org, 'organization')]);

        $this->actingAs($manager)
            ->postJson("/api/classes/{$class->id}/enrollments/bulk", [
                'enroll' => [],
                'unenroll' => [$a->id, $b->id],
                'confirm_clear' => true,
            ])
            ->assertOk()
            ->assertJsonCount(0, 'enrollments');

        $this->assertDatabaseMissing('class_enrollments', ['id' => $a->id]);
        $this->assertDatabaseMissing('class_enrollments', ['id' => $b->id]);
    }

    public function test_bulk_removing_the_last_single_enrollee_needs_no_confirmation(): void
    {
        $org = Organization::factory()->create();
        $manager = $this->manager($org);
        $class = TrainingClass::factory()->for($org, 'organization')->create();
        $only = ClassEnrollment::factory()->for($class, 'trainingClass')->create(['user_id' => User::factory()->for($org, 'organization')]);

        // Removing the last person from a one-person roster is a normal edit.
        $this->actingAs($manager)
            ->postJson("/api/classes/{$class->id}/enrollments/bulk", [
                'enroll' => [],
                'unenroll' => [$only->id],
            ])
            ->assertOk();

        $this->assertDatabaseMissing('class_enrollments', ['id' => $only->id]);
    }

    public function test_bulk_enrollment_blocked_on_completed_class(): void
    {
        $org = Organization::factory()->create();
        $manager = $this->manager($org);
        $class = TrainingClass::factory()->for($org, 'organization')->create(['status' => 'completed']);
        $student = User::factory()->for($org, 'organization')->create();

        $this->actingAs($manager)
            ->postJson("/api/classes/{$class->id}/enrollments/bulk", [
                'enroll' => [$student->id],
                'unenroll' => [],
            ])
            ->assertStatus(422);
    }

    public function test_enrollment_detail_includes_the_users_name_and_email(): void
    {
        $org = Organization::factory()->create();
        $manager = $this->manager($org);
        $class = TrainingClass::factory()->for($org, 'organization')->create();
        $student = User::factory()->for($org, 'organization')
            ->create(['f_name' => 'Dana', 'l_name' => 'Reed', 'email' => 'dana.reed@demo.local']);

        $this->actingAs($manager)
            ->postJson("/api/classes/{$class->id}/enrollments", ['user_id' => $student->id])
            ->assertOk()
            ->assertJsonPath('enrollments.0.user_name', 'Dana Reed')
            ->assertJsonPath('enrollments.0.user_email', 'dana.reed@demo.local');
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
