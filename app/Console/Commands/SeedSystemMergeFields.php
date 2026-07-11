<?php

namespace App\Console\Commands;

use App\Models\MergeField;
use App\Support\MergeData\SystemMergeFields;
use Illuminate\Console\Command;

/**
 * Registers/refreshes the system merge-field catalog (org_id NULL rows,
 * visible to every org). Idempotent: updateOrCreate by key — metadata
 * (label/type/group/help/seq) follows the catalog, stored org VALUES are
 * untouched. System content stays console-managed until site-admin
 * tooling exists (decision 2026-07-11).
 */
class SeedSystemMergeFields extends Command
{
    protected $signature = 'merge-fields:seed-system';

    protected $description = 'Seed or refresh the system merge-field catalog (org_id NULL)';

    public function handle(): int
    {
        $created = 0;
        $updated = 0;

        $seq = 0;
        foreach (SystemMergeFields::catalog() as $key => $def) {
            $field = MergeField::query()
                ->whereNull('org_id')
                ->where('key', $key)
                ->first();

            $attributes = [
                'org_id' => null,
                'key' => $key,
                'label' => $def['label'],
                'type' => $def['type'],
                'field_group' => $def['group'],
                'help' => $def['help'] ?? null,
                'seq' => $seq++,
                'draft' => false,
            ];

            if ($field === null) {
                MergeField::create($attributes);
                $created++;
            } else {
                $field->update($attributes);
                $updated++;
            }
        }

        $this->info("System merge fields: {$created} created, {$updated} refreshed.");

        return self::SUCCESS;
    }
}
