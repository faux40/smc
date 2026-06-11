<?php

namespace Tests\Feature\Schema;

use App\Models\AssignmentNotificationState;
use App\Models\Organization;
use App\Models\TrainingAssignment;
use App\Services\TrainingStatusService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AssignmentNotificationStateSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_table_shape(): void
    {
        $this->assertTrue(Schema::hasColumns('assignment_notification_states', [
            'id', 'org_id', 'training_assignment_id', 'last_seen_status',
            'created_at', 'updated_at',
        ]));
    }

    public function test_training_assignment_id_is_unique(): void
    {
        $org = Organization::factory()->create();
        $ta = TrainingAssignment::factory()->create(['org_id' => $org->id]);

        AssignmentNotificationState::create([
            'org_id' => $org->id,
            'training_assignment_id' => $ta->id,
            'last_seen_status' => TrainingStatusService::STATUS_CURRENT,
        ]);

        $this->expectException(QueryException::class);

        AssignmentNotificationState::create([
            'org_id' => $org->id,
            'training_assignment_id' => $ta->id,
            'last_seen_status' => TrainingStatusService::STATUS_OVERDUE,
        ]);
    }

    public function test_cascade_deletes_when_training_assignment_deleted(): void
    {
        $org = Organization::factory()->create();
        $ta = TrainingAssignment::factory()->create(['org_id' => $org->id]);

        $state = AssignmentNotificationState::create([
            'org_id' => $org->id,
            'training_assignment_id' => $ta->id,
            'last_seen_status' => TrainingStatusService::STATUS_CURRENT,
        ]);

        $ta->delete();

        $this->assertDatabaseMissing('assignment_notification_states', ['id' => $state->id]);
    }
}
