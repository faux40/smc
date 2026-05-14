<?php

namespace Tests\Feature\Schema;

use App\Models\Assignment;
use App\Models\AssignmentNotificationState;
use App\Models\Organization;
use App\Services\UserComplianceCalculator;
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
            'id', 'org_id', 'assignment_id', 'last_seen_status',
            'created_at', 'updated_at',
        ]));
    }

    public function test_assignment_id_is_unique(): void
    {
        $org = Organization::factory()->create();
        $assignment = Assignment::factory()->for($org, 'organization')->create();

        AssignmentNotificationState::create([
            'org_id' => $org->id,
            'assignment_id' => $assignment->id,
            'last_seen_status' => UserComplianceCalculator::STATUS_CURRENT,
        ]);

        $this->expectException(QueryException::class);

        AssignmentNotificationState::create([
            'org_id' => $org->id,
            'assignment_id' => $assignment->id,
            'last_seen_status' => UserComplianceCalculator::STATUS_OVERDUE,
        ]);
    }

    public function test_cascade_deletes_when_assignment_force_deleted(): void
    {
        $org = Organization::factory()->create();
        $assignment = Assignment::factory()->for($org, 'organization')->create();

        $state = AssignmentNotificationState::create([
            'org_id' => $org->id,
            'assignment_id' => $assignment->id,
            'last_seen_status' => UserComplianceCalculator::STATUS_CURRENT,
        ]);

        $assignment->forceDelete();

        $this->assertDatabaseMissing('assignment_notification_states', ['id' => $state->id]);
    }
}
