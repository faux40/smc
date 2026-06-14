<?php

namespace App\Http\Controllers;

use App\Actions\CreateUser;
use App\Events\UserSoftDeleted;
use App\Events\UserStatusChanged;
use App\Events\UserUpdated;
use App\Http\Requests\CreateUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Models\Completion;
use App\Models\TrainingAssignment;
use App\Models\User;
use App\Services\TrainingStatusService;
use App\Support\CompletionSerializer;
use App\Support\SourceChips;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class UsersController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', User::class);

        $search = trim((string) $request->query('q', ''));
        $roleFilter = trim((string) $request->query('role', ''));
        $includeDisabled = filter_var($request->query('include_disabled', false), FILTER_VALIDATE_BOOLEAN);

        // Tag filter: tags=<uuid>,<uuid>&tags_mode=and|or|not
        // - and: row must have *every* selected tag (whereHas per id)
        // - or : row must have *any*   selected tag (whereHas whereIn)
        // - not: row must have *none* of the selected tags (whereDoesntHave)
        $tagIds = array_values(array_filter(
            (array) $request->query('tags', []),
            fn ($v) => is_string($v) && $v !== '',
        ));
        $tagsMode = in_array($request->query('tags_mode'), ['and', 'or', 'not'], true)
            ? $request->query('tags_mode')
            : 'and';

        $users = User::query()
            ->with(['roles:id,name', 'tags:id', 'supervisor:id,prefix_name,f_name,m_name,l_name,suffix_name'])
            ->when(! $includeDisabled, fn ($q) => $q->where('status', 'active'))
            ->when($search !== '', function ($q) use ($search) {
                // Case-insensitive via LOWER() on both sides — portable across
                // sqlite (tests) and Postgres (dev/prod), where bare LIKE is
                // case-sensitive. Column names are a fixed allowlist, never
                // user input, so interpolating them into the raw clause is safe.
                $term = '%'.mb_strtolower($search).'%';
                $columns = [
                    'f_name', 'm_name', 'l_name', 'email',
                    'job_title', 'department', 'location', 'employee_number',
                ];

                $q->where(function ($inner) use ($columns, $term) {
                    foreach ($columns as $column) {
                        $inner->orWhereRaw("LOWER({$column}) LIKE ?", [$term]);
                    }
                });
            })
            ->when(count($tagIds) > 0, function ($q) use ($tagIds, $tagsMode) {
                // `taggables.taggable_id` is varchar (schema kept generic
                // for mixed-PK morphs); `users.id` is uuid. Postgres
                // won't auto-cast across the join, so the relation's
                // default whereHas/whereDoesntHave error with 42883.
                // Explicit `CAST(users.id AS text)` works on both
                // sqlite (tests) and pgsql (dev/prod).
                $tagSubquery = function ($sub, array $ids) {
                    $sub->select(DB::raw(1))
                        ->from('taggables')
                        ->join('tags', 'tags.id', '=', 'taggables.tag_id')
                        ->whereRaw('taggables.taggable_id = CAST(users.id AS text)')
                        ->where('taggables.taggable_type', User::class)
                        ->whereNull('tags.deleted_at')
                        ->whereIn('tags.id', $ids);
                };

                if ($tagsMode === 'and') {
                    foreach ($tagIds as $tagId) {
                        $q->whereExists(fn ($sub) => $tagSubquery($sub, [$tagId]));
                    }
                } elseif ($tagsMode === 'or') {
                    $q->whereExists(fn ($sub) => $tagSubquery($sub, $tagIds));
                } else { // 'not'
                    $q->whereNotExists(fn ($sub) => $tagSubquery($sub, $tagIds));
                }
            })
            ->orderBy('l_name')
            ->orderBy('f_name')
            ->get(['id', 'org_id', 'f_name', 'm_name', 'l_name', 'prefix_name', 'suffix_name', 'email', 'status', 'department', 'location', 'job_title', 'employee_number', 'supervisor_id', 'start_date', 'end_date', 'created_at'])
            ->when($roleFilter !== '', fn ($collection) => $collection->filter(
                fn (User $u) => $u->roles->contains('name', $roleFilter),
            )->values())
            ->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'f_name' => $u->f_name,
                'm_name' => $u->m_name,
                'l_name' => $u->l_name,
                'prefix_name' => $u->prefix_name,
                'suffix_name' => $u->suffix_name,
                'email' => $u->email,
                'status' => $u->status,
                'role' => $u->roles->first()?->name,
                'department' => $u->department,
                'location' => $u->location,
                'job_title' => $u->job_title,
                'employee_number' => $u->employee_number,
                'supervisor_id' => $u->supervisor_id,
                'supervisor_name' => $u->supervisor?->name,
                'start_date' => $u->start_date?->toDateString(),
                'end_date' => $u->end_date?->toDateString(),
                'created_at' => $u->created_at?->toDateTimeString(),
                // TagsListCell hydrates the tags store with these so the
                // first paint already shows attached pills without a
                // follow-up fetch. Eager-loaded via `with('tags:id')`.
                'tag_ids' => $u->tags->pluck('id')->all(),
                'can_edit' => Gate::check('update', $u),
                'can_disable' => Gate::check('disable', $u),
                'can_delete' => Gate::check('delete', $u),
            ]);

        return Inertia::render('users/Index', [
            'users' => $users,
            'filters' => [
                'q' => $search,
                'role' => $roleFilter,
                'include_disabled' => $includeDisabled,
                'tags' => $tagIds,
                'tags_mode' => $tagsMode,
            ],
            'can_create' => Gate::check('create', User::class),
        ]);
    }

    /**
     * Lean JSON list of active org users for downstream picker UX
     * (assignment / completion form modals). Manager+ gate matches the
     * widened Assignment / Completion policies — the people who can
     * pick users in those workflows.
     */
    public function pickerList(Request $request): JsonResponse
    {
        abort_unless(
            $request->user()->hasAnyRole(['Owner', 'SuperAdmin', 'Admin', 'Manager']),
            403,
        );

        $users = User::query()
            ->where('status', 'active')
            ->with(['tags:id', 'supervisor:id,prefix_name,f_name,m_name,l_name,suffix_name'])
            ->orderBy('l_name')
            ->orderBy('f_name')
            ->get([
                'id', 'f_name', 'l_name', 'email', 'employee_number',
                'department', 'location', 'job_title', 'supervisor_id',
            ]);

        return response()->json($users->map(fn (User $u) => [
            'id' => $u->id,
            'name' => $u->name,
            'f_name' => $u->f_name,
            'l_name' => $u->l_name,
            'email' => $u->email,
            'employee_number' => $u->employee_number,
            'department' => $u->department,
            'location' => $u->location,
            'job_title' => $u->job_title,
            'supervisor_id' => $u->supervisor_id,
            'supervisor_name' => $u->supervisor?->name,
            'tag_ids' => $u->tags->pluck('id')->all(),
        ]));
    }

    /**
     * Distinct existing values for the free-text profile fields, scoped to the
     * caller's org (the User model's global org scope handles tenancy). Feeds
     * the type-ahead on the user form so admins reuse "Foreman" instead of
     * coining "foreman" / "Fore man" — standardization without enforcement.
     */
    public function fieldOptions(): JsonResponse
    {
        $distinct = fn (string $column): array => User::query()
            ->whereNotNull($column)
            ->where($column, '!=', '')
            ->distinct()
            ->orderBy($column)
            ->pluck($column)
            ->all();

        return response()->json([
            'department' => $distinct('department'),
            'location' => $distinct('location'),
            'job_title' => $distinct('job_title'),
        ]);
    }

    /**
     * Per-user detail page (Phase 13.3). Renders the Inertia shell with
     * the basic user header; compliance + completion timelines stream
     * in via the JSON `trainingCompliance()` endpoint on mount.
     */
    public function show(User $user): Response
    {
        Gate::authorize('viewDetail', $user);

        return Inertia::render('users/Show', [
            'subject' => [
                'id' => $user->id,
                'name' => $user->name,
                'f_name' => $user->f_name,
                'm_name' => $user->m_name,
                'l_name' => $user->l_name,
                'prefix_name' => $user->prefix_name,
                'suffix_name' => $user->suffix_name,
                'email' => $user->email,
                'status' => $user->status,
                'role' => $user->roles->first()?->name,
                'department' => $user->department,
                'location' => $user->location,
                'job_title' => $user->job_title,
                'employee_number' => $user->employee_number,
                'supervisor_id' => $user->supervisor_id,
                'supervisor_name' => $user->supervisor?->name,
                'start_date' => $user->start_date?->toDateString(),
                'end_date' => $user->end_date?->toDateString(),
                // Gates the inline Edit affordance; the same ability the
                // update endpoint enforces (UpdateUserRequest::authorize).
                'can_edit' => Gate::allows('update', $user),
            ],
            // TagsField is mounted on the page; hydrate it with the
            // current attachments so it doesn't need a follow-up fetch.
            'tagIds' => $user->tags()->pluck('tags.id')->all(),
        ]);
    }

    /**
     * JSON compliance payload for the user detail page. Groups
     * assignments by status (overdue / due_soon / current /
     * never_started / inactive) and returns the full completion
     * history (per the v15 "credit for unassigned" path stays visible).
     */
    /**
     * J3 — TA-engine compliance payload: every training assignment grouped
     * into exactly one status bucket, plus the user's full completion
     * history.
     */
    public function trainingCompliance(User $user, TrainingStatusService $status): JsonResponse
    {
        Gate::authorize('viewDetail', $user);

        $window = $user->organization->expiringSoonDays();

        $tas = TrainingAssignment::query()
            ->where('user_id', $user->id)
            ->with('activeSources')
            ->orderBy('name')
            ->get();

        $requirementNames = SourceChips::names($tas);

        $grouped = $tas->groupBy(fn (TrainingAssignment $ta) => $status->statusFor($ta, $window));

        $groups = collect(TrainingStatusService::STATUSES)
            ->mapWithKeys(fn (string $bucket) => [
                $bucket => ($grouped->get($bucket) ?? collect())
                    ->map(fn (TrainingAssignment $ta) => $this->complianceRow($ta, $status, $window, $requirementNames))
                    ->values()
                    ->all(),
            ])
            ->all();

        return response()->json([
            'groups' => $groups,
            'completions' => $this->completionHistory($user),
        ]);
    }

    /**
     * @param  Collection<string, string>  $requirementNames
     * @return array<string, mixed>
     */
    private function complianceRow(
        TrainingAssignment $ta,
        TrainingStatusService $status,
        int $window,
        $requirementNames,
    ): array {
        return [
            'id' => $ta->id,
            'training_id' => $ta->training_id,
            'training_name' => $ta->name,
            'status' => $status->statusFor($ta, $window),
            'expires_at' => $ta->expires_at?->toDateString(),
            'last_completed_at' => $ta->last_completed_at?->toDateString(),
            'days_until_due' => $status->daysUntilDue($ta),
            'sources' => SourceChips::for($ta, $requirementNames),
        ];
    }

    /**
     * Full completion history through the shared serializer (M1):
     * training names (trashed incl.), hours, class links, effective credits.
     *
     * @return array<int, array<string, mixed>>
     */
    private function completionHistory(User $user): array
    {
        $completions = Completion::query()
            ->where('user_id', $user->id)
            ->with('rqmtElements:id')
            ->orderByDesc('completion_date')
            ->get();

        return CompletionSerializer::collection($completions);
    }

    public function store(CreateUserRequest $request, CreateUser $creator): RedirectResponse
    {
        // New single-add users land as role None (role is set later via edit).
        $creator->handle($request->user()->org_id, $request->validated(), 'None');

        return Redirect::route('users.index');
    }

    /**
     * BULK USER ADD — create many users from a spreadsheet-style grid.
     * Per-row best-effort: valid rows are created, invalid rows are skipped
     * (never blocking the batch) and reported with their field errors. Role
     * is settable per row (never Owner); email is unique globally and within
     * the batch. Shares CreateUser with the single-add path.
     */
    public function bulkStore(Request $request, CreateUser $creator): JsonResponse
    {
        Gate::authorize('create', User::class);

        $request->validate([
            'users' => ['required', 'array', 'min:1', 'max:500'],
        ]);

        $orgId = $request->user()->org_id;
        $rules = $this->bulkRowRules($orgId);

        $created = 0;
        $skipped = 0;
        $results = [];
        $claimedEmails = [];

        foreach ($request->input('users') as $i => $row) {
            $validator = Validator::make(is_array($row) ? $row : [], $rules);

            if ($validator->fails()) {
                $results[] = ['index' => $i, 'status' => 'skipped', 'errors' => $validator->errors()->toArray()];
                $skipped++;

                continue;
            }

            $data = $validator->validated();
            $emailKey = isset($data['email']) ? strtolower((string) $data['email']) : null;

            // Within-batch dedup (DB uniqueness is enforced by the rules; this
            // also catches two new identical emails in the same submission).
            if ($emailKey !== null && isset($claimedEmails[$emailKey])) {
                $results[] = ['index' => $i, 'status' => 'skipped', 'errors' => ['email' => ['Duplicate email within this batch.']]];
                $skipped++;

                continue;
            }

            $user = $creator->handle($orgId, $data, $data['role'] ?? 'None');

            if ($emailKey !== null) {
                $claimedEmails[$emailKey] = true;
            }
            $results[] = ['index' => $i, 'status' => 'created', 'user_id' => $user->id];
            $created++;
        }

        return response()->json(['created' => $created, 'skipped' => $skipped, 'results' => $results]);
    }

    /**
     * Per-row validation for bulk add — mirrors CreateUserRequest plus a
     * per-row Role (never Owner; default None applied at create).
     *
     * @return array<string, array<int, mixed>>
     */
    private function bulkRowRules(string $orgId): array
    {
        return [
            'f_name' => ['required', 'string', 'max:255'],
            'm_name' => ['nullable', 'string', 'max:255'],
            'l_name' => ['required', 'string', 'max:255'],
            'prefix_name' => ['nullable', 'string', 'max:32'],
            'suffix_name' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'string', 'email', 'max:255', Rule::unique('users', 'email')->whereNull('deleted_at')],
            'role' => ['nullable', Rule::in(UpdateUserRequest::ASSIGNABLE_ROLES)],
            'department' => ['nullable', 'string', 'max:255'],
            'location' => ['nullable', 'string', 'max:255'],
            'job_title' => ['nullable', 'string', 'max:255'],
            'employee_number' => ['nullable', 'string', 'max:64'],
            'supervisor_id' => ['nullable', 'string', Rule::exists('users', 'id')->where('org_id', $orgId)->whereNull('deleted_at')],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
        ];
    }

    public function update(UpdateUserRequest $request, User $user): RedirectResponse
    {
        $data = $request->validated();

        $user->update([
            'f_name' => $data['f_name'],
            'm_name' => $data['m_name'] ?? null,
            'l_name' => $data['l_name'],
            'prefix_name' => $data['prefix_name'] ?? null,
            'suffix_name' => $data['suffix_name'] ?? null,
            'email' => $data['email'] ?? null,
            'status' => $data['status'],
            'department' => $data['department'] ?? null,
            'location' => $data['location'] ?? null,
            'job_title' => $data['job_title'] ?? null,
            'employee_number' => $data['employee_number'] ?? null,
            'supervisor_id' => $data['supervisor_id'] ?? null,
            'start_date' => $data['start_date'] ?? null,
            'end_date' => $data['end_date'] ?? null,
        ]);

        // role is absent from $data for Owner targets (the request
        // rule is `prohibited` then); skip syncRoles so the Owner
        // role stays intact. Otherwise syncRoles replaces all roles
        // atomically to match the one-role-per-user invariant.
        if (isset($data['role'])) {
            $user->syncRoles([$data['role']]);
        }

        event(new UserUpdated($user->fresh()->load('roles:id,name')));

        // Return to wherever the edit was launched from — the users list
        // modal (referer = users.index) lands back on the list, while the
        // user-detail edit modal stays on the detail page. Direct/test
        // requests without a referer fall back to the list.
        return back(fallback: route('users.index'));
    }

    public function disable(User $user): RedirectResponse
    {
        Gate::authorize('disable', $user);

        $user->update(['status' => 'disabled']);

        event(new UserStatusChanged($user->fresh()));

        return Redirect::route('users.index');
    }

    public function enable(User $user): RedirectResponse
    {
        Gate::authorize('enable', $user);

        $user->update(['status' => 'active']);

        event(new UserStatusChanged($user->fresh()));

        return Redirect::route('users.index');
    }

    public function destroy(User $user): RedirectResponse
    {
        Gate::authorize('delete', $user);

        $user->delete();

        event(new UserSoftDeleted($user));

        return Redirect::route('users.index');
    }
}
