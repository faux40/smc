<?php

namespace Tests\Feature\Console;

use App\Models\MergeField;
use App\Support\MergeData\SystemMergeFields;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeedSystemMergeFieldsTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeds_the_system_catalog(): void
    {
        $this->artisan('merge-fields:seed-system')
            ->assertSuccessful();

        $this->assertSame(
            count(SystemMergeFields::catalog()),
            MergeField::query()->whereNull('org_id')->count(),
        );

        $agency = MergeField::query()->whereNull('org_id')->where('key', 'agency')->first();
        $this->assertNotNull($agency);
        $this->assertSame('Agency profile', $agency->field_group);
        $this->assertSame('text', $agency->type);

        // Pipe-delimited demo columns arrive as proper list fields.
        $eap = MergeField::query()->whereNull('org_id')->where('key', 'eap_info')->first();
        $this->assertSame('list', $eap->type);

        // The demo's mixed-case ${EMS_direct_phone} is normalized to the
        // key grammar; Phase M's template translation maps the old token.
        $this->assertNotNull(
            MergeField::query()->whereNull('org_id')->where('key', 'ems_direct_phone')->first(),
        );
        $this->assertNull(
            MergeField::query()->whereNull('org_id')->where('key', 'EMS_direct_phone')->first(),
        );
    }

    public function test_reseeding_is_idempotent_and_updates_metadata(): void
    {
        $this->artisan('merge-fields:seed-system')->assertSuccessful();
        $count = MergeField::query()->whereNull('org_id')->count();

        // Simulate a stale label; re-seed refreshes metadata but never
        // duplicates or destroys.
        MergeField::query()->whereNull('org_id')->where('key', 'agency')
            ->update(['label' => 'Stale label']);

        $this->artisan('merge-fields:seed-system')->assertSuccessful();

        $this->assertSame($count, MergeField::query()->whereNull('org_id')->count());
        $this->assertSame(
            SystemMergeFields::catalog()['agency']['label'],
            MergeField::query()->whereNull('org_id')->where('key', 'agency')->value('label'),
        );
    }

    public function test_every_catalog_key_satisfies_the_key_grammar(): void
    {
        foreach (array_keys(SystemMergeFields::catalog()) as $key) {
            $this->assertMatchesRegularExpression('/^[a-z][a-z0-9_]*$/', $key, "bad key: {$key}");
        }
    }
}
