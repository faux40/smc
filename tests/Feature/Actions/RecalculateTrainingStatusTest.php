<?php

namespace Tests\Feature\Actions;

use App\Actions\RecalculateTrainingStatus;
use App\Models\Completion;
use App\Models\Organization;
use App\Models\StdFrequency;
use App\Models\Training;
use App\Models\TrainingAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecalculateTrainingStatusTest extends TestCase
{
    use RefreshDatabase;

    private function makeContext(array $trainingAttrs = []): array
    {
        $org = Organization::factory()->create();
        $user = User::factory()->for($org, 'organization')->create();
        $training = Training::factory()->for($org, 'organization')->create($trainingAttrs);
        $assignment = TrainingAssignment::factory()->create([
            'org_id' => $org->id,
            'user_id' => $user->id,
            'training_id' => $training->id,
        ]);

        return compact('org', 'user', 'training', 'assignment');
    }

    public function test_leaves_both_null_when_no_completions_exist(): void
    {
        ['user' => $user, 'training' => $training, 'assignment' => $assignment] = $this->makeContext();

        (new RecalculateTrainingStatus)->handle($user->id, $training->id);

        $assignment->refresh();
        $this->assertNull($assignment->expires_at);
        $this->assertNull($assignment->last_completed_at);
    }

    public function test_sets_last_completed_at_and_uses_explicit_expire_date(): void
    {
        ['org' => $org, 'user' => $user, 'training' => $training, 'assignment' => $assignment]
            = $this->makeContext();

        Completion::factory()->create([
            'org_id' => $org->id,
            'user_id' => $user->id,
            'module_type' => Training::class,
            'module_id' => $training->id,
            'completion_date' => '2026-01-10',
            'expire_date' => '2027-06-30',
        ]);

        (new RecalculateTrainingStatus)->handle($user->id, $training->id);

        $assignment->refresh();
        $this->assertEquals('2026-01-10', $assignment->last_completed_at->toDateString());
        $this->assertEquals('2027-06-30', $assignment->expires_at->toDateString());
    }

    public function test_computes_expires_at_from_std_frequency_when_no_expire_date(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->for($org, 'organization')->create();
        $freq = StdFrequency::factory()->for($org, 'organization')->create(['repeat_days' => 365]);
        $training = Training::factory()->for($org, 'organization')->create([
            'repeating' => true,
            'std_freq_id' => $freq->id,
        ]);
        $assignment = TrainingAssignment::factory()->create([
            'org_id' => $org->id,
            'user_id' => $user->id,
            'training_id' => $training->id,
        ]);

        Completion::factory()->create([
            'org_id' => $org->id,
            'user_id' => $user->id,
            'module_type' => Training::class,
            'module_id' => $training->id,
            'completion_date' => '2026-01-01',
            'expire_date' => null,
        ]);

        (new RecalculateTrainingStatus)->handle($user->id, $training->id);

        $assignment->refresh();
        $this->assertEquals('2026-01-01', $assignment->last_completed_at->toDateString());
        $this->assertEquals('2027-01-01', $assignment->expires_at->toDateString());
    }

    public function test_expires_at_is_null_for_initial_only_training_after_completion(): void
    {
        ['org' => $org, 'user' => $user, 'training' => $training, 'assignment' => $assignment]
            = $this->makeContext(['initial_only' => true]);

        Completion::factory()->create([
            'org_id' => $org->id,
            'user_id' => $user->id,
            'module_type' => Training::class,
            'module_id' => $training->id,
            'completion_date' => '2026-03-01',
            'expire_date' => null,
        ]);

        (new RecalculateTrainingStatus)->handle($user->id, $training->id);

        $assignment->refresh();
        $this->assertEquals('2026-03-01', $assignment->last_completed_at->toDateString());
        $this->assertNull($assignment->expires_at);
    }

    public function test_trashed_std_frequency_falls_back_to_no_expiry(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->for($org, 'organization')->create();
        $freq = StdFrequency::factory()->for($org, 'organization')->create(['repeat_days' => 365]);
        $training = Training::factory()->for($org, 'organization')->create([
            'repeating' => true,
            'std_freq_id' => $freq->id,
        ]);
        $assignment = TrainingAssignment::factory()->create([
            'org_id' => $org->id,
            'user_id' => $user->id,
            'training_id' => $training->id,
        ]);

        Completion::factory()->create([
            'org_id' => $org->id,
            'user_id' => $user->id,
            'module_type' => Training::class,
            'module_id' => $training->id,
            'completion_date' => '2026-01-01',
            'expire_date' => null,
        ]);

        $freq->delete();

        (new RecalculateTrainingStatus)->handle($user->id, $training->id);

        $assignment->refresh();
        $this->assertEquals('2026-01-01', $assignment->last_completed_at->toDateString());
        $this->assertNull($assignment->expires_at);
    }

    public function test_uses_most_recent_completion_when_multiple_exist(): void
    {
        ['org' => $org, 'user' => $user, 'training' => $training, 'assignment' => $assignment]
            = $this->makeContext();

        Completion::factory()->create([
            'org_id' => $org->id,
            'user_id' => $user->id,
            'module_type' => Training::class,
            'module_id' => $training->id,
            'completion_date' => '2025-01-01',
            'expire_date' => '2026-01-01',
        ]);
        Completion::factory()->create([
            'org_id' => $org->id,
            'user_id' => $user->id,
            'module_type' => Training::class,
            'module_id' => $training->id,
            'completion_date' => '2026-06-01',
            'expire_date' => '2027-06-01',
        ]);

        (new RecalculateTrainingStatus)->handle($user->id, $training->id);

        $assignment->refresh();
        $this->assertEquals('2026-06-01', $assignment->last_completed_at->toDateString());
        $this->assertEquals('2027-06-01', $assignment->expires_at->toDateString());
    }

    public function test_resets_to_null_after_completion_is_soft_deleted(): void
    {
        ['org' => $org, 'user' => $user, 'training' => $training, 'assignment' => $assignment]
            = $this->makeContext();

        $completion = Completion::factory()->create([
            'org_id' => $org->id,
            'user_id' => $user->id,
            'module_type' => Training::class,
            'module_id' => $training->id,
            'completion_date' => '2026-01-01',
            'expire_date' => '2027-01-01',
        ]);

        (new RecalculateTrainingStatus)->handle($user->id, $training->id);
        $completion->delete();
        (new RecalculateTrainingStatus)->handle($user->id, $training->id);

        $assignment->refresh();
        $this->assertNull($assignment->expires_at);
        $this->assertNull($assignment->last_completed_at);
    }

    public function test_only_updates_assignments_for_the_given_user(): void
    {
        ['org' => $org, 'training' => $training] = $this->makeContext();
        $otherUser = User::factory()->for($org, 'organization')->create();
        $otherAssignment = TrainingAssignment::factory()->create([
            'org_id' => $org->id,
            'user_id' => $otherUser->id,
            'training_id' => $training->id,
            'last_completed_at' => null,
        ]);

        // Completion only for otherUser
        Completion::factory()->create([
            'org_id' => $org->id,
            'user_id' => $otherUser->id,
            'module_type' => Training::class,
            'module_id' => $training->id,
            'completion_date' => '2026-05-01',
            'expire_date' => '2027-05-01',
        ]);

        $user = User::factory()->for($org, 'organization')->create();
        $userAssignment = TrainingAssignment::factory()->create([
            'org_id' => $org->id,
            'user_id' => $user->id,
            'training_id' => $training->id,
            'last_completed_at' => null,
        ]);

        // Only recalculate for otherUser — userAssignment must stay untouched
        (new RecalculateTrainingStatus)->handle($otherUser->id, $training->id);

        $userAssignment->refresh();
        $this->assertNull($userAssignment->last_completed_at);

        $otherAssignment->refresh();
        $this->assertEquals('2026-05-01', $otherAssignment->last_completed_at->toDateString());
    }
}
