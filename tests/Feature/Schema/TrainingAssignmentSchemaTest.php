<?php

namespace Tests\Feature\Schema;

use App\Models\AssignmentSource;
use App\Models\Organization;
use App\Models\Training;
use App\Models\TrainingAssignment;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TrainingAssignmentSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_training_assignments_table_has_correct_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('training_assignments', [
            'id', 'org_id', 'user_id', 'training_id', 'name',
            'expires_at', 'last_completed_at', 'created_at', 'updated_at',
        ]));
    }

    public function test_assignment_sources_table_has_correct_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('assignment_sources', [
            'id', 'training_assignment_id',
            'sourceable_type', 'sourceable_id',
            'added_at', 'removed_at',
        ]));
    }

    public function test_duplicate_user_training_pair_is_rejected(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->for($org, 'organization')->create();
        $training = Training::factory()->for($org, 'organization')->create();

        TrainingAssignment::factory()->create([
            'org_id' => $org->id,
            'user_id' => $user->id,
            'training_id' => $training->id,
        ]);

        $this->expectException(QueryException::class);

        TrainingAssignment::factory()->create([
            'org_id' => $org->id,
            'user_id' => $user->id,
            'training_id' => $training->id,
        ]);
    }

    public function test_assignment_source_cascades_on_training_assignment_delete(): void
    {
        $org = Organization::factory()->create();
        $ta = TrainingAssignment::factory()->for($org, 'organization')->create();

        $source = AssignmentSource::create([
            'training_assignment_id' => $ta->id,
            'sourceable_type' => null,
            'sourceable_id' => null,
            'added_at' => now(),
        ]);

        $ta->delete();

        $this->assertDatabaseMissing('assignment_sources', ['id' => $source->id]);
    }
}
