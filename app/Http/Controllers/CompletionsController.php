<?php

namespace App\Http\Controllers;

use App\Events\CompletionCreated;
use App\Events\CompletionDeleted;
use App\Events\CompletionUpdated;
use App\Http\Requests\CompletionRequest;
use App\Models\Completion;
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

        $rows = $query->orderBy('completion_date', 'desc')->get();

        return response()->json($rows->map(fn (Completion $c) => $this->serialize($c)));
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
                'notes' => $data['notes'] ?? null,
            ]);
            $c->rqmtElements()->sync($data['rqmt_element_ids']);

            return $c;
        });

        event(new CompletionCreated($completion->fresh()));

        return response()->json($this->serialize($completion->fresh()->load('rqmtElements:id')), 201);
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
                'notes' => $data['notes'] ?? null,
            ]);
            $completion->rqmtElements()->sync($data['rqmt_element_ids']);
        });

        event(new CompletionUpdated($completion->fresh()));

        return response()->json($this->serialize($completion->fresh()->load('rqmtElements:id')));
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

    private function serialize(Completion $c): array
    {
        return [
            'id' => $c->id,
            'user_id' => $c->user_id,
            'module_type' => $c->module_type,
            'module_id' => $c->module_id,
            'completion_date' => optional($c->completion_date)->toDateString(),
            'certification_date' => optional($c->certification_date)->toDateString(),
            'expire_date' => optional($c->expire_date)->toDateString(),
            'cert_ident' => $c->cert_ident,
            'notes' => $c->notes,
            'rqmt_element_ids' => $c->rqmtElements->pluck('id')->all(),
            'can_edit' => Gate::check('update', $c),
            'can_delete' => Gate::check('delete', $c),
        ];
    }
}
