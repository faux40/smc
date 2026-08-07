<?php

namespace Tests\Feature\Tenancy;

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
 * The refresher guard: `requires_prior_completion` on a class means everyone
 * on the roster should already hold a completion of each topic's training
 * (Initial and Refresher run as class flavors of ONE training — the training
 * is the credential, classes are deliveries).
 *
 * Deliberately a SOFT guard: the detail payload carries, per topic training,
 * which org users have a prior completion, and the roster UI warns — never
 * blocks. People trained at a previous employer or holding paper records are
 * real; a hard gate would fight the front desk within a week.
 */
class ClassPriorCompletionTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->org = Organization::factory()->create();
        $this->admin = User::factory()->for($this->org, 'organization')->withRole('Admin')->create();
    }

    private function classWithTopic(array $classAttrs = []): array
    {
        $training = Training::factory()->for($this->org, 'organization')->create();
        $class = TrainingClass::factory()->for($this->org, 'organization')->create(array_merge([
            'status' => 'scheduled',
        ], $classAttrs));
        $topic = ClassTraining::factory()
            ->for($class, 'trainingClass')
            ->for($training, 'training')
            ->create(['training_name' => $training->name]);

        return [$class, $training, $topic];
    }

    private function complete(User $user, Training $training, array $attrs = []): Completion
    {
        return Completion::factory()->create(array_merge([
            'org_id' => $this->org->id,
            'user_id' => $user->id,
            'module_type' => Training::class,
            'module_id' => $training->id,
            'completion_date' => '2025-01-15',
        ], $attrs));
    }

    public function test_flag_defaults_false_and_round_trips_through_store_and_update(): void
    {
        $created = $this->actingAs($this->admin)
            ->postJson('/api/classes', [
                'name' => 'FP Comp Person — Refresher',
                'scheduled_date' => '2026-09-01',
                'requires_prior_completion' => true,
            ])
            ->assertCreated()
            ->json();

        $this->assertTrue($created['requires_prior_completion']);

        $plain = $this->actingAs($this->admin)
            ->postJson('/api/classes', [
                'name' => 'FP Comp Person — Initial',
                'scheduled_date' => '2026-09-02',
            ])
            ->assertCreated()
            ->json();

        $this->assertFalse($plain['requires_prior_completion']);

        $updated = $this->actingAs($this->admin)
            ->patchJson("/api/classes/{$plain['id']}", [
                'name' => $plain['name'],
                'scheduled_date' => $plain['scheduled_date'],
                'requires_prior_completion' => true,
            ])
            ->assertOk()
            ->json();

        $this->assertTrue($updated['requires_prior_completion']);
    }

    public function test_detail_lists_users_with_a_prior_completion_per_topic_training(): void
    {
        [$class, $training] = $this->classWithTopic(['requires_prior_completion' => true]);

        $hasPrior = User::factory()->for($this->org, 'organization')->create();
        $noPrior = User::factory()->for($this->org, 'organization')->create();
        $this->complete($hasPrior, $training);

        $detail = $this->actingAs($this->admin)
            ->getJson("/api/classes/{$class->id}")
            ->assertOk()
            ->json();

        $ids = $detail['prior_completion_user_ids'][$training->id];
        $this->assertContains($hasPrior->id, $ids);
        $this->assertNotContains($noPrior->id, $ids);
    }

    public function test_a_completion_issued_by_this_very_class_is_not_prior(): void
    {
        // Someone whose ONLY completion came from this class did not "have
        // the initial" — the refresher must not count its own credit.
        [$class, $training, $topic] = $this->classWithTopic(['requires_prior_completion' => true]);

        $selfCredit = User::factory()->for($this->org, 'organization')->create();
        $this->complete($selfCredit, $training, ['class_training_id' => $topic->id]);

        $detail = $this->actingAs($this->admin)
            ->getJson("/api/classes/{$class->id}")
            ->assertOk()
            ->json();

        $this->assertNotContains($selfCredit->id, $detail['prior_completion_user_ids'][$training->id]);
    }

    public function test_an_expired_completion_still_counts_as_prior(): void
    {
        // Existence, not currency: a lapsed credential still proves they had
        // the initial — the refresher is exactly how they get current again.
        [$class, $training] = $this->classWithTopic(['requires_prior_completion' => true]);

        $lapsed = User::factory()->for($this->org, 'organization')->create();
        $this->complete($lapsed, $training, [
            'completion_date' => '2020-01-15',
            'expire_date' => '2021-01-15',
        ]);

        $detail = $this->actingAs($this->admin)
            ->getJson("/api/classes/{$class->id}")
            ->assertOk()
            ->json();

        $this->assertContains($lapsed->id, $detail['prior_completion_user_ids'][$training->id]);
    }

    public function test_a_completion_from_another_class_of_the_same_training_is_prior(): void
    {
        // The Initial ran as a class of the same training — its credit is
        // exactly what the refresher wants to see.
        [$class, $training] = $this->classWithTopic(['requires_prior_completion' => true]);

        $initialClass = TrainingClass::factory()->for($this->org, 'organization')->create([
            'status' => 'completed',
            'completion_date' => '2025-01-15',
            'completed_at' => now(),
        ]);
        $initialTopic = ClassTraining::factory()
            ->for($initialClass, 'trainingClass')
            ->for($training, 'training')
            ->create(['training_name' => $training->name]);

        $graduate = User::factory()->for($this->org, 'organization')->create();
        $this->complete($graduate, $training, ['class_training_id' => $initialTopic->id]);

        $detail = $this->actingAs($this->admin)
            ->getJson("/api/classes/{$class->id}")
            ->assertOk()
            ->json();

        $this->assertContains($graduate->id, $detail['prior_completion_user_ids'][$training->id]);
    }

    public function test_the_map_is_empty_when_the_flag_is_off(): void
    {
        // No flag, no bookkeeping — the detail payload stays lean and the UI
        // has nothing to warn about.
        [$class, $training] = $this->classWithTopic();

        $veteran = User::factory()->for($this->org, 'organization')->create();
        $this->complete($veteran, $training);

        $detail = $this->actingAs($this->admin)
            ->getJson("/api/classes/{$class->id}")
            ->assertOk()
            ->json();

        $this->assertSame([], $detail['prior_completion_user_ids']);
    }
}
