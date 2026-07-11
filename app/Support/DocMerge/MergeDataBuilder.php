<?php

namespace App\Support\DocMerge;

use App\Models\MergeField;
use App\Models\Organization;
use App\Support\MergeData\MergeValueResolver;
use Illuminate\Support\Carbon;

/**
 * Bridges the merge-field registry (Phase D1) to the merge engine:
 * resolves the org's values for a (location, department) variation and
 * shapes them for TBS.
 *
 *  - fields: key => string. Unset -> visible '--KEY--' (the demo's
 *    fallback convention — gaps must be obvious in the output).
 *    List values are comma-joined for inline `[m.key]` usage.
 *  - listRows: list-field key => [['item' => v], ...] for the repeating
 *    blocks the translator mints (empty list -> block disappears).
 *  - Computed generation-time values (doc_date etc.) are added here —
 *    they are not stored merge fields (see SystemMergeFields).
 *  - Legacy alias: the demo templates' ${EMS_direct_phone} keeps working
 *    until Phase M rewrites them (maps to ems_direct_phone).
 */
class MergeDataBuilder
{
    /**
     * Keys computed at generation time — never stored merge fields, and
     * excluded from template-upload draft registration.
     */
    public const COMPUTED_KEYS = ['doc_date', 'doc_date_my', 'foot_date', 'copy_date', 'today_date'];

    public function __construct(private readonly MergeValueResolver $resolver) {}

    /**
     * @return array{fields: array<string, string>, listRows: array<string, array<int, array<string, string>>>}
     */
    public function build(Organization $org, ?string $location = null, ?string $department = null): array
    {
        $resolved = $this->resolver->resolve($org->id, $location, $department);

        $types = MergeField::query()
            ->visibleTo($org->id)
            ->pluck('type', 'key');

        $fields = [];
        $listRows = [];

        foreach ($resolved as $key => $value) {
            if (($types[$key] ?? null) === 'list') {
                $items = is_array($value) ? array_values(array_filter($value, fn ($v) => $v !== '')) : [];
                $fields[$key] = $items === []
                    ? $this->placeholder($key)
                    : implode(', ', $items);
                $listRows[$key] = array_map(
                    fn (string $item) => [TemplateTranslator::BLOCK_ROW_KEY => $item],
                    $items,
                );

                continue;
            }

            $fields[$key] = ($value === null || $value === '')
                ? $this->placeholder($key)
                : (string) $value;
        }

        // Legacy template alias (Phase M rewrites the templates; until
        // then the old mixed-case token resolves to the same value).
        if (isset($fields['ems_direct_phone'])) {
            $fields['EMS_direct_phone'] = $fields['ems_direct_phone'];
        }

        return ['fields' => [...$fields, ...$this->computed($org)], 'listRows' => $listRows];
    }

    /**
     * Generation-time values, in the org's timezone.
     *
     * @return array<string, string>
     */
    private function computed(Organization $org): array
    {
        $now = Carbon::now($org->timezone ?? 'UTC');

        return [
            'doc_date' => $now->format('Y-m-d'),
            'doc_date_my' => $now->format('F Y'),
            'foot_date' => $now->format('Ymd'),
            'copy_date' => $now->format('Y'),
            'today_date' => $now->format('Y-m-d'),
        ];
    }

    private function placeholder(string $key): string
    {
        return '--'.strtoupper($key).'--';
    }
}
