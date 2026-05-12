<?php

namespace Tests\Feature\Schema;

use App\Models\Assignment;
use App\Models\Completion;
use App\Models\Organization;
use App\Models\Requirement;
use App\Models\RqmtElement;
use App\Models\StdFrequency;
use App\Models\Training;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Phase 5.2 schema scaffold — verify each domain-core table is reachable
 * via its model + factory, has the expected columns, and FKs point at
 * the right tables. Business logic (FormRequests, controllers, policies)
 * lands in later phases.
 */
class DomainCoreSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_std_frequencies_table_shape(): void
    {
        $this->assertTrue(Schema::hasColumns('std_frequencies', [
            'id', 'org_id', 'name', 'repeat_days', 'created_at', 'updated_at', 'deleted_at',
        ]));

        $org = Organization::factory()->create();
        $freq = StdFrequency::factory()->for($org, 'organization')->create();

        $this->assertSame($org->id, $freq->org_id);
    }

    public function test_trainings_table_shape(): void
    {
        $this->assertTrue(Schema::hasColumns('trainings', [
            'id', 'org_id', 'name', 'description',
            'initial_only', 'repeating', 'std_freq_id', 'as_needed',
            'created_at', 'updated_at', 'deleted_at',
        ]));

        $org = Organization::factory()->create();
        $training = Training::factory()->for($org, 'organization')->create();

        $this->assertSame($org->id, $training->org_id);
    }

    public function test_requirements_table_shape(): void
    {
        $this->assertTrue(Schema::hasColumns('requirements', [
            'id', 'org_id', 'name', 'description',
            'created_at', 'updated_at', 'deleted_at',
        ]));

        $org = Organization::factory()->create();
        $req = Requirement::factory()->for($org, 'organization')->create();

        $this->assertSame($org->id, $req->org_id);
    }

    public function test_rqmt_elements_table_shape(): void
    {
        $this->assertTrue(Schema::hasColumns('rqmt_elements', [
            'id', 'org_id', 'requirement_id',
            'module_type', 'module_id',
            'name', 'description',
            'initial_only', 'repeating', 'std_freq_id', 'as_needed',
            'created_at', 'updated_at', 'deleted_at',
        ]));

        $org = Organization::factory()->create();
        $req = Requirement::factory()->for($org, 'organization')->create();
        $training = Training::factory()->for($org, 'organization')->create();

        $element = RqmtElement::factory()
            ->for($org, 'organization')
            ->for($req, 'requirement')
            ->state([
                'module_type' => Training::class,
                'module_id' => $training->id,
            ])
            ->create();

        $this->assertSame($req->id, $element->requirement_id);
        $this->assertSame($training->id, $element->module_id);
    }

    public function test_assignments_table_shape(): void
    {
        $this->assertTrue(Schema::hasColumns('assignments', [
            'id', 'org_id', 'user_id', 'requirement_id',
            'initial_only', 'repeating', 'std_freq_id', 'as_needed',
            'name', 'description',
            'start_date', 'end_date',
            'created_at', 'updated_at', 'deleted_at',
        ]));

        $org = Organization::factory()->create();
        $user = User::factory()->for($org, 'organization')->create();
        $req = Requirement::factory()->for($org, 'organization')->create();

        $assignment = Assignment::factory()
            ->for($org, 'organization')
            ->for($user, 'user')
            ->for($req, 'requirement')
            ->create();

        $this->assertSame($user->id, $assignment->user_id);
        $this->assertSame($req->id, $assignment->requirement_id);
    }

    public function test_completions_table_shape(): void
    {
        $this->assertTrue(Schema::hasColumns('completions', [
            'id', 'org_id', 'user_id', 'rqmt_element_id',
            'module_type', 'module_id',
            'completion_date', 'certification_date', 'expire_date',
            'cert_ident', 'notes',
            'created_at', 'updated_at', 'deleted_at',
        ]));

        $org = Organization::factory()->create();
        $user = User::factory()->for($org, 'organization')->create();
        $req = Requirement::factory()->for($org, 'organization')->create();
        $training = Training::factory()->for($org, 'organization')->create();
        $element = RqmtElement::factory()
            ->for($org, 'organization')
            ->for($req, 'requirement')
            ->state([
                'module_type' => Training::class,
                'module_id' => $training->id,
            ])
            ->create();

        $completion = Completion::factory()
            ->for($org, 'organization')
            ->for($user, 'user')
            ->for($element, 'rqmtElement')
            ->state([
                'module_type' => Training::class,
                'module_id' => $training->id,
            ])
            ->create();

        $this->assertSame($user->id, $completion->user_id);
        $this->assertSame($element->id, $completion->rqmt_element_id);
    }

    public function test_completion_can_exist_without_assignment(): void
    {
        // v14 spec: completions stand alone. A user can complete a module
        // without being assigned; future assignments are pre-satisfied.
        $org = Organization::factory()->create();
        $user = User::factory()->for($org, 'organization')->create();
        $req = Requirement::factory()->for($org, 'organization')->create();
        $training = Training::factory()->for($org, 'organization')->create();
        $element = RqmtElement::factory()
            ->for($org, 'organization')
            ->for($req, 'requirement')
            ->state([
                'module_type' => Training::class,
                'module_id' => $training->id,
            ])
            ->create();

        // No Assignment row exists for this user×requirement pair.
        $this->assertSame(0, Assignment::query()->where('user_id', $user->id)->count());

        $completion = Completion::factory()
            ->for($org, 'organization')
            ->for($user, 'user')
            ->for($element, 'rqmtElement')
            ->state([
                'module_type' => Training::class,
                'module_id' => $training->id,
            ])
            ->create();

        $this->assertNotNull($completion->id);
    }
}
