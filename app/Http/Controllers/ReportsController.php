<?php

namespace App\Http\Controllers;

use App\Models\Completion;
use App\Models\Organization;
use App\Models\Training;
use App\Models\User;
use App\Support\CompletionSerializer;
use App\Support\ExpiryStatus;
use App\Support\PdfRenderer;
use App\Support\ReportGrouping;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
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
            ->with(['user:id,prefix_name,f_name,m_name,l_name,suffix_name,employee_number,department,location', 'user.tags:id,name', 'rqmtElements:id']);

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

    /** Org completion report — full filtered set as a PDF (capped). */
    public function completionsExport(Request $request): PdfBuilder
    {
        $this->authorizeManager($request);
        $org = $request->user()->organization;

        $all = $this->completionsQuery($request, $org)
            ->with(['user:id,prefix_name,f_name,m_name,l_name,suffix_name,employee_number,department,location', 'user.tags:id,name', 'rqmtElements:id'])
            ->limit(self::ROW_CAP + 1)
            ->get();

        $capped = $all->count() > self::ROW_CAP;
        $rows = $this->reportRows($all->take(self::ROW_CAP), $org);

        // Optional grouping: `group_by[]` in precedence order. Empty/unknown →
        // no grouping (flat report). The blade renders group bands from `groups`.
        $groupBy = ReportGrouping::sanitize((array) $request->query('group_by', []));

        return $this->tableReport(
            org: $org,
            title: 'Completion report',
            subtitle: $this->dateRangeLabel($request),
            columns: $this->selectedColumns($request),
            rows: $rows,
            capped: $capped,
            filename: 'completion-report.pdf',
            filters: $this->filterSummary($request),
            groups: $groupBy !== [] ? ReportGrouping::flatten($rows, $groupBy) : [],
        );
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
     * Resolve which columns the export PDF should render, honoring the
     * on-screen column show/hide + order passed as `columns[]`. Unknown keys
     * are ignored (whitelist); an empty/absent/all-unknown selection falls
     * back to the full catalog rather than rendering an empty table.
     *
     * @return array<int, array{key: string, label: string}>
     */
    private function selectedColumns(Request $request): array
    {
        $requested = array_values(array_filter(
            (array) $request->query('columns', []),
            fn ($k) => is_string($k) && isset(self::COMPLETION_COLUMNS[$k]),
        ));
        $keys = $requested !== [] ? $requested : array_keys(self::COMPLETION_COLUMNS);

        return array_map(
            fn (string $key) => ['key' => $key, 'label' => self::COMPLETION_COLUMNS[$key]],
            $keys,
        );
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

        return $this->tableReport(
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

        return $this->tableReport(
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

    /**
     * Render the shared tabular report PDF with a repeating page footer.
     *
     * @param  array<int, array{key: string, label: string}>  $columns
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<int, array<string, mixed>>  $groups  flattened group/row render list (empty = flat report)
     */
    private function tableReport(
        Organization $org,
        string $title,
        ?string $subtitle,
        array $columns,
        array $rows,
        bool $capped,
        string $filename,
        ?string $filters = null,
        array $groups = [],
    ): PdfBuilder {
        $generatedAt = Carbon::now(config('app.display_timezone'))->format('M j, Y g:i A');

        $pdf = PdfRenderer::make('pdf.report', [
            'org_name' => $org->name,
            'title' => $title,
            'subtitle' => $subtitle,
            'filters' => $filters,
            'columns' => $columns,
            'rows' => $rows,
            'groups' => $groups,
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
