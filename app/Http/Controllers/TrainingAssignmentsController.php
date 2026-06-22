<?php

namespace App\Http\Controllers;

use App\Events\TrainingAssignmentDeleted;
use App\Http\Requests\TrainingAssignmentRequest;
use App\Models\AssignmentSource;
use App\Models\Requirement;
use App\Models\TrainingAssignment;
use App\Models\User;
use App\Services\TrainingAssignmentService;
use App\Services\TrainingStatusService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class TrainingAssignmentsController extends Controller
{
    public function __construct(
        private TrainingAssignmentService $service,
        private TrainingStatusService $status,
    ) {}

    /** Memoised org amber window for status computation in serialize(). */
    private ?int $dueSoonDays = null;

    private function dueSoonDays(): int
    {
        return $this->dueSoonDays ??= Auth::user()->organization->expiringSoonDays();
    }

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', TrainingAssignment::class);

        $query = TrainingAssignment::query()
            ->where('org_id', $request->user()->org_id)
            ->with(['activeSources']);

        if ($request->filled('user_id')) {
            $query->where('user_id', (string) $request->query('user_id'));
        }
        if ($request->filled('training_id')) {
            $query->where('training_id', (string) $request->query('training_id'));
        }

        $rows = $query->orderBy('created_at', 'desc')->get();

        return response()->json($rows->map(fn (TrainingAssignment $ta) => $this->serialize($ta)));
    }

    /**
     * Server-paged assignments grouped by user — the row unit is the user, with
     * their training-assignment pills inline. All filtering/sort/paging runs in
     * the DB (the page previously loaded every TA + user and aggregated client-
     * side). Per page we hydrate only the visible users' TAs.
     */
    public function byUser(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', TrainingAssignment::class);
        $orgId = $request->user()->org_id;

        $users = User::query()
            ->where('users.org_id', $orgId)
            ->where('users.status', 'active');

        $this->applyUserSearch($users, $request);
        $this->applyTrainingSearch($users, $request);
        $this->applyRequirementFilter($users, $request);
        $this->applyTagFilter($users, $request);

        // assignments_count is a correlated subquery so we can sort by it.
        $users->select('users.*')->selectSub(
            TrainingAssignment::query()
                ->whereColumn('training_assignments.user_id', 'users.id')
                ->selectRaw('count(*)'),
            'assignments_count',
        );

        $this->applyUserSort($users, $request);

        $perPage = max(1, min(100, (int) $request->query('per_page', 25)));
        $page = $users->paginate($perPage);

        // Hydrate just this page's users: supervisor name, tag ids, and their TAs.
        $page->getCollection()->load([
            'supervisor:id,prefix_name,f_name,m_name,l_name,suffix_name',
            'tags:id',
        ]);
        $pageUserIds = $page->getCollection()->pluck('id');
        $tasByUser = TrainingAssignment::query()
            ->whereIn('user_id', $pageUserIds)
            ->with('activeSources')
            ->get()
            ->groupBy('user_id');

        return response()->json([
            'data' => $page->getCollection()->map(fn (User $u) => [
                'user_id' => $u->id,
                'name' => $u->sort_name,
                'email' => $u->email,
                'employee_number' => $u->employee_number,
                'job_title' => $u->job_title,
                'department' => $u->department,
                'location' => $u->location,
                'supervisor_name' => $u->supervisor?->sort_name,
                'tag_ids' => $u->tags->pluck('id')->all(),
                'assignments_count' => (int) $u->assignments_count,
                'assignments' => ($tasByUser->get($u->id) ?? collect())
                    ->map(fn (TrainingAssignment $ta) => $this->serialize($ta))
                    ->values()
                    ->all(),
            ])->values()->all(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    /** User text filter (name/email/EE#/title/dept/location). */
    private function applyUserSearch($query, Request $request): void
    {
        $uq = trim((string) $request->query('user_q', ''));
        if ($uq === '') {
            return;
        }
        $like = '%'.mb_strtolower($uq).'%';
        $query->where(function ($w) use ($like) {
            foreach (['f_name', 'm_name', 'l_name', 'email', 'employee_number', 'job_title', 'department', 'location'] as $col) {
                $w->orWhereRaw("LOWER(users.{$col}) LIKE ?", [$like]);
            }
        });
    }

    /** Generic search — training names only (users with a matching TA). */
    private function applyTrainingSearch($query, Request $request): void
    {
        $q = trim((string) $request->query('q', ''));
        if ($q === '') {
            return;
        }
        $like = '%'.mb_strtolower($q).'%';
        $query->whereExists(fn ($s) => $s->select(DB::raw(1))
            ->from('training_assignments as ta')
            ->whereColumn('ta.user_id', 'users.id')
            ->whereRaw('LOWER(ta.name) LIKE ?', [$like]));
    }

    /** Requirement filter (and/or/not) — users with TAs sourced from them. */
    private function applyRequirementFilter($query, Request $request): void
    {
        $ids = array_values(array_filter((array) $request->query('requirements', []), fn ($v) => is_string($v) && $v !== ''));
        if ($ids === []) {
            return;
        }
        $mode = in_array($request->query('req_mode'), ['and', 'or', 'not'], true) ? $request->query('req_mode') : 'or';
        $sub = fn ($s, array $reqIds) => $s->select(DB::raw(1))
            ->from('training_assignments as ta')
            ->join('assignment_sources as src', 'src.training_assignment_id', '=', 'ta.id')
            ->whereColumn('ta.user_id', 'users.id')
            ->whereNull('src.removed_at')
            ->where('src.sourceable_type', Requirement::class)
            ->whereIn('src.sourceable_id', $reqIds);

        if ($mode === 'and') {
            foreach ($ids as $id) {
                $query->whereExists(fn ($s) => $sub($s, [$id]));
            }
        } elseif ($mode === 'or') {
            $query->whereExists(fn ($s) => $sub($s, $ids));
        } else {
            $query->whereNotExists(fn ($s) => $sub($s, $ids));
        }
    }

    /** Tag filter (and/or/not) — same CAST subquery as UsersController. */
    private function applyTagFilter($query, Request $request): void
    {
        $ids = array_values(array_filter((array) $request->query('tags', []), fn ($v) => is_string($v) && $v !== ''));
        if ($ids === []) {
            return;
        }
        $mode = in_array($request->query('tags_mode'), ['and', 'or', 'not'], true) ? $request->query('tags_mode') : 'and';
        $sub = fn ($s, array $tagIds) => $s->select(DB::raw(1))
            ->from('taggables')
            ->join('tags', 'tags.id', '=', 'taggables.tag_id')
            ->whereRaw('taggables.taggable_id = CAST(users.id AS text)')
            ->where('taggables.taggable_type', User::class)
            ->whereNull('tags.deleted_at')
            ->whereIn('tags.id', $tagIds);

        if ($mode === 'and') {
            foreach ($ids as $id) {
                $query->whereExists(fn ($s) => $sub($s, [$id]));
            }
        } elseif ($mode === 'or') {
            $query->whereExists(fn ($s) => $sub($s, $ids));
        } else {
            $query->whereNotExists(fn ($s) => $sub($s, $ids));
        }
    }

    /** Whitelisted sort: user columns (name) + the assignment count. */
    private function applyUserSort($query, Request $request): void
    {
        $dir = $request->query('dir') === 'desc' ? 'desc' : 'asc';
        $sort = (string) $request->query('sort', 'name');

        if ($sort === 'assignments') {
            $query->orderBy('assignments_count', $dir)->orderBy('users.l_name')->orderBy('users.f_name');

            return;
        }
        if ($sort === 'supervisor') {
            $query->leftJoin('users as sup', 'sup.id', '=', 'users.supervisor_id')
                ->orderBy('sup.l_name', $dir)->orderBy('sup.f_name', $dir);

            return;
        }

        $columns = [
            'name' => ['l_name', 'f_name'],
            'employee_number' => ['employee_number'],
            'job_title' => ['job_title'],
            'department' => ['department'],
            'location' => ['location'],
        ];
        foreach ($columns[$sort] ?? $columns['name'] as $col) {
            $query->orderBy('users.'.$col, $dir);
        }
        $query->orderBy('users.id');
    }

    public function store(TrainingAssignmentRequest $request): JsonResponse
    {
        Gate::authorize('create', TrainingAssignment::class);

        $data = $request->validated();
        $orgId = Auth::user()->org_id;
        $userId = $data['user_id'];

        $results = $data['source_type'] === 'requirement'
            ? $this->service->assignFromRequirement($orgId, $userId, $data['requirement_id'])
            : [$this->service->assignDirect($orgId, $userId, $data['training_id'])];

        return response()->json(
            array_map(fn (TrainingAssignment $ta) => $this->serialize($ta->load('activeSources')), $results),
            201,
        );
    }

    public function breakFromRequirement(Request $request, TrainingAssignment $trainingAssignment): JsonResponse
    {
        Gate::authorize('delete', $trainingAssignment);

        $orgId = $request->user()->org_id;

        $data = $request->validate([
            'requirement_id' => [
                'required', 'string',
                Rule::exists('requirements', 'id')->where('org_id', $orgId)->whereNull('deleted_at'),
            ],
        ]);

        $result = $this->service->breakFromRequirement($orgId, $trainingAssignment, $data['requirement_id']);

        return response()->json($result);
    }

    public function destroyByRequirement(Request $request): JsonResponse
    {
        Gate::authorize('deleteAny', TrainingAssignment::class);

        $orgId = $request->user()->org_id;

        $data = $request->validate([
            'user_id' => [
                'required', 'string',
                Rule::exists('users', 'id')->where('org_id', $orgId)->whereNull('deleted_at'),
            ],
            'requirement_id' => [
                'required', 'string',
                Rule::exists('requirements', 'id')->where('org_id', $orgId)->whereNull('deleted_at'),
            ],
        ]);

        $result = $this->service->removeRequirementSources($orgId, $data['user_id'], $data['requirement_id']);

        return response()->json($result);
    }

    public function destroy(TrainingAssignment $trainingAssignment): JsonResponse
    {
        Gate::authorize('delete', $trainingAssignment);

        $id = $trainingAssignment->id;
        $userId = $trainingAssignment->user_id;
        $trainingId = $trainingAssignment->training_id;
        $orgId = $trainingAssignment->org_id;

        $trainingAssignment->delete();

        event(new TrainingAssignmentDeleted($id, $userId, $trainingId, $orgId));

        return response()->json(['ok' => true]);
    }

    private function serialize(TrainingAssignment $ta): array
    {
        $sources = $ta->relationLoaded('activeSources')
            ? $ta->activeSources
            : $ta->activeSources()->get();

        return [
            'id' => $ta->id,
            'user_id' => $ta->user_id,
            'training_id' => $ta->training_id,
            'name' => $ta->name,
            'expires_at' => $ta->expires_at?->toDateString(),
            'last_completed_at' => $ta->last_completed_at?->toDateString(),
            'status' => $this->status->statusFor($ta, $this->dueSoonDays()),
            'days_until_due' => $this->status->daysUntilDue($ta),
            'as_needed_only' => $ta->as_needed_only,
            'active_sources' => $sources->map(fn (AssignmentSource $s) => [
                'id' => $s->id,
                'sourceable_type' => $s->sourceable_type,
                'sourceable_id' => $s->sourceable_id,
                'added_at' => $s->added_at->toISOString(),
            ])->values()->all(),
            'can_delete' => Gate::check('delete', $ta),
        ];
    }
}
