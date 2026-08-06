<?php

namespace App\Http\Controllers;

use App\Models\Requirement;
use App\Models\Training;
use App\Services\ComplianceQuery;
use App\Support\CsvExport;
use App\Support\TableReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\LaravelPdf\PdfBuilder;

/**
 * Org-wide compliance roll-ups, pivoted by training or requirement. Manager+
 * gated (mirrors the dashboard widgets). The Inertia shell ships no data; each
 * tab streams a paginated {data, meta} page from its JSON endpoint via the
 * compliance Pinia store + useServerTable. All aggregation lives in
 * ComplianceQuery (the single seam over the materialized status).
 */
class ComplianceController extends Controller
{
    private const MANAGER_PLUS_ROLES = ['Owner', 'SuperAdmin', 'Admin', 'Manager'];

    public function __construct(private readonly ComplianceQuery $compliance) {}

    public function index(Request $request): Response
    {
        $this->authorizeManager($request);

        return Inertia::render('compliance/Index');
    }

    /**
     * Per-training compliance detail shell: the training + its status tallies
     * (header chips). The user list streams in via the trainingUsers endpoint.
     */
    public function trainingDetail(Request $request, Training $training): Response
    {
        $this->authorizeManager($request);

        return Inertia::render('compliance/TrainingDetail', [
            'training' => ['id' => $training->id, 'name' => $training->name],
            'counts' => $this->compliance->trainingCounts($request->user()->organization, $training->id),
        ]);
    }

    public function byTraining(Request $request): JsonResponse
    {
        $this->authorizeManager($request);

        return response()->json(
            $this->compliance->byTraining($request->user()->organization, $this->opts($request)),
        );
    }

    /** Per-requirement compliance detail shell + its status tallies. */
    public function requirementDetail(Request $request, Requirement $requirement): Response
    {
        $this->authorizeManager($request);

        return Inertia::render('compliance/RequirementDetail', [
            'requirement' => ['id' => $requirement->id, 'name' => $requirement->name],
            'counts' => $this->compliance->requirementCounts($request->user()->organization, $requirement->id),
        ]);
    }

    /** Not-required (per-training) detail shell + its Current/Expired tallies. */
    public function notRequiredDetail(Request $request, Training $training): Response
    {
        $this->authorizeManager($request);

        return Inertia::render('compliance/NotRequiredDetail', [
            'training' => ['id' => $training->id, 'name' => $training->name],
            'counts' => $this->compliance->notRequiredCountsForTraining($request->user()->organization, $training->id),
        ]);
    }

    public function byRequirement(Request $request): JsonResponse
    {
        $this->authorizeManager($request);

        return response()->json(
            $this->compliance->byRequirement($request->user()->organization, $this->opts($request)),
        );
    }

    public function notRequired(Request $request): JsonResponse
    {
        $this->authorizeManager($request);

        return response()->json(
            $this->compliance->notRequired($request->user()->organization, $this->opts($request)),
        );
    }

    /**
     * The three roll-up tabs, each with the title, the query it runs, and the
     * bucket columns it actually has. "Not required" counts Current/Expired
     * only — giving it the five-bucket catalog would print three permanently
     * empty columns.
     */
    private const EXPORT_DIMENSIONS = [
        'training' => [
            'title' => 'Compliance — by training',
            'method' => 'byTraining',
            'columns' => [
                'name' => 'Training',
                'overdue' => 'Overdue',
                'due_soon' => 'Due soon',
                'not_started' => 'Not started',
                'current' => 'Current',
                'as_needed' => 'As needed',
                'total' => 'Total',
            ],
        ],
        'requirement' => [
            'title' => 'Compliance — by requirement',
            'method' => 'byRequirement',
            'columns' => [
                'name' => 'Requirement',
                'overdue' => 'Overdue',
                'due_soon' => 'Due soon',
                'not_started' => 'Not started',
                'current' => 'Current',
                'as_needed' => 'As needed',
                'total' => 'Total',
            ],
        ],
        'not-required' => [
            'title' => 'Compliance — not required',
            'method' => 'notRequired',
            'columns' => [
                'name' => 'Training',
                'current' => 'Current',
                'expired' => 'Expired',
                'total' => 'Taken',
            ],
        ],
    ];

    /**
     * A compliance roll-up tab as a PDF.
     *
     * Runs the tab's own ComplianceQuery method (with `all`, so the sheet isn't
     * one page of results pretending to be the whole set) and flattens the
     * nested bucket counts — the screen reads `counts.overdue`, `pdf.report`
     * reads a flat `overdue`.
     */
    public function export(Request $request): PdfBuilder
    {
        $this->authorizeManager($request);

        $key = (string) $request->query('dimension', 'training');
        abort_unless(isset(self::EXPORT_DIMENSIONS[$key]), 422, 'Unknown compliance dimension.');

        $dimension = self::EXPORT_DIMENSIONS[$key];
        $org = $request->user()->organization;

        $result = $this->compliance->{$dimension['method']}(
            $org,
            [...$this->opts($request), 'all' => true],
        );

        $rows = array_map(
            // Flatten counts up to the top level; the blade indexes rows by
            // column key and would print a blank cell for every bucket.
            fn (array $row) => [
                'name' => $row['name'],
                'total' => $row['total'],
                ...$row['counts'],
            ],
            $result['data'],
        );

        return TableReport::render(
            org: $org,
            title: $dimension['title'],
            columns: CsvExport::columns($request, $dimension['columns']),
            rows: $rows,
            filename: 'compliance-'.$key.'-'.now(config('app.display_timezone'))->format('Y-m-d').'.pdf',
            filters: $request->filled('q') ? 'Search: '.$request->query('q') : null,
            capped: count($result['data']) >= TableReport::ROW_CAP,
        );
    }

    /** Drill-down: users assigned a training, worst-status first. */
    public function trainingUsers(Request $request, Training $training): JsonResponse
    {
        $this->authorizeManager($request);

        return response()->json(
            $this->compliance->usersForTraining($request->user()->organization, $training->id, $this->pageOpts($request)),
        );
    }

    /** Drill-down: users whose assignment a requirement actively sources. */
    public function requirementUsers(Request $request, Requirement $requirement): JsonResponse
    {
        $this->authorizeManager($request);

        return response()->json(
            $this->compliance->usersForRequirement($request->user()->organization, $requirement->id, $this->pageOpts($request)),
        );
    }

    /** Drill-down: people who took a training without being required to. */
    public function notRequiredUsers(Request $request, Training $training): JsonResponse
    {
        $this->authorizeManager($request);

        return response()->json(
            $this->compliance->notRequiredUsersForTraining($request->user()->organization, $training->id, $this->pageOpts($request)),
        );
    }

    private function authorizeManager(Request $request): void
    {
        abort_unless(
            $request->user()->hasAnyRole(self::MANAGER_PLUS_ROLES),
            403,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function opts(Request $request): array
    {
        return [
            'q' => $request->query('q'),
            'sort' => $request->query('sort'),
            'dir' => $request->query('dir'),
            'page' => $request->query('page'),
            'per_page' => $request->query('per_page'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function pageOpts(Request $request): array
    {
        return [
            'page' => $request->query('page'),
            'per_page' => $request->query('per_page'),
            'q' => $request->query('q'),
            'status' => $request->query('status'),
            'tags' => $request->query('tags'),
            'tags_mode' => $request->query('tags_mode'),
        ];
    }
}
