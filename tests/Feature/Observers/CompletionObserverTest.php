<?php

namespace Tests\Feature\Observers;

use App\Models\Completion;
use App\Models\Organization;
use App\Models\Training;
use App\Models\TrainingAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompletionObserverTest extends TestCase
{
    use RefreshDatabase;

    public function test_saving_a_training_completion_updates_training_assignment_status(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->for($org, 'organization')->create();
        $training = Training::factory()->for($org, 'organization')->create();
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
            'completion_date' => '2026-04-01',
            'expire_date' => '2027-04-01',
        ]);

        $assignment->refresh();
        $this->assertEquals('2026-04-01', $assignment->last_completed_at->toDateString());
        $this->assertEquals('2027-04-01', $assignment->expires_at->toDateString());
    }

    public function test_soft_deleting_a_completion_reverts_assignment_status(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->for($org, 'organization')->create();
        $training = Training::factory()->for($org, 'organization')->create();
        $assignment = TrainingAssignment::factory()->create([
            'org_id' => $org->id,
            'user_id' => $user->id,
            'training_id' => $training->id,
        ]);

        $completion = Completion::factory()->create([
            'org_id' => $org->id,
            'user_id' => $user->id,
            'module_type' => Training::class,
            'module_id' => $training->id,
            'completion_date' => '2026-04-01',
            'expire_date' => '2027-04-01',
        ]);

        $assignment->refresh();
        $this->assertEquals('2026-04-01', $assignment->last_completed_at->toDateString());

        $completion->delete();

        $assignment->refresh();
        $this->assertNull($assignment->last_completed_at);
        $this->assertNull($assignment->expires_at);
    }

    public function test_non_training_completion_does_not_affect_training_assignments(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->for($org, 'organization')->create();
        $training = Training::factory()->for($org, 'organization')->create();
        $assignment = TrainingAssignment::factory()->create([
            'org_id' => $org->id,
            'user_id' => $user->id,
            'training_id' => $training->id,
            'last_completed_at' => null,
            'expires_at' => null,
        ]);

        // Completion for some other module type (not Training)
        Completion::factory()->create([
            'org_id' => $org->id,
            'user_id' => $user->id,
            'module_type' => 'App\\Models\\SomeOtherModule',
            'module_id' => $training->id,
            'completion_date' => '2026-04-01',
            'expire_date' => '2027-04-01',
        ]);

        $assignment->refresh();
        $this->assertNull($assignment->last_completed_at);
        $this->assertNull($assignment->expires_at);
    }
}
