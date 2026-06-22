<?php

namespace App\Support;

/**
 * Flattens completion-report rows into a render list for the `pdf.report`
 * blade when grouping is requested. The caller passes the rows (from
 * ReportsController::reportRows()) and an ordered list of grouping keys; the
 * order is the nesting precedence (first key = outermost group).
 *
 * Output is a flat array of items so the blade can iterate once:
 *   ['type' => 'group', 'level' => int, 'label' => string, 'count' => int]
 *   ['type' => 'row',   'data'  => array<string, mixed>]
 *
 * Groups are sorted by their display value (case-insensitive) and leaf rows
 * keep their incoming order. `user` groups on `user_id` (so two people with
 * the same display name stay distinct) while every other key groups on its
 * own value.
 */
class ReportGrouping
{
    /** Groupable keys → header label prefix. Also the validation whitelist. */
    private const LABELS = [
        'training' => 'Training',
        'status' => 'Status',
        'user' => 'User',
        'location' => 'Location',
        'department' => 'Department',
    ];

    /**
     * Keep only known grouping keys, in the given order, without duplicates.
     *
     * @param  array<int, mixed>  $keys
     * @return array<int, string>
     */
    public static function sanitize(array $keys): array
    {
        $out = [];
        foreach ($keys as $key) {
            if (is_string($key) && isset(self::LABELS[$key]) && ! in_array($key, $out, true)) {
                $out[] = $key;
            }
        }

        return $out;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<int, mixed>  $groupBy  ordered grouping keys (precedence)
     * @return array<int, array<string, mixed>>
     */
    public static function flatten(array $rows, array $groupBy): array
    {
        return self::build($rows, self::sanitize($groupBy), 0);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<int, string>  $keys
     * @return array<int, array<string, mixed>>
     */
    private static function build(array $rows, array $keys, int $level): array
    {
        if ($keys === []) {
            return array_map(fn (array $r) => ['type' => 'row', 'data' => $r], $rows);
        }

        $key = $keys[0];
        $rest = array_slice($keys, 1);
        $items = [];

        foreach (self::bucketize($rows, $key) as $bucket) {
            $items[] = [
                'type' => 'group',
                'level' => $level,
                'label' => self::LABELS[$key].': '.self::displayOrNone($bucket['display']),
                'count' => count($bucket['rows']),
            ];
            $items = array_merge($items, self::build($bucket['rows'], $rest, $level + 1));
        }

        return $items;
    }

    /**
     * Group rows by a key, sorted by display value. `user` groups on user_id
     * (label shows the name); every other key groups on its own value.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, array{display: string, rows: array<int, array<string, mixed>>}>
     */
    private static function bucketize(array $rows, string $key): array
    {
        $buckets = [];
        foreach ($rows as $row) {
            $identity = (string) ($key === 'user' ? ($row['user_id'] ?? '') : ($row[$key] ?? ''));
            $display = (string) ($key === 'user' ? ($row['user'] ?? '') : ($row[$key] ?? ''));

            if (! isset($buckets[$identity])) {
                $buckets[$identity] = ['display' => $display, 'rows' => []];
            }
            $buckets[$identity]['rows'][] = $row;
        }

        uasort($buckets, fn (array $a, array $b) => strcasecmp($a['display'], $b['display']));

        return $buckets;
    }

    /** Blank / em-dash placeholder values read as "(none)" in the header. */
    private static function displayOrNone(string $display): string
    {
        $display = trim($display);

        return ($display === '' || $display === '—') ? '(none)' : $display;
    }
}
