<?php

namespace App\Support;

use App\Models\Organization;
use Carbon\Carbon;
use Spatie\LaravelPdf\PdfBuilder;

/**
 * Render a table of rows as the shared `pdf.report` sheet.
 *
 * Extracted from ReportsController, which owned the only copy while being the
 * only caller. The dashboard, classes and compliance exports need the same
 * header/footer/cap treatment, and three hand-copies would drift the moment
 * one of them gained a column or changed its stamp format.
 *
 * Callers own their query, columns and row shaping; this owns the page.
 */
class TableReport
{
    /**
     * Hard ceiling on rows in one PDF. Callers select ROW_CAP + 1, pass the
     * first ROW_CAP, and set `capped` when there were more — the sheet then
     * says so rather than silently truncating.
     */
    public const ROW_CAP = 2000;

    /**
     * @param  array<int, array{key: string, label: string}>  $columns
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<int, array<string, mixed>>  $groups  flattened group/row render list (empty = flat report)
     * @param  string|null  $pageSize  CSS `@page size`; null keeps pdf.report's landscape default
     */
    public static function render(
        Organization $org,
        string $title,
        array $columns,
        array $rows,
        string $filename,
        ?string $subtitle = null,
        ?string $filters = null,
        array $groups = [],
        bool $capped = false,
        ?string $pageSize = null,
    ): PdfBuilder {
        $generatedAt = Carbon::now(config('app.display_timezone'))->format('M j, Y g:i A');

        return PdfRenderer::make('pdf.report', [
            'org_name' => $org->name,
            'title' => $title,
            'subtitle' => $subtitle,
            'filters' => $filters,
            'columns' => $columns,
            'rows' => $rows,
            'groups' => $groups,
            'capped' => $capped,
            'cap' => self::ROW_CAP,
            // Left null unless asked for: pdf.report's own default is
            // landscape, and passing a value here would override it for every
            // wide export that never wanted to choose.
            'pageSize' => $pageSize,
        ])
            // Chromium's default header/footer would print the URL and page
            // chrome; the footer view carries our stamp instead.
            ->headerHtml('<span></span>')
            ->footerView('pdf.partials.footer', ['stamp' => $generatedAt, 'title' => $title])
            ->name($filename);
    }
}
