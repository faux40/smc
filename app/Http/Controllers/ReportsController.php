<?php

namespace App\Http\Controllers;

use App\Models\Completion;
use App\Models\Organization;
use App\Models\Requirement;
use App\Models\Training;
use App\Models\TrainingAssignment;
use App\Models\User;
use App\Services\ComplianceQuery;
use App\Services\TrainingStatusService;
use App\Support\CompletionSerializer;
use App\Support\CsvExport;
use App\Support\ExpiryStatus;
use App\Support\ReportGrouping;
use App\Support\TableReport;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\LaravelPdf\PdfBuilder;
use Symfony\Component\HttpFoundation\StreamedResponse;

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

    /** Single source of truth lives with the renderer that prints "showing the first N". */
    private const ROW_CAP = TableReport::ROW_CAP;

    public function __construct(
        private readonly ComplianceQuery $compliance,
        private readonly TrainingStatusService $status,
    ) {}

    /** Reports landing (Manager+) — the org completion report + filters. */
    public function index(Request $request): Response
    {
        $this->authorizeManager($request);

        return Inertia::render('reports/Index');
    }

    /**
     * Org completion report — on-screen paginated JSON ({data, meta}). Filters:
     * date range (from/to), training-name search (q), user search (user_q), tags.
     */
    public function completions(Request $request): JsonResponse
    {
        $this->authorizeManager($request);
        $org = $request->user()->organization;

        $base = $this->completionsQuery($request, $org)
            ->with($this->completionRelations());

        $perPage = max(1, min(100, (int) $request->query('per_page', 25)));
        $page = $base->paginate($perPage);

        return response()->json([
            'data' => $this->reportRows($page->getCollection(), $org),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    /**
     * Org completion report — full filtered set as a PDF (capped), or as a
     * streamed CSV when `?format=csv` is present. Both branches share the
     * exact same filters (completionsQuery), column resolution
     * (selectedColumns), and row shaping (reportRows) — only the rendering
     * differs.
     */
    public function completionsExport(Request $request): PdfBuilder|StreamedResponse
    {
        $this->authorizeManager($request);
        $org = $request->user()->organization;

        if ($request->query('format') === 'csv') {
            return $this->completionsExportCsv($request, $org);
        }

        $all = $this->completionsQuery($request, $org)
            ->with($this->completionRelations())
            ->limit(self::ROW_CAP + 1)
            ->get();

        $capped = $all->count() > self::ROW_CAP;
        $rows = $this->reportRows($all->take(self::ROW_CAP), $org);

        // Optional grouping: `group_by[]` in precedence order. Empty/unknown →
        // no grouping (flat report). The blade renders group bands from `groups`.
        $groupBy = ReportGrouping::sanitize((array) $request->query('group_by', []));

        return TableReport::render(
            org: $org,
            title: 'Completion report',
            subtitle: $this->dateRangeLabel($request),
            columns: $this->selectedColumns($request, self::COMPLETION_COLUMNS),
            rows: $rows,
            capped: $capped,
            filename: 'completion-report.pdf',
            filters: $this->filterSummary($request),
            groups: $groupBy !== [] ? ReportGrouping::flatten($rows, $groupBy) : [],
        );
    }

    /**
     * Org completion report as a streamed CSV — same filters/columns as the
     * PDF export, via `fputcsv` against `php://output` so nothing is buffered
     * in memory. `Content-Disposition`/`Content-Type` are handled by
     * `streamDownload()`.
     *
     * Grouping (`group_by[]`) is represented as label rows interleaved with
     * data rows — e.g. a row whose sole cell reads "Location: Yard (2)"
     * followed by that bucket's data rows — mirroring the PDF's group bands
     * exactly (same `ReportGrouping::flatten` output, just rendered as CSV
     * rows instead of a colspan `<tr>`). This was chosen over flattening
     * group keys into leading columns because several groupable keys (e.g.
     * `status`) are derived in PHP (ExpiryStatus), not a SQL column, so a
     * true global sort-by-group-then-stream isn't possible without either
     * duplicating that derivation as SQL or materializing the full result
     * set first. Grouped exports therefore materialize like the PDF path
     * does (capped at ROW_CAP) — grouping is the less common, "narrower"
     * export shape. The common flat (ungrouped) export streams from a
     * chunked query and is NOT row-capped, since CSV rows are cheap and
     * chunking already bounds memory regardless of row count; if a flat
     * export exceeds the PDF's ROW_CAP, that's logged for visibility.
     */
    private function completionsExportCsv(Request $request, Organization $org): StreamedResponse
    {
        $columns = $this->selectedColumns($request, self::COMPLETION_COLUMNS);
        $groupBy = ReportGrouping::sanitize((array) $request->query('group_by', []));
        $filename = 'completions-'.Carbon::now(config('app.display_timezone'))->format('Y-m-d').'.csv';

        $lines = $groupBy !== []
            ? $this->completionsGroupedCsvLines($request, $org, $columns, $groupBy)
            : $this->completionsFlatCsvLines($request, $org, $columns);

        return $this->streamCsv($filename, $columns, $lines);
    }

    /**
     * @param  array<int, array{key: string, label: string}>  $columns
     * @param  iterable<int, array<int, mixed>>  $rows  each item is one CSV line's cells
     */
    private function streamCsv(string $filename, array $columns, iterable $rows): StreamedResponse
    {
        return CsvExport::stream($filename, $columns, $rows);
    }

    /**
     * A shaped report row → its CSV cells, in the selected column order.
     *
     * @param  array<string, mixed>  $row
     * @param  array<int, array{key: string, label: string}>  $columns
     * @return array<int, mixed>
     */
    private function rowToCsv(array $row, array $columns): array
    {
        return CsvExport::cells($row, $columns);
    }

    /**
     * Grouped shaped rows → interleaved CSV lines: a one-cell group-label row
     * ("Location: Yard (2)") followed by that bucket's data rows, mirroring the
     * PDF's group bands exactly (same `ReportGrouping::flatten` output). Shared
     * by the completion + compliance-status CSV exports.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<int, string>  $groupBy
     * @param  array<int, array{key: string, label: string}>  $columns
     * @return \Generator<int, array<int, mixed>>
     */
    private function groupedCsvLines(array $rows, array $groupBy, array $columns, bool $capped): \Generator
    {
        foreach (ReportGrouping::flatten($rows, $groupBy) as $item) {
            if ($item['type'] === 'group') {
                yield [$item['label'].' ('.$item['count'].')'];
            } else {
                yield $this->rowToCsv($item['data'], $columns);
            }
        }

        if ($capped) {
            yield ['Showing the first '.self::ROW_CAP.' rows — narrow your filters to see the rest.'];
        }
    }

    /**
     * Ungrouped completion CSV lines: streamed straight from a chunked query in
     * the shared filter/order, so an arbitrarily large org never holds more
     * than one chunk of completions in memory at a time.
     *
     * @param  array<int, array{key: string, label: string}>  $columns
     * @return \Generator<int, array<int, mixed>>
     */
    private function completionsFlatCsvLines(Request $request, Organization $org, array $columns): \Generator
    {
        $total = 0;

        foreach ($this->completionsQuery($request, $org)->with($this->completionRelations())->lazy(500)->chunk(500) as $chunk) {
            $collection = new Collection($chunk->all());
            foreach ($this->reportRows($collection, $org) as $row) {
                yield $this->rowToCsv($row, $columns);
            }
            $total += $collection->count();
        }

        if ($total > self::ROW_CAP) {
            Log::info('CSV completion export exceeded the PDF row cap', [
                'org_id' => $org->id,
                'rows' => $total,
            ]);
        }
    }

    /**
     * Grouped completion CSV lines: materializes the same capped row set the PDF
     * export uses and renders it through the shared group flattener.
     *
     * @param  array<int, array{key: string, label: string}>  $columns
     * @param  array<int, string>  $groupBy
     * @return \Generator<int, array<int, mixed>>
     */
    private function completionsGroupedCsvLines(Request $request, Organization $org, array $columns, array $groupBy): \Generator
    {
        $all = $this->completionsQuery($request, $org)
            ->with($this->completionRelations())
            ->limit(self::ROW_CAP + 1)
            ->get();

        $capped = $all->count() > self::ROW_CAP;
        $rows = $this->reportRows($all->take(self::ROW_CAP), $org);

        yield from $this->groupedCsvLines($rows, $groupBy, $columns, $capped);
    }

    /**
     * Relations eager-loaded for the completion report's on-screen JSON, PDF
     * export, and CSV export alike (user identity columns + tags + the
     * elements needed for CompletionSerializer). Centralized so the three
     * callers can't drift.
     *
     * @return array<int, string>
     */
    private function completionRelations(): array
    {
        return [
            'user:id,prefix_name,f_name,m_name,l_name,suffix_name,employee_number,department,location',
            'user.tags:id,name',
            'rqmtElements:id',
        ];
    }

    /**
     * The completion report's full column catalog (key → label), in default
     * order. The on-screen table mirrors these keys via useTableView.
     *
     * @var array<string, string>
     */
    private const COMPLETION_COLUMNS = [
        'user' => 'User',
        'employee_number' => 'Employee #',
        'department' => 'Department',
        'location' => 'Location',
        'training' => 'Training',
        'completion_date' => 'Completed',
        'expire_date' => 'Expires',
        'status' => 'Status',
        'tags' => 'Tags',
        'hours' => 'Hours',
        'class' => 'Class',
        'cert_id' => 'Cert ID',
    ];

    /**
     * The compliance-status report's column catalog (key → label), in default
     * order. Distinct from the completion catalog: this report is one row per
     * (user, assigned training) with the *current* status + due date + source,
     * not a completion record. `expires_at` reads "Expires / Due" to make clear
     * it's the forward-looking due date (F12 date-field clarity), not a
     * completed date.
     *
     * @var array<string, string>
     */
    private const COMPLIANCE_STATUS_COLUMNS = [
        'user' => 'User',
        'employee_number' => 'Employee #',
        'department' => 'Department',
        'location' => 'Location',
        'training' => 'Training',
        'status' => 'Status',
        'expires_at' => 'Expires / Due',
        'days_until_due' => 'Days until due',
        'source' => 'Source',
    ];

    /**
     * Resolve which columns the export should render, honoring the on-screen
     * column show/hide + order passed as `columns[]`. Unknown keys are ignored
     * (whitelist against the given catalog); an empty/absent/all-unknown
     * selection falls back to the full catalog rather than rendering an empty
     * table.
     *
     * @param  array<string, string>  $catalog  key → label whitelist for this report
     * @return array<int, array{key: string, label: string}>
     */
    private function selectedColumns(Request $request, array $catalog): array
    {
        return CsvExport::columns($request, $catalog);
    }

    /**
     * Filtered completions query (Training modules only), newest first. Shared
     * by the on-screen table + the PDF export. The module_id↔trainings.id and
     * user.id↔taggables joins cast uuid→text (Postgres won't auto-cast).
     *
     * @return Builder<Completion>
     */
    private function completionsQuery(Request $request, Organization $org): Builder
    {
        $query = Completion::query()
            ->where('completions.org_id', $org->id)
            ->where('completions.module_type', Training::class);

        if ($from = $request->query('from')) {
            // Normalize to a plain Y-m-d string: completion_date is a `date`
            // column, and a plain `where` (no `::date` cast) is needed for the
            // comparison to use completions_org_completion_date_idx.
            $query->where('completions.completion_date', '>=', Carbon::parse($from)->toDateString());
        }
        if ($to = $request->query('to')) {
            $query->where('completions.completion_date', '<=', Carbon::parse($to)->toDateString());
        }

        if ($tq = trim((string) $request->query('q', ''))) {
            $like = '%'.mb_strtolower($tq).'%';
            $query->whereExists(fn ($s) => $s->select(DB::raw(1))
                ->from('trainings as t')
                ->whereRaw('CAST(t.id AS text) = completions.module_id')
                ->whereRaw('LOWER(t.name) LIKE ?', [$like]));
        }

        if ($uq = trim((string) $request->query('user_q', ''))) {
            $like = '%'.mb_strtolower($uq).'%';
            $query->whereExists(fn ($s) => $s->select(DB::raw(1))
                ->from('users as u')
                ->whereColumn('u.id', 'completions.user_id')
                ->where(function ($w) use ($like) {
                    foreach (['f_name', 'm_name', 'l_name', 'email', 'employee_number', 'department', 'location'] as $col) {
                        $w->orWhereRaw("LOWER(u.{$col}) LIKE ?", [$like]);
                    }
                }));
        }

        $tagIds = array_values(array_filter((array) $request->query('tags', []), fn ($v) => is_string($v) && $v !== ''));
        if ($tagIds !== []) {
            $mode = in_array($request->query('tags_mode'), ['and', 'or', 'not'], true) ? $request->query('tags_mode') : 'and';
            $sub = fn ($s, array $ids) => $s->select(DB::raw(1))
                ->from('taggables')
                ->join('tags', 'tags.id', '=', 'taggables.tag_id')
                ->whereRaw('taggables.taggable_id = CAST(completions.user_id AS text)')
                ->where('taggables.taggable_type', User::class)
                ->whereNull('tags.deleted_at')
                ->whereIn('tags.id', $ids);
            if ($mode === 'and') {
                foreach ($tagIds as $id) {
                    $query->whereExists(fn ($s) => $sub($s, [$id]));
                }
            } elseif ($mode === 'or') {
                $query->whereExists(fn ($s) => $sub($s, $tagIds));
            } else {
                $query->whereNotExists(fn ($s) => $sub($s, $tagIds));
            }
        }

        $this->applyStatusFilter($query, $request, $org);

        return $query->orderByDesc('completions.completion_date')->orderBy('completions.id');
    }

    /**
     * Filter by derived expiry status (any-of). Status isn't stored, so each
     * selected key maps to an `expire_date` predicate using the same boundaries
     * as ExpiryStatus: expired = past; due_soon = today..today+soonDays;
     * current = no expiry OR beyond the window. Multiple statuses are OR'd.
     *
     * @param  Builder<Completion>  $query
     */
    private function applyStatusFilter(Builder $query, Request $request, Organization $org): void
    {
        $statuses = array_values(array_filter(
            (array) $request->query('statuses', []),
            fn ($v) => in_array($v, ['expired', 'due_soon', 'current'], true),
        ));
        if ($statuses === []) {
            return;
        }

        $today = Carbon::now()->startOfDay()->toDateString();
        $boundary = Carbon::parse($today)->addDays($org->expiringSoonDays())->toDateString();
        $col = 'completions.expire_date';

        $query->where(function ($outer) use ($statuses, $today, $boundary, $col) {
            foreach ($statuses as $status) {
                $outer->orWhere(function ($w) use ($status, $today, $boundary, $col) {
                    if ($status === 'expired') {
                        $w->whereNotNull($col)->where($col, '<', $today);
                    } elseif ($status === 'due_soon') {
                        $w->whereNotNull($col)
                            ->where($col, '>=', $today)
                            ->where($col, '<=', $boundary);
                    } else { // current — no expiry on record, or beyond the window
                        // F9 invariant: a null expire_date only remains possible
                        // for trainings with no repeat frequency (initial-only /
                        // as-needed) — CompletionsController::store/update/
                        // bulkStore and CompleteClass all default expire_date to
                        // completion_date + repeat_days at write time (via
                        // ExpiryCalculator) whenever the training repeats, so a
                        // null here means "genuinely never expires", not "field
                        // left blank". Do not change this bucketing without
                        // re-verifying that invariant still holds. (Completions
                        // recorded before this default existed are an untouched
                        // exception — see the F9 rollout notes.)
                        $w->whereNull($col)->orWhere($col, '>', $boundary);
                    }
                });
            }
        });
    }

    /**
     * Map completions to rich report rows — user (+ identifying columns),
     * training, dates, an expiry Status label, and a `_band` colour key for row
     * shading. Callers pick which columns to show; extra keys are ignored by the
     * blade. Users must be eager-loaded (id + name parts + employee_number /
     * department / location).
     *
     * @param  Collection<int, Completion>  $completions
     * @return array<int, array<string, mixed>>
     */
    private function reportRows(Collection $completions, Organization $org): array
    {
        $users = $completions->pluck('user', 'user_id');
        $soonDays = $org->expiringSoonDays();
        $today = Carbon::now()->startOfDay()->toDateString();

        return collect(CompletionSerializer::collection($completions))
            ->map(function (array $r) use ($users, $soonDays, $today) {
                $u = $users[$r['user_id']] ?? null;
                $status = ExpiryStatus::for($r['expire_date'] ?? null, $soonDays, $today);

                return [
                    'id' => $r['id'],
                    'user_id' => $r['user_id'],
                    // Tag IDs for the on-screen list (hydrates the tags store so
                    // pills render); only the JSON query eager-loads user.tags —
                    // PDF callers don't, so guard to avoid an N+1 lazy load.
                    'tag_ids' => $u && $u->relationLoaded('tags')
                        ? $u->tags->pluck('id')->all()
                        : [],
                    // Tag names joined, for the server-rendered PDF column
                    // (the on-screen list renders pills from tag_ids instead).
                    'tags' => $u && $u->relationLoaded('tags') && $u->tags->isNotEmpty()
                        ? $u->tags->pluck('name')->implode(', ')
                        : '—',
                    'user' => $u?->sort_name ?? '—',
                    'employee_number' => $u?->employee_number ?? '—',
                    'department' => $u?->department ?? '—',
                    'location' => $u?->location ?? '—',
                    'training' => $r['training_name'] ?? '—',
                    'completion_date' => $r['completion_date'] ?? '—',
                    'expire_date' => $r['expire_date'] ?? '—',
                    'status' => $status['label'],
                    'hours' => $r['hours'] ?? '—',
                    'class' => $r['class_name'] ?? '—',
                    'cert_id' => $r['cert_id'] ?? '—',
                    '_band' => $status['key'],
                ];
            })
            ->all();
    }

    private function dateRangeLabel(Request $request): ?string
    {
        $from = $request->query('from');
        $to = $request->query('to');
        if (! $from && ! $to) {
            return null;
        }

        return trim(($from ?: 'start').' → '.($to ?: 'now'));
    }

    private function filterSummary(Request $request): ?string
    {
        $parts = [];
        if ($label = $this->dateRangeLabel($request)) {
            $parts[] = 'Dates: '.$label;
        }
        if ($q = trim((string) $request->query('q', ''))) {
            $parts[] = 'Training: '.$q;
        }
        if ($uq = trim((string) $request->query('user_q', ''))) {
            $parts[] = 'User: '.$uq;
        }
        $statusLabels = ['expired' => 'Expired', 'due_soon' => 'Expires soon', 'current' => 'Current'];
        $statuses = array_values(array_filter(
            (array) $request->query('statuses', []),
            fn ($v) => isset($statusLabels[$v]),
        ));
        if ($statuses !== []) {
            $parts[] = 'Status: '.implode(', ', array_map(fn ($s) => $statusLabels[$s], $statuses));
        }

        return $parts === [] ? null : implode('   ·   ', $parts);
    }

    // ------------------------------------------------------------------
    // F12 — compliance-status snapshot (the audit document): every employee ×
    // each assigned training with its CURRENT status / due date / source,
    // including never-started people. Dataset comes from ComplianceQuery's TA
    // snapshot (no parallel status derivation); rendered on-screen, as PDF, and
    // as CSV via the same seams the completion report uses.
    // ------------------------------------------------------------------

    /**
     * Compliance-status report — on-screen paginated JSON ({data, meta}).
     * Filters mirror the compliance screens (status multi-select, tag, search)
     * plus optional requirement_id / training_id scope.
     */
    public function complianceStatus(Request $request): JsonResponse
    {
        $this->authorizeManager($request);
        $org = $request->user()->organization;

        $perPage = max(1, min(100, (int) $request->query('per_page', 25)));
        $page = $this->compliance
            ->statusReportQuery($org, $this->complianceStatusOpts($request))
            ->paginate($perPage);

        return response()->json([
            'data' => $this->complianceStatusRows($page->getCollection()),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    /**
     * Compliance-status report — full filtered set as a PDF (capped), or a
     * streamed CSV when `?format=csv`. Both branches share the same filters
     * (statusReportQuery), column resolution, row shaping, and grouping — only
     * the rendering differs. Filename `compliance-status-YYYY-MM-DD.{pdf,csv}`.
     */
    public function complianceStatusExport(Request $request): PdfBuilder|StreamedResponse
    {
        $this->authorizeManager($request);
        $org = $request->user()->organization;
        $opts = $this->complianceStatusOpts($request);
        $columns = $this->selectedColumns($request, self::COMPLIANCE_STATUS_COLUMNS);
        $groupBy = ReportGrouping::sanitize((array) $request->query('group_by', []));
        $date = Carbon::now(config('app.display_timezone'))->format('Y-m-d');

        if ($request->query('format') === 'csv') {
            $lines = $groupBy !== []
                ? $this->complianceStatusGroupedCsvLines($org, $opts, $columns, $groupBy)
                : $this->complianceStatusFlatCsvLines($org, $opts, $columns);

            return $this->streamCsv('compliance-status-'.$date.'.csv', $columns, $lines);
        }

        $all = $this->compliance->statusReportQuery($org, $opts)
            ->limit(self::ROW_CAP + 1)
            ->get();

        $capped = $all->count() > self::ROW_CAP;
        $rows = $this->complianceStatusRows($all->take(self::ROW_CAP));

        return TableReport::render(
            org: $org,
            title: 'Compliance status',
            subtitle: $this->complianceStatusSubtitle($org, $opts),
            columns: $columns,
            rows: $rows,
            capped: $capped,
            filename: 'compliance-status-'.$date.'.pdf',
            filters: $this->complianceStatusFilterSummary($request),
            groups: $groupBy !== [] ? ReportGrouping::flatten($rows, $groupBy) : [],
        );
    }

    /**
     * Parse the compliance-status request into the ComplianceQuery opts shape.
     *
     * @return array<string, mixed>
     */
    private function complianceStatusOpts(Request $request): array
    {
        return [
            'statuses' => (array) $request->query('statuses', []),
            'q' => (string) $request->query('q', ''),
            'tags' => (array) $request->query('tags', []),
            'tags_mode' => $request->query('tags_mode'),
            'requirement_id' => $request->query('requirement_id'),
            'training_id' => $request->query('training_id'),
        ];
    }

    /**
     * Map compliance-status TAs → report rows: user identity, training, the
     * labeled current status (canonical vocabulary), the expiry/due date,
     * signed days-until-due (negative = overdue), and the source (requirement
     * name(s), or "Direct"). Users must be eager-loaded (statusReportQuery
     * does this); `activeSources.sourceable` supplies the source names.
     *
     * @param  Collection<int, TrainingAssignment>  $tas
     * @return array<int, array<string, mixed>>
     */
    private function complianceStatusRows(Collection $tas): array
    {
        return $tas->map(function (TrainingAssignment $ta) {
            $u = $ta->user;

            $sources = $ta->relationLoaded('activeSources')
                ? $ta->activeSources
                    ->filter(fn ($s) => $s->sourceable_type === Requirement::class)
                    ->map(fn ($s) => $s->sourceable?->name)
                    ->filter()
                    ->unique()
                    ->values()
                : collect();

            $days = $this->status->daysUntilDue($ta);

            return [
                'id' => $ta->id,
                'user_id' => $ta->user_id,
                'training_id' => $ta->training_id,
                'tag_ids' => $u && $u->relationLoaded('tags')
                    ? $u->tags->pluck('id')->all()
                    : [],
                'user' => $u?->sort_name ?? '—',
                'employee_number' => $u?->employee_number ?? '—',
                'department' => $u?->department ?? '—',
                'location' => $u?->location ?? '—',
                'training' => $ta->name ?? '—',
                'status' => TrainingStatusService::LABELS[$ta->status] ?? $ta->status,
                // Raw bucket key for the on-screen badge (`_band`).
                'status_key' => $ta->status,
                'expires_at' => $ta->expires_at?->toDateString() ?? '—',
                'days_until_due' => $days === null ? '—' : (string) $days,
                'source' => $sources->isNotEmpty() ? $sources->implode(', ') : 'Direct',
                '_band' => $ta->status,
            ];
        })->all();
    }

    /**
     * Ungrouped compliance-status CSV lines — streamed from a chunked query so
     * a large org never holds more than one chunk of TAs in memory.
     *
     * @param  array<string, mixed>  $opts
     * @param  array<int, array{key: string, label: string}>  $columns
     * @return \Generator<int, array<int, mixed>>
     */
    private function complianceStatusFlatCsvLines(Organization $org, array $opts, array $columns): \Generator
    {
        $total = 0;

        foreach ($this->compliance->statusReportQuery($org, $opts)->lazy(500)->chunk(500) as $chunk) {
            $collection = new Collection($chunk->all());
            foreach ($this->complianceStatusRows($collection) as $row) {
                yield $this->rowToCsv($row, $columns);
            }
            $total += $collection->count();
        }

        if ($total > self::ROW_CAP) {
            Log::info('CSV compliance-status export exceeded the PDF row cap', [
                'org_id' => $org->id,
                'rows' => $total,
            ]);
        }
    }

    /**
     * Grouped compliance-status CSV lines — materializes the same capped set the
     * PDF export uses and renders it through the shared group flattener.
     *
     * @param  array<string, mixed>  $opts
     * @param  array<int, array{key: string, label: string}>  $columns
     * @param  array<int, string>  $groupBy
     * @return \Generator<int, array<int, mixed>>
     */
    private function complianceStatusGroupedCsvLines(Organization $org, array $opts, array $columns, array $groupBy): \Generator
    {
        $all = $this->compliance->statusReportQuery($org, $opts)
            ->limit(self::ROW_CAP + 1)
            ->get();

        $capped = $all->count() > self::ROW_CAP;
        $rows = $this->complianceStatusRows($all->take(self::ROW_CAP));

        yield from $this->groupedCsvLines($rows, $groupBy, $columns, $capped);
    }

    /**
     * Subtitle for the scoped export: the requirement or training name when the
     * report is scoped to one (e.g. the RequirementDetail export), else null.
     *
     * @param  array<string, mixed>  $opts
     */
    private function complianceStatusSubtitle(Organization $org, array $opts): ?string
    {
        if (! empty($opts['requirement_id'])) {
            return Requirement::where('org_id', $org->id)
                ->whereKey($opts['requirement_id'])
                ->value('name');
        }
        if (! empty($opts['training_id'])) {
            return Training::where('org_id', $org->id)
                ->whereKey($opts['training_id'])
                ->value('name');
        }

        return null;
    }

    private function complianceStatusFilterSummary(Request $request): ?string
    {
        $parts = [];

        $statuses = array_values(array_filter(
            (array) $request->query('statuses', []),
            fn ($v) => isset(TrainingStatusService::LABELS[$v]),
        ));
        if ($statuses !== []) {
            $parts[] = 'Status: '.implode(', ', array_map(fn ($s) => TrainingStatusService::LABELS[$s], $statuses));
        }
        if ($q = trim((string) $request->query('q', ''))) {
            $parts[] = 'Search: '.$q;
        }

        return $parts === [] ? null : implode('   ·   ', $parts);
    }

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
            ->with(['user:id,prefix_name,f_name,m_name,l_name,suffix_name,employee_number,department,location', 'rqmtElements:id'])
            ->orderByDesc('completion_date')
            ->limit(self::ROW_CAP + 1)
            ->get();

        $capped = $completions->count() > self::ROW_CAP;
        $completions = $completions->take(self::ROW_CAP);

        return TableReport::render(
            org: $org,
            title: 'Training record',
            subtitle: $training->name,
            columns: [
                ['key' => 'user', 'label' => 'User'],
                ['key' => 'employee_number', 'label' => 'Employee #'],
                ['key' => 'department', 'label' => 'Department'],
                ['key' => 'location', 'label' => 'Location'],
                ['key' => 'completion_date', 'label' => 'Completed'],
                ['key' => 'expire_date', 'label' => 'Expires'],
                ['key' => 'status', 'label' => 'Status'],
                ['key' => 'hours', 'label' => 'Hours'],
                ['key' => 'class', 'label' => 'Class'],
                ['key' => 'cert_id', 'label' => 'Cert ID'],
            ],
            rows: $this->reportRows($completions, $org),
            capped: $capped,
            filename: 'training-record-'.$training->id.'.pdf',
        );
    }

    /**
     * User training record (transcript) — one user's full completion history,
     * newest first (training, when, expiry, hours, class, cert id).
     */
    public function userRecord(Request $request, User $user): PdfBuilder
    {
        $this->authorizeManager($request);

        $completions = Completion::query()
            ->where('org_id', $user->org_id)
            ->where('user_id', $user->id)
            ->with(['user:id,prefix_name,f_name,m_name,l_name,suffix_name,employee_number,department,location', 'rqmtElements:id'])
            ->orderByDesc('completion_date')
            ->limit(self::ROW_CAP + 1)
            ->get();

        $capped = $completions->count() > self::ROW_CAP;
        $completions = $completions->take(self::ROW_CAP);

        return TableReport::render(
            org: $user->organization,
            title: 'Training record',
            subtitle: $user->sort_name,
            // One person → identifying columns live in the subtitle, not the table.
            columns: [
                ['key' => 'training', 'label' => 'Training'],
                ['key' => 'completion_date', 'label' => 'Completed'],
                ['key' => 'expire_date', 'label' => 'Expires'],
                ['key' => 'status', 'label' => 'Status'],
                ['key' => 'hours', 'label' => 'Hours'],
                ['key' => 'class', 'label' => 'Class'],
                ['key' => 'cert_id', 'label' => 'Cert ID'],
            ],
            rows: $this->reportRows($completions, $user->organization),
            capped: $capped,
            filename: 'training-record-user-'.$user->id.'.pdf',
        );
    }

    private function authorizeManager(Request $request): void
    {
        abort_unless(
            $request->user()->hasAnyRole(self::MANAGER_PLUS_ROLES),
            403,
        );
    }
}
