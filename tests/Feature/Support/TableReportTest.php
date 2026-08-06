<?php

namespace Tests\Feature\Support;

use App\Models\Organization;
use App\Support\TableReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\LaravelPdf\Facades\Pdf;
use Tests\TestCase;

/**
 * The render-a-table-as-PDF recipe, extracted from ReportsController so the
 * dashboard, classes and compliance exports render through the same code
 * rather than three copies of it.
 */
class TableReportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Pdf::fake();
    }

    private const COLUMNS = [
        ['key' => 'name', 'label' => 'Name'],
        ['key' => 'due', 'label' => 'Due'],
    ];

    private function render(array $overrides = [])
    {
        $org = Organization::factory()->create(['name' => 'Barritt Group']);

        return TableReport::render(...[
            'org' => $org,
            'title' => 'Needs action',
            'columns' => self::COLUMNS,
            'rows' => [['name' => 'Lee, Sam', 'due' => '2026-09-01']],
            'filename' => 'needs-action.pdf',
            ...$overrides,
        ]);
    }

    public function test_renders_the_shared_report_view_with_org_title_and_rows(): void
    {
        $this->render()->toResponse(request());

        Pdf::assertRespondedWithPdf(
            fn ($pdf) => $pdf->viewName === 'pdf.report'
                && $pdf->viewData['org_name'] === 'Barritt Group'
                && $pdf->viewData['title'] === 'Needs action'
                && $pdf->viewData['rows'][0]['name'] === 'Lee, Sam',
        );
    }

    public function test_defaults_leave_the_optional_slots_empty(): void
    {
        // A caller that passes only the required arguments must still produce a
        // well-formed payload — the blade reads every one of these keys.
        $this->render()->toResponse(request());

        Pdf::assertRespondedWithPdf(
            fn ($pdf) => $pdf->viewData['subtitle'] === null
                && $pdf->viewData['filters'] === null
                && $pdf->viewData['groups'] === []
                && $pdf->viewData['capped'] === false,
        );
    }

    public function test_passes_subtitle_filters_and_groups_through(): void
    {
        $this->render([
            'subtitle' => 'Overdue only',
            'filters' => 'Status: Overdue',
            'groups' => [['_group' => 'CPR']],
        ])->toResponse(request());

        Pdf::assertRespondedWithPdf(
            fn ($pdf) => $pdf->viewData['subtitle'] === 'Overdue only'
                && $pdf->viewData['filters'] === 'Status: Overdue'
                && $pdf->viewData['groups'] === [['_group' => 'CPR']],
        );
    }

    public function test_reports_the_row_cap_it_actually_enforced(): void
    {
        // The blade prints "showing the first N rows" from `cap`, so the number
        // has to come from the same constant callers truncate against.
        $this->render(['capped' => true])->toResponse(request());

        Pdf::assertRespondedWithPdf(
            fn ($pdf) => $pdf->viewData['capped'] === true
                && $pdf->viewData['cap'] === TableReport::ROW_CAP,
        );
    }

    public function test_page_size_defaults_to_the_views_landscape(): void
    {
        // pdf.report defaults to landscape; passing nothing must not override
        // it, or every existing wide export silently turns portrait.
        $this->render()->toResponse(request());

        Pdf::assertRespondedWithPdf(
            fn ($pdf) => ! array_key_exists('pageSize', $pdf->viewData)
                || $pdf->viewData['pageSize'] === null,
        );
    }

    public function test_page_size_can_be_overridden_for_narrow_reports(): void
    {
        $this->render(['pageSize' => '8.5in 11in'])->toResponse(request());

        Pdf::assertRespondedWithPdf(
            fn ($pdf) => $pdf->viewData['pageSize'] === '8.5in 11in',
        );
    }
}
