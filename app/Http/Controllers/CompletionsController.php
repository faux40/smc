<?php

namespace App\Http\Controllers;

use App\Events\CompletionCreated;
use App\Events\CompletionDeleted;
use App\Events\CompletionUpdated;
use App\Http\Requests\CompletionRequest;
use App\Models\Completion;
use App\Support\CompletionSerializer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class CompletionsController extends Controller
{
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
            $query->leftJoin('trainings', function ($join) {
                $join->on('trainings.id', '=', 'completions.module_id')
                    ->where('completions.module_type', '=', \App\Models\Training::class);
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

        $completion = DB::transaction(function () use ($data) {
            $c = Completion::create([
                'org_id' => Auth::user()->org_id,
                'user_id' => $data['user_id'],
                'module_type' => $data['module_type'],
                'module_id' => $data['module_id'],
                'completion_date' => $data['completion_date'],
                'certification_date' => $data['certification_date'] ?? null,
                'expire_date' => $data['expire_date'] ?? null,
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

    public function update(CompletionRequest $request, Completion $completion): JsonResponse
    {
        // Authz already ran in CompletionRequest::authorize().
        $data = $request->validated();

        DB::transaction(function () use ($completion, $data) {
            $completion->update([
                'completion_date' => $data['completion_date'],
                'certification_date' => $data['certification_date'] ?? null,
                'expire_date' => $data['expire_date'] ?? null,
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
}
