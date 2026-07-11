<?php

namespace Tests\Unit\Support;

use App\Models\MergeField;
use App\Models\MergeValue;
use App\Models\Organization;
use App\Support\DocMerge\MergeDataBuilder;
use App\Support\MergeData\MergeValueResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class MergeDataBuilderTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function builder(): MergeDataBuilder
    {
        return new MergeDataBuilder(new MergeValueResolver);
    }

    public function test_builds_fields_with_visible_placeholders_for_gaps(): void
    {
        $org = Organization::factory()->create();
        $agency = MergeField::factory()->system()->create(['key' => 'agency']);
        MergeField::factory()->system()->create(['key' => 'top_manager']);
        MergeValue::factory()->for($org, 'organization')->for($agency, 'field')->create(['value' => 'City of Rio Dell']);

        $built = $this->builder()->build($org);

        $this->assertSame('City of Rio Dell', $built['fields']['agency']);
        $this->assertSame('--TOP_MANAGER--', $built['fields']['top_manager']);
    }

    public function test_list_fields_join_inline_and_produce_block_rows(): void
    {
        $org = Organization::factory()->create();
        $eap = MergeField::factory()->system()->create(['key' => 'eap_info', 'type' => 'list']);
        MergeField::factory()->system()->create(['key' => 'cs_spaces', 'type' => 'list']);
        MergeValue::factory()->for($org, 'organization')->for($eap, 'field')
            ->create(['value' => ['Call 911', 'Notify supervisor']]);

        $built = $this->builder()->build($org);

        $this->assertSame('Call 911, Notify supervisor', $built['fields']['eap_info']);
        $this->assertSame(
            [['item' => 'Call 911'], ['item' => 'Notify supervisor']],
            $built['listRows']['eap_info'],
        );
        // Unset list: visible placeholder inline, no rows (block vanishes).
        $this->assertSame('--CS_SPACES--', $built['fields']['cs_spaces']);
        $this->assertSame([], $built['listRows']['cs_spaces']);
    }

    public function test_variation_overrides_flow_through(): void
    {
        $org = Organization::factory()->create();
        $field = MergeField::factory()->system()->create(['key' => 'assembly_area_onsite_primary']);
        MergeValue::factory()->for($org, 'organization')->for($field, 'field')->create(['value' => 'Front lot']);
        MergeValue::factory()->for($org, 'organization')->for($field, 'field')
            ->create(['value' => 'North gate', 'location' => 'North Yard']);

        $this->assertSame(
            'North gate',
            $this->builder()->build($org, location: 'North Yard')['fields']['assembly_area_onsite_primary'],
        );
        $this->assertSame(
            'Front lot',
            $this->builder()->build($org)['fields']['assembly_area_onsite_primary'],
        );
    }

    public function test_computed_dates_use_the_org_timezone(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-05 03:00:00', 'UTC'));
        // 3am UTC July 5 is still July 4 in Los Angeles.
        $org = Organization::factory()->create(['timezone' => 'America/Los_Angeles']);

        $fields = $this->builder()->build($org)['fields'];

        $this->assertSame('2026-07-04', $fields['doc_date']);
        $this->assertSame('July 2026', $fields['doc_date_my']);
        $this->assertSame('20260704', $fields['foot_date']);
        $this->assertSame('2026', $fields['copy_date']);
    }

    public function test_legacy_ems_alias_keeps_old_templates_working(): void
    {
        $org = Organization::factory()->create();
        $field = MergeField::factory()->system()->create(['key' => 'ems_direct_phone']);
        MergeValue::factory()->for($org, 'organization')->for($field, 'field')->create(['value' => '555-0100']);

        $fields = $this->builder()->build($org)['fields'];

        $this->assertSame('555-0100', $fields['EMS_direct_phone']);
    }
}
