<?php

namespace App\Http\Controllers;

use App\Models\Completion;
use App\Models\Training;
use App\Support\CompletionSerializer;
use App\Support\PdfRenderer;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Spatie\LaravelPdf\PdfBuilder;

/**
 * Exportable PDF reports (T1). Each action assembles rows and streams a PDF via
 * the shared `pdf.report` table view. Manager+ gated (mirrors the dashboard +
 * compliance pages). Synchronous generation with a row cap — large datasets get
 * a "narrow your filters" note rather than a timeout; queueing can come later if
 * real data hits the cap.
 */
class ReportsController extends Controller
{
    private const MANAGER_PLUS_ROLES = ['Owner', 'SuperAdmin', 'Admin', 'Manager'];

    private const ROW_CAP = 2000;

    /**
     * Training record — everyone who has completed a given training, newest
     * first (who took it, when, expiry, hours, class, cert id).
     */
    public function trainingRecord(Request $request, Training $training): PdfBuilder
    {
        $this->authorizeManager($request);
        $org = $request->user()->organization;

        $completions = Completion::query()
            ->where('org_id', $org->id)
            ->where('module_type', Training::class)
            ->where('module_id', $training->id)
            ->with(['user:id,prefix_name,f_name,m_name,l_name,suffix_name', 'rqmtElements:id'])
            ->orderByDesc('completion_date')
            ->limit(self::ROW_CAP + 1)
            ->get();

        $capped = $completions->count() > self::ROW_CAP;
        $completions = $completions->take(self::ROW_CAP);

        $names = $completions->pluck('user', 'user_id')->map(fn ($u) => $u?->sort_name);
        $rows = collect(CompletionSerializer::collection($completions))
            ->map(fn (array $r) => [
                'user' => $names[$r['user_id']] ?? '—',
                'completion_date' => $r['completion_date'] ?? '—',
                'expire_date' => $r['expire_date'] ?? '—',
                'hours' => $r['hours'] ?? '—',
                'class' => $r['class_name'] ?? '—',
                'cert_id' => $r['cert_id'] ?? '—',
            ])
            ->all();

        return $this->tableReport(
            org: $org,
            title: 'Training record',
            subtitle: $training->name,
            columns: [
                ['key' => 'user', 'label' => 'User'],
                ['key' => 'completion_date', 'label' => 'Completed'],
                ['key' => 'expire_date', 'label' => 'Expires'],
                ['key' => 'hours', 'label' => 'Hours'],
                ['key' => 'class', 'label' => 'Class'],
                ['key' => 'cert_id', 'label' => 'Cert ID'],
            ],
            rows: $rows,
            capped: $capped,
            filename: 'training-record-'.$training->id.'.pdf',
        );
    }

    /**
     * Render the shared tabular report PDF with a repeating page footer.
     *
     * @param  array<int, array{key: string, label: string}>  $columns
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function tableReport(
        \App\Models\Organization $org,
        string $title,
        ?string $subtitle,
        array $columns,
        array $rows,
        bool $capped,
        string $filename,
        ?string $filters = null,
    ): PdfBuilder {
        $generatedAt = Carbon::now(config('app.display_timezone'))->format('M j, Y g:i A');

        $pdf = PdfRenderer::make('pdf.report', [
            'org_name' => $org->name,
            'title' => $title,
            'subtitle' => $subtitle,
            'filters' => $filters,
            'columns' => $columns,
            'rows' => $rows,
            'capped' => $capped,
            'cap' => self::ROW_CAP,
        ]);

        return $pdf
            ->headerHtml('<span></span>')
            ->footerView('pdf.partials.footer', ['stamp' => $generatedAt, 'title' => $title])
            ->name($filename);
    }

    private function authorizeManager(Request $request): void
    {
        abort_unless(
            $request->user()->hasAnyRole(self::MANAGER_PLUS_ROLES),
            403,
        );
    }
}
