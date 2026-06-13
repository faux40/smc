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
            ->where('org_id', $request->user()->org_id)
            ->with('rqmtElements:id');

        if ($request->filled('user_id')) {
            $query->where('user_id', (string) $request->query('user_id'));
        }

        // Free-text search across DB-backed completion fields (cert id / notes).
        // Case-insensitive + portable (LOWER on both sides).
        if ($request->filled('q')) {
            $term = '%'.mb_strtolower((string) $request->query('q')).'%';
            $query->where(function ($w) use ($term) {
                $w->whereRaw('LOWER(cert_id) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(cert_ident) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(notes) LIKE ?', [$term]);
            });
        }

        // Server-side sort, restricted to a safe DB-column allowlist.
        $sortable = ['completion_date', 'expire_date', 'certification_date', 'hours', 'cert_id', 'created_at'];
        $sort = in_array($request->query('sort'), $sortable, true) ? $request->query('sort') : 'completion_date';
        $dir = $request->query('dir') === 'asc' ? 'asc' : 'desc';
        $query->orderBy($sort, $dir)->orderBy('id');

        // Paginated mode (?page=…) returns {data, meta}; without it the legacy
        // flat array is preserved so existing consumers keep working until they
        // migrate to the paged contract.
        if ($request->filled('page')) {
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

        $rows = $query->get();

        return response()->json(CompletionSerializer::collection($rows, withPermissions: true));
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
