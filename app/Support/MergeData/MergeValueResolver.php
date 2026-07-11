<?php

namespace App\Support\MergeData;

use App\Models\MergeField;
use App\Models\MergeValue;

/**
 * Resolves an org's merge data into a flat [key => value] map for a
 * requested (location, department) variation — the seam the D2 document
 * generator merges into `${key}` template placeholders.
 *
 * Ladder (decision 2026-07-11): the most specific eligible row wins —
 * both-match > location-only > department-only > org default. A row is
 * eligible only when each of its dimensions is '' or equals the request
 * (so a "North Yard" override never leaks into a "South Yard" or
 * unqualified document). Fields with no eligible row map to null; the
 * generator renders those as visible --KEY-- placeholders.
 *
 * Queries are explicitly org-filtered and unscoped so the resolver works
 * identically in requests, queued jobs, and console commands (where
 * `currentOrgId` may not be bound).
 */
class MergeValueResolver
{
    /**
     * @return array<string, mixed>
     */
    public function resolve(string $orgId, ?string $location = null, ?string $department = null): array
    {
        $fields = MergeField::query()
            ->visibleTo($orgId)
            ->get(['id', 'key']);

        if ($fields->isEmpty()) {
            return [];
        }

        $locations = $location !== null && $location !== '' ? ['', $location] : [''];
        $departments = $department !== null && $department !== '' ? ['', $department] : [''];

        $best = [];
        MergeValue::query()
            ->withoutGlobalScope('organization')
            ->where('org_id', $orgId)
            ->whereIn('merge_field_id', $fields->pluck('id'))
            ->whereIn('location', $locations)
            ->whereIn('department', $departments)
            ->get()
            ->each(function (MergeValue $row) use (&$best): void {
                // Location outranks department when only one can match.
                $score = ($row->location !== '' ? 2 : 0) + ($row->department !== '' ? 1 : 0);
                $current = $best[$row->merge_field_id] ?? null;
                if ($current === null || $score > $current['score']) {
                    $best[$row->merge_field_id] = ['score' => $score, 'value' => $row->value];
                }
            });

        return $fields
            ->mapWithKeys(fn (MergeField $f) => [$f->key => $best[$f->id]['value'] ?? null])
            ->all();
    }
}
