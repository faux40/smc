<?php

namespace App\Http\Controllers;

use App\Actions\RecalculateTrainingStatus;
use App\Events\CompletionCreated;
use App\Events\CompletionDeleted;
use App\Events\CompletionsBulkChanged;
use App\Events\CompletionUpdated;
use App\Http\Requests\BulkCompletionRequest;
use App\Http\Requests\CompletionRequest;
use App\Models\Completion;
use App\Models\RqmtElement;
use App\Models\Training;
use App\Models\User;
use App\Support\CompletionSerializer;
use App\Support\ExpiryCalculator;
use App\Support\RecalcContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class CompletionsController extends Controller
{
    public function __construct(
        private RecalculateTrainingStatus $recalculate,
    ) {}

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Completion::class);

        $query = Completion::query()
            ->where('completions.org_id', $request->user()->org_id)
            ->with('rqmtElements:id');

        if ($request->filled('user_id')) {
            $query->where('completions.user_id', (string) $request->query('user_id'));
        }

        // Determine joins required for sort and/or search.
        $hasSearch = $request->filled('q');
        $sortable = ['completion_date', 'expire_date', 'certification_date', 'hours', 'cert_id', 'created_at', 'user', 'training_name'];
        $sort = in_array($request->query('sort'), $sortable, true) ? $request->query('sort') : 'completion_date';
        $dir = $request->query('dir') === 'asc' ? 'asc' : 'desc';

        $needsUserJoin = $sort === 'user' || $hasSearch;
        $needsTrainingJoin = $sort === 'training_name' || $hasSearch;

        if ($needsUserJoin || $needsTrainingJoin) {
            $query->select('completions.*');
        }

        if ($needsUserJoin) {
            // Raw LEFT JOIN — bypasses Eloquent's SoftDeletes scope intentionally,
            // so completions for soft-deleted users still appear and sort correctly.
            $query->leftJoin('users', 'users.id', '=', 'completions.user_id');
        }

        if ($needsTrainingJoin) {
            // Only match Training-type modules; non-Training rows get NULLs (safe for sort/search).
            //
            // The cast is load-bearing on Postgres: `completions.module_id` is a
            // string column by design — the morph is meant to carry a future
            // module whose id is not a UUID — while `trainings.id` is uuid, and
            // Postgres refuses `uuid = character varying` rather than coercing.
            // Without it every search and every training-name sort returned 500.
            //
            // Cast the uuid side, not the string one: `module_id::uuid` would
            // throw on the first non-UUID module, i.e. exactly the case the
            // string column exists for. Costs the index on trainings.id for this
            // join; if that ever bites, a functional index on (id::text) buys it
            // back without changing the query.
            $query->leftJoin('trainings', function ($join) {
                $join->on(DB::raw('CAST("trainings"."id" AS TEXT)'), '=', 'completions.module_id')
                    ->where('completions.module_type', '=', Training::class);
            });
        }

        // Free-text search — cert/notes always; user name + training name when joins are active.
        if ($hasSearch) {
            $term = '%'.mb_strtolower((string) $request->query('q')).'%';
            $query->where(function ($w) use ($term) {
                $w->whereRaw('LOWER(completions.cert_id) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(completions.cert_ident) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(completions.notes) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(users.f_name) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(users.l_name) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(users.email) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(trainings.name) LIKE ?', [$term]);
            });
        }

        // Sort — join-based columns use table-qualified refs; DB columns qualify when joins are active.
        if ($sort === 'user') {
            $query->orderBy('users.l_name', $dir)->orderBy('users.f_name', $dir)->orderBy('completions.id');
        } elseif ($sort === 'training_name') {
            $query->orderBy('trainings.name', $dir)->orderBy('completions.id');
        } else {
            $col = ($needsUserJoin || $needsTrainingJoin) ? "completions.{$sort}" : $sort;
            $query->orderBy($col, $dir)->orderBy('completions.id');
        }

        // Always paginated ({data, meta}); the completions Pinia store is the
        // only consumer and drives it via useServerTable.
        $perPage = max(1, min(100, (int) $request->query('per_page', 25)));
        $page = $query->paginate($perPage);

        return response()->json([
            'data' => CompletionSerializer::collection(collect($page->items()), withPermissions: true),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    public function store(CompletionRequest $request): JsonResponse
    {
        // Authz already ran in CompletionRequest::authorize().
        $data = $request->validated();
        $expireDate = $this->defaultExpireDate(
            $data['module_type'],
            $data['module_id'],
            $data['completion_date'],
            $data['expire_date'] ?? null,
        );

        $completion = DB::transaction(function () use ($data, $expireDate) {
            $c = Completion::create([
                'org_id' => Auth::user()->org_id,
                'user_id' => $data['user_id'],
                'module_type' => $data['module_type'],
                'module_id' => $data['module_id'],
                'completion_date' => $data['completion_date'],
                'certification_date' => $data['certification_date'] ?? null,
                'expire_date' => $expireDate,
                'cert_ident' => $data['cert_ident'] ?? null,
                'hours' => $data['hours'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);
            $c->rqmtElements()->sync($data['rqmt_element_ids']);

            return $c;
        });

        event(new CompletionCreated($completion->fresh(), actorId: Auth::id()));

        return response()->json(
            CompletionSerializer::one($completion->fresh()->load('rqmtElements:id'), withPermissions: true),
            201,
        );
    }

    /**
     * F8 — record one training completion for many users at once (the paper
     * roster case), without the full class workflow.
     *
     * Batching contract mirrors bulkAssignDirect: the org-membership filter is
     * one whereIn, the status recalc is a single batched handleMany() (not N
     * observer round-trips), and one CompletionsBulkChanged broadcast replaces
     * the per-completion CompletionCreated storm. Duplicate completions are
     * legitimate (a retake), so — unlike bulk assignment — existing records are
     * NOT skipped; only non-org / soft-deleted users are.
     *
     * Suppressing the CompletionCreated events also suppresses their per-user
     * inbox notification (NotifyCompletionRecorded): a bulk roster entry
     * shouldn't ping 12 people. The observer does nothing but recalc, so
     * withoutEvents loses no side effect we need — the batched recalc below
     * replaces it.
     */
    public function bulkStore(BulkCompletionRequest $request): JsonResponse
    {
        // Authz already ran in BulkCompletionRequest::authorize().
        $data = $request->validated();
        $orgId = $request->user()->org_id;
        $trainingId = $data['training_id'];

        // Org-scoped, non-soft-deleted members only — the global scope on the
        // User model drops trashed rows, so this both org-scopes and skips
        // inactive users in one query (same seam as bulkAssignDirect).
        $validUserIds = User::where('org_id', $orgId)
            ->whereIn('id', $data['user_ids'])
            ->pluck('id');
        $skipped = count($data['user_ids']) - $validUserIds->count();

        if ($validUserIds->isEmpty()) {
            return response()->json(['created_count' => 0, 'skipped_count' => $skipped], 201);
        }

        // One batched status recalc over every (user, training) pair, with the
        // training, amber window, and this training's requirement elements
        // preloaded so requirement-sourced assignments recompute correctly.
        // Loaded up-front (not after the writes) so the same Training row also
        // backs the F9 expire_date default below — one query, not N.
        $training = Training::where('org_id', $orgId)->with('stdFrequency:id,repeat_days')->findOrFail($trainingId);
        $expireDate = $this->defaultExpireDate(Training::class, $trainingId, $data['completion_date'], $data['expire_date'] ?? null, $training);

        DB::transaction(function () use ($validUserIds, $data, $orgId, $trainingId, $expireDate) {
            // withoutEvents mutes the per-completion CompletionObserver recalc
            // (and the CompletionCreated event) — the batched recalc below runs
            // once for the whole set instead.
            Completion::withoutEvents(function () use ($validUserIds, $data, $orgId, $trainingId, $expireDate) {
                foreach ($validUserIds as $userId) {
                    $c = Completion::create([
                        'org_id' => $orgId,
                        'user_id' => $userId,
                        'module_type' => Training::class,
                        'module_id' => $trainingId,
                        'completion_date' => $data['completion_date'],
                        'certification_date' => $data['certification_date'] ?? null,
                        'expire_date' => $expireDate,
                        'cert_ident' => $data['cert_ident'] ?? null,
                        'hours' => $data['hours'] ?? null,
                        'notes' => $data['notes'] ?? null,
                    ]);
                    $c->rqmtElements()->sync($data['rqmt_element_ids']);
                }
            });
        });

        $elements = RqmtElement::where('org_id', $orgId)
            ->where('module_type', Training::class)
            ->where('module_id', $trainingId)
            ->with('stdFrequency')
            ->get();
        $context = RecalcContext::make($orgId, collect([$training]), $elements);
        $pairs = $validUserIds->map(fn ($id) => ['user_id' => $id, 'training_id' => $trainingId]);
        $this->recalculate->handleMany($pairs, $context);

        // One org-channel signal for the whole batch — peer tabs debounce-refetch.
        event(new CompletionsBulkChanged($orgId, actorId: Auth::id()));

        return response()->json([
            'created_count' => $validUserIds->count(),
            'skipped_count' => $skipped,
        ], 201);
    }

    public function update(CompletionRequest $request, Completion $completion): JsonResponse
    {
        // Authz already ran in CompletionRequest::authorize().
        $data = $request->validated();
        $expireDate = $this->defaultExpireDate(
            $completion->module_type,
            $completion->module_id,
            $data['completion_date'],
            $data['expire_date'] ?? null,
        );

        DB::transaction(function () use ($completion, $data, $expireDate) {
            $completion->update([
                'completion_date' => $data['completion_date'],
                'certification_date' => $data['certification_date'] ?? null,
                'expire_date' => $expireDate,
                'cert_ident' => $data['cert_ident'] ?? null,
                'hours' => $data['hours'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);
            $completion->rqmtElements()->sync($data['rqmt_element_ids']);
        });

        event(new CompletionUpdated($completion->fresh()));

        return response()->json(
            CompletionSerializer::one($completion->fresh()->load('rqmtElements:id'), withPermissions: true),
        );
    }

    public function destroy(Completion $completion): JsonResponse
    {
        Gate::authorize('delete', $completion);

        $id = $completion->id;
        $userId = $completion->user_id;
        $orgId = $completion->org_id;
        $completion->delete();

        event(new CompletionDeleted($id, $userId, $orgId));

        return response()->json(['ok' => true]);
    }

    /**
     * F9 — defense-in-depth default for `expire_date`: when the client left
     * it absent/null, and the module is a Training with a repeat frequency,
     * default it to completion_date + repeat_days via the shared
     * ExpiryCalculator (same math CompleteClass uses at class close-out) —
     * so a forgotten field can no longer silently read as "Current forever"
     * in the reports (see ReportsController::applyStatusFilter). An explicit
     * expire_date from the client always wins; a training with no repeat
     * frequency (initial-only / as-needed) correctly stays null.
     *
     * @param  Training|null  $training  pass the already-loaded row when the
     *                                   caller has one (bulkStore) to avoid
     *                                   a redundant query.
     */
    private function defaultExpireDate(
        string $moduleType,
        string $moduleId,
        string $completionDate,
        ?string $expireDate,
        ?Training $training = null,
    ): ?string {
        if ($expireDate !== null) {
            return $expireDate;
        }

        if ($moduleType !== Training::class) {
            return null;
        }

        $training ??= Training::where('org_id', Auth::user()->org_id)
            ->with('stdFrequency:id,repeat_days')
            ->find($moduleId);

        return $training ? ExpiryCalculator::forTraining($training, $completionDate) : null;
    }
}
