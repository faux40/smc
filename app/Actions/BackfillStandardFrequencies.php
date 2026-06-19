<?php

namespace App\Actions;

use App\Models\Organization;
use App\Models\StdFrequency;
use Illuminate\Database\Eloquent\Collection;

/**
 * Ensure every organization has the standard frequency set
 * (StdFrequency::STANDARD).
 *
 * New orgs already get the full set at registration (CreateNewUser), so this
 * is for orgs created before a frequency was added to the standard list — it
 * inserts only the missing ones. Idempotent: re-running never duplicates, and
 * an org's own custom frequencies are left untouched. On a fresh database
 * (no orgs yet) it's a no-op.
 */
class BackfillStandardFrequencies
{
    /**
     * @param  string|null  $orgId  limit to one organization (null = all)
     * @return int number of frequency rows created
     */
    public function handle(?string $orgId = null): int
    {
        $created = 0;

        Organization::query()
            ->when($orgId, fn ($q) => $q->whereKey($orgId))
            ->chunkById(200, function (Collection $orgs) use (&$created) {
                foreach ($orgs as $org) {
                    $created += $this->backfillOrg($org);
                }
            });

        return $created;
    }

    private function backfillOrg(Organization $org): int
    {
        $created = 0;

        // Existing names for this org, read unscoped so a bound tenant context
        // (e.g. a request) can't hide another org's rows.
        $existing = StdFrequency::withoutGlobalScope('organization')
            ->where('org_id', $org->id)
            ->pluck('name')
            ->all();

        foreach (StdFrequency::STANDARD as $freq) {
            if (in_array($freq['name'], $existing, true)) {
                continue;
            }

            StdFrequency::create([
                'org_id' => $org->id,
                'name' => $freq['name'],
                'repeat_days' => $freq['repeat_days'],
            ]);
            $created++;
        }

        return $created;
    }
}
