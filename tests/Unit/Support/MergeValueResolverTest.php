<?php

namespace Tests\Unit\Support;

use App\Models\MergeField;
use App\Models\MergeValue;
use App\Models\Organization;
use App\Support\MergeData\MergeValueResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The variation-resolution ladder (decision 2026-07-11): for a requested
 * (location, department), the most specific eligible row wins —
 * both-match > location-only > department-only > org default > null.
 * This is the seam D2's document generator consumes.
 */
class MergeValueResolverTest extends TestCase
{
    use RefreshDatabase;

    private function field(Organization $org, string $key, string $type = 'text'): MergeField
    {
        return MergeField::factory()->for($org, 'organization')->create(['key' => $key, 'type' => $type]);
    }

    private function value(Organization $org, MergeField $field, mixed $value, string $location = '', string $department = ''): MergeValue
    {
        return MergeValue::factory()
            ->for($org, 'organization')
            ->for($field, 'field')
            ->create(['value' => $value, 'location' => $location, 'department' => $department]);
    }

    public function test_org_default_resolves_when_no_variation_requested(): void
    {
        $org = Organization::factory()->create();
        $field = $this->field($org, 'agency');
        $this->value($org, $field, 'City of Rio Dell');

        $resolved = (new MergeValueResolver)->resolve($org->id);

        $this->assertSame('City of Rio Dell', $resolved['agency']);
    }

    public function test_field_with_no_value_resolves_to_null_but_is_present(): void
    {
        // D2 renders null as a visible --KEY-- placeholder; the key must
        // exist in the map so the generator knows the field exists.
        $org = Organization::factory()->create();
        $this->field($org, 'agency');

        $resolved = (new MergeValueResolver)->resolve($org->id);

        $this->assertArrayHasKey('agency', $resolved);
        $this->assertNull($resolved['agency']);
    }

    public function test_location_override_beats_default(): void
    {
        $org = Organization::factory()->create();
        $field = $this->field($org, 'assembly_area');
        $this->value($org, $field, 'Front lot');
        $this->value($org, $field, 'North gate', location: 'North Yard');

        $resolver = new MergeValueResolver;

        $this->assertSame('North gate', $resolver->resolve($org->id, location: 'North Yard')['assembly_area']);
        // Different location → falls back to the default, never a foreign override.
        $this->assertSame('Front lot', $resolver->resolve($org->id, location: 'South Yard')['assembly_area']);
        // No location requested → location-specific rows are ineligible.
        $this->assertSame('Front lot', $resolver->resolve($org->id)['assembly_area']);
    }

    public function test_department_override_beats_default(): void
    {
        $org = Organization::factory()->create();
        $field = $this->field($org, 'supervisor');
        $this->value($org, $field, 'Org-wide Sup');
        $this->value($org, $field, 'Parks Sup', department: 'Parks');

        $resolved = (new MergeValueResolver)->resolve($org->id, department: 'Parks');

        $this->assertSame('Parks Sup', $resolved['supervisor']);
    }

    public function test_specificity_ladder_with_both_requested(): void
    {
        // both-match > location-only > department-only > default.
        $org = Organization::factory()->create();
        $field = $this->field($org, 'contact');
        $this->value($org, $field, 'default');
        $this->value($org, $field, 'dept-only', department: 'Parks');
        $this->value($org, $field, 'loc-only', location: 'North Yard');
        $this->value($org, $field, 'both', location: 'North Yard', department: 'Parks');

        $resolver = new MergeValueResolver;

        $this->assertSame('both', $resolver->resolve($org->id, 'North Yard', 'Parks')['contact']);

        // Remove the both-row → location-only outranks department-only.
        MergeValue::withoutGlobalScope('organization')
            ->where('location', 'North Yard')->where('department', 'Parks')->delete();
        $this->assertSame('loc-only', $resolver->resolve($org->id, 'North Yard', 'Parks')['contact']);

        // Remove location-only → department-only wins.
        MergeValue::withoutGlobalScope('organization')
            ->where('location', 'North Yard')->delete();
        $this->assertSame('dept-only', $resolver->resolve($org->id, 'North Yard', 'Parks')['contact']);

        // Remove department-only → default.
        MergeValue::withoutGlobalScope('organization')
            ->where('department', 'Parks')->delete();
        $this->assertSame('default', $resolver->resolve($org->id, 'North Yard', 'Parks')['contact']);
    }

    public function test_includes_system_fields_and_scopes_values_per_org(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $system = MergeField::factory()->system()->create(['key' => 'agency']);
        $this->value($orgA, $system, 'Org A Agency');
        $this->value($orgB, $system, 'Org B Agency');
        // Org B's private field must not appear in Org A's map at all.
        $this->field($orgB, 'b_private');

        $resolved = (new MergeValueResolver)->resolve($orgA->id);

        $this->assertSame('Org A Agency', $resolved['agency']);
        $this->assertArrayNotHasKey('b_private', $resolved);
    }

    public function test_list_values_stay_arrays(): void
    {
        $org = Organization::factory()->create();
        $field = $this->field($org, 'workgroups', 'list');
        $this->value($org, $field, ['Public Works', 'Parks']);

        $resolved = (new MergeValueResolver)->resolve($org->id);

        $this->assertSame(['Public Works', 'Parks'], $resolved['workgroups']);
    }

    public function test_soft_deleted_fields_are_excluded(): void
    {
        $org = Organization::factory()->create();
        $field = $this->field($org, 'gone');
        $this->value($org, $field, 'x');
        $field->delete();

        $this->assertArrayNotHasKey('gone', (new MergeValueResolver)->resolve($org->id));
    }
}
