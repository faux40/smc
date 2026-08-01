<?php

namespace Tests\Feature\Schema;

use App\Models\Completion;
use App\Models\Organization;
use App\Models\Requirement;
use App\Models\RqmtElement;
use App\Models\StdFrequency;
use App\Models\Training;
use App\Models\TrainingAssignment;
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

    public function test_legacy_assignments_table_is_gone(): void
    {
        // J5: the legacy user×requirement assignments table was retired —
        // training_assignments + assignment_sources are the only persisted
        // assignment shape. Assert the drop so it can't quietly come back.
        $this->assertFalse(Schema::hasTable('assignments'));
    }

    public function test_completions_table_shape(): void
    {
        $this->assertTrue(Schema::hasColumns('completions', [
            'id', 'org_id', 'user_id',
            'module_type', 'module_id',
            'completion_date', 'certification_date', 'expire_date',
            'cert_ident', 'notes',
            'created_at', 'updated_at', 'deleted_at',
        ]));
        // The rqmt_element link moved out to the `completion_elements` pivot
        // in v15 — assert the column is gone so we can't regress.
        $this->assertFalse(Schema::hasColumn('completions', 'rqmt_element_id'));

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
            ->state([
                'module_type' => Training::class,
                'module_id' => $training->id,
            ])
            ->create();
        $completion->rqmtElements()->sync([$element->id]);

        $this->assertSame($user->id, $completion->user_id);
        $this->assertSame([$element->id], $completion->rqmtElements()->pluck('rqmt_elements.id')->all());
    }

    public function test_completion_elements_pivot_shape(): void
    {
        $this->assertTrue(Schema::hasColumns('completion_elements', [
            'completion_id', 'rqmt_element_id',
        ]));

        $org = Organization::factory()->create();
        $user = User::factory()->for($org, 'organization')->create();
        $req = Requirement::factory()->for($org, 'organization')->create();
        $training = Training::factory()->for($org, 'organization')->create();
        $trainingB = Training::factory()->for($org, 'organization')->create();
        $elementA = RqmtElement::factory()
            ->for($org, 'organization')
            ->for($req, 'requirement')
            ->state(['module_type' => Training::class, 'module_id' => $training->id])
            ->create();
        // Distinct training — a requirement can't bind the same module twice.
        $elementB = RqmtElement::factory()
            ->for($org, 'organization')
            ->for($req, 'requirement')
            ->state(['module_type' => Training::class, 'module_id' => $trainingB->id])
            ->create();

        $completion = Completion::factory()
            ->for($org, 'organization')
            ->for($user, 'user')
            ->state(['module_type' => Training::class, 'module_id' => $training->id])
            ->create();

        // One completion can credit several elements (v15 spec).
        $completion->rqmtElements()->sync([$elementA->id, $elementB->id]);

        $this->assertEqualsCanonicalizing(
            [$elementA->id, $elementB->id],
            $completion->rqmtElements()->pluck('rqmt_elements.id')->all(),
        );
    }

    public function test_completion_can_exist_without_assignment(): void
    {
        // v15 spec: completions stand alone. A user can complete a module
        // without being assigned (still must link to an element in the system,
        // but that's an application-layer rule, not a schema rule).
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

        // The user has no training assignment for this module.
        $this->assertSame(0, TrainingAssignment::query()->where('user_id', $user->id)->count());

        $completion = Completion::factory()
            ->for($org, 'organization')
            ->for($user, 'user')
            ->state([
                'module_type' => Training::class,
                'module_id' => $training->id,
            ])
            ->create();
        $completion->rqmtElements()->sync([$element->id]);

        $this->assertNotNull($completion->id);
        $this->assertCount(1, $completion->rqmtElements);
    }
}
