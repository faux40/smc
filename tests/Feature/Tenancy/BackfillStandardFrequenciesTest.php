<?php

namespace Tests\Feature\Tenancy;

use App\Actions\BackfillStandardFrequencies;
use App\Models\Organization;
use App\Models\StdFrequency;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BackfillStandardFrequenciesTest extends TestCase
{
    use RefreshDatabase;

    /** Names present for an org, ignoring the tenant global scope. */
    private function freqNames(Organization $org): array
    {
        return StdFrequency::withoutGlobalScope('organization')
            ->where('org_id', $org->id)
            ->orderByDesc('repeat_days')
            ->pluck('name')
            ->all();
    }

    public function test_backfills_missing_standard_frequencies_for_every_org(): void
    {
        // One org already has a subset; another has none at all.
        $orgA = Organization::factory()->create();
        StdFrequency::create(['org_id' => $orgA->id, 'name' => 'Annual', 'repeat_days' => 365]);
        $orgB = Organization::factory()->create();

        $created = (new BackfillStandardFrequencies)->handle();

        $standardNames = array_column(StdFrequency::STANDARD, 'name');
        foreach ([$orgA, $orgB] as $org) {
            $this->assertEqualsCanonicalizing($standardNames, $this->freqNames($org));
        }

        // The four new multi-year options are present with correct day counts.
        $this->assertSame(
            730,
            StdFrequency::withoutGlobalScope('organization')
                ->where('org_id', $orgA->id)->where('name', 'Every 2 Years')->value('repeat_days'),
        );

        // orgA was missing 8, orgB missing all 9.
        $this->assertSame(8 + count(StdFrequency::STANDARD), $created);
    }

    public function test_is_idempotent_and_creates_no_duplicates(): void
    {
        $org = Organization::factory()->create();

        (new BackfillStandardFrequencies)->handle();
        $afterFirst = $this->freqNames($org);

        $created = (new BackfillStandardFrequencies)->handle();

        $this->assertSame(0, $created);
        $this->assertSame($afterFirst, $this->freqNames($org));
        $this->assertCount(count(StdFrequency::STANDARD), $afterFirst);
    }

    public function test_can_target_a_single_org(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();

        $created = (new BackfillStandardFrequencies)->handle($orgA->id);

        $this->assertSame(count(StdFrequency::STANDARD), $created);
        $this->assertNotEmpty($this->freqNames($orgA));
        $this->assertEmpty($this->freqNames($orgB)); // untouched
    }
}
