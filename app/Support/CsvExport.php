<?php

namespace App\Support;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Shared column-selection + CSV streaming for every tabular export.
 *
 * Extracted from ReportsController (which owned both as private methods)
 * when the class name-check sheet became a second consumer — a copy would
 * have been two places to fix a quoting or ordering bug.
 */
final class CsvExport
{
    /**
     * Resolve which columns an export should render, honoring the caller's
     * show/hide + order passed as `columns[]`. Unknown keys are ignored
     * (whitelist against the given catalog); an empty/absent/all-unknown
     * selection falls back to the full catalog rather than rendering an
     * empty table.
     *
     * `$always` names keys that are re-inserted (in catalog order) when a
     * selection omits them — for a sheet whose entire purpose is one column,
     * deselecting that column would produce a useless file.
     *
     * `$default` overrides what "no selection" means. The reports pass
     * nothing and get the full catalog, because their on-screen table always
     * sends its own columns; a sheet reached straight from a link wants a
     * deliberately narrow default instead.
     *
     * @param  array<string, string>  $catalog  key → label whitelist
     * @param  array<int, string>  $always  keys that cannot be deselected
     * @param  array<int, string>|null  $default  keys to use when nothing is selected
     * @return array<int, array{key: string, label: string}>
     */
    public static function columns(
        Request $request,
        array $catalog,
        array $always = [],
        ?array $default = null,
    ): array {
        // `input()`, not `query()`: the exports are GET (query string) but
        // filing a sheet to a class's documents POSTs the same selection in
        // the body, and it must not be silently dropped there.
        $requested = array_values(array_filter(
            (array) $request->input('columns', []),
            fn ($k) => is_string($k) && isset($catalog[$k]),
        ));

        $keys = $requested !== []
            ? $requested
            : array_values(array_filter(
                $default ?? array_keys($catalog),
                fn (string $k) => isset($catalog[$k]),
            ));

        foreach ($always as $required) {
            if (isset($catalog[$required]) && ! in_array($required, $keys, true)) {
                // Catalog order, not appended last — a forced column reads as
                // part of the table, not as an afterthought bolted on the end.
                $keys = array_values(array_filter(
                    array_keys($catalog),
                    fn (string $k) => $k === $required || in_array($k, $keys, true),
                ));
            }
        }

        return array_map(
            fn (string $key) => ['key' => $key, 'label' => $catalog[$key]],
            $keys,
        );
    }

    /**
     * Header row from the column labels, then each yielded line written via
     * `fputcsv` against `php://output` so nothing is buffered in memory.
     * `Content-Disposition`/`Content-Type` are handled by `streamDownload()`.
     *
     * @param  array<int, array{key: string, label: string}>  $columns
     * @param  iterable<int, array<int, mixed>>  $rows  each item is one CSV line's cells
     */
    public static function stream(string $filename, array $columns, iterable $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($columns, $rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, array_column($columns, 'label'));

            foreach ($rows as $line) {
                fputcsv($out, $line);
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    /**
     * A shaped row → its cells, in the selected column order.
     *
     * @param  array<string, mixed>  $row
     * @param  array<int, array{key: string, label: string}>  $columns
     * @return array<int, mixed>
     */
    public static function cells(array $row, array $columns): array
    {
        return array_map(fn (array $col) => $row[$col['key']] ?? '', $columns);
    }
}
