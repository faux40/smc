<?php

namespace App\Http\Controllers;

use App\Events\RqmtElementCreated;
use App\Events\RqmtElementDeleted;
use App\Events\RqmtElementUpdated;
use App\Http\Requests\CompletionRequest;
use App\Http\Requests\RqmtElementRequest;
use App\Models\Requirement;
use App\Models\RqmtElement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class RqmtElementsController extends Controller
{
    /**
     * "Candidate elements" endpoint flagged in the Phase 10.2 spec: given
     * a module identity (type + id), return every rqmt_element in the
     * org that points at it. Powers the multi-select on the manual
     * Completion form so an admin can credit a user for one module
     * against several Requirements at once. Same role gate as the
     * Completion create path (Owner/SA/Admin/Manager).
     */
    public function candidates(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', RqmtElement::class);

        $orgId = Auth::user()->org_id;

        $data = $request->validate([
            'module_type' => ['required', 'string', Rule::in(CompletionRequest::ALLOWED_MODULE_TYPES)],
            'module_id' => ['required', 'string'],
        ]);

        $rows = RqmtElement::query()
            ->where('module_type', $data['module_type'])
            ->where('module_id', $data['module_id'])
            ->with('requirement:id,name')
            ->orderBy('name')
            ->get();

        return response()->json($rows->map(fn (RqmtElement $e) => [
            'id' => $e->id,
            'requirement_id' => $e->requirement_id,
            'requirement_name' => $e->requirement?->name,
            'name' => $e->name,
            'description' => $e->description,
            'initial_only' => $e->initial_only,
            'repeating' => $e->repeating,
            'std_freq_id' => $e->std_freq_id,
            'as_needed' => $e->as_needed,
        ]));
    }

    public function index(Requirement $requirement): JsonResponse
    {
        // Same-org check on the parent requirement; the global scope already
        // hides cross-org rows, but the controller's binding resolved before
        // the scope kicked in (since the URL carried the uuid). Explicitly
        // guard here.
        abort_unless(Auth::user()->org_id === $requirement->org_id, 403);
        Gate::authorize('viewAny', RqmtElement::class);

        $rows = $requirement->elements()
            ->orderBy('created_at')
            ->get();

        return response()->json($rows->map(fn (RqmtElement $e) => [
            'id' => $e->id,
            'requirement_id' => $e->requirement_id,
            'module_type' => $e->module_type,
            'module_id' => $e->module_id,
            'name' => $e->name,
            'description' => $e->description,
            'initial_only' => $e->initial_only,
            'repeating' => $e->repeating,
            'std_freq_id' => $e->std_freq_id,
            'as_needed' => $e->as_needed,
            'can_edit' => Gate::check('update', $e),
            'can_delete' => Gate::check('delete', $e),
        ]));
    }

    public function store(RqmtElementRequest $request, Requirement $requirement): JsonResponse
    {
        abort_unless(Auth::user()->org_id === $requirement->org_id, 403);
        Gate::authorize('create', RqmtElement::class);

        $data = $request->validated();
        $element = RqmtElement::create([
            'org_id' => $requirement->org_id,
            'requirement_id' => $requirement->id,
            'module_type' => $data['module_type'],
            'module_id' => $data['module_id'],
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'initial_only' => (bool) $data['initial_only'],
            'repeating' => (bool) $data['repeating'],
            'std_freq_id' => ((bool) $data['repeating']) ? $data['std_freq_id'] : null,
            'as_needed' => (bool) $data['as_needed'],
        ]);

        event(new RqmtElementCreated($element));

        return response()->json([
            'id' => $element->id,
            'requirement_id' => $element->requirement_id,
            'name' => $element->name,
        ], 201);
    }

    public function update(RqmtElementRequest $request, RqmtElement $rqmtElement): JsonResponse
    {
        Gate::authorize('update', $rqmtElement);

        $data = $request->validated();
        $rqmtElement->update([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'initial_only' => (bool) $data['initial_only'],
            'repeating' => (bool) $data['repeating'],
            'std_freq_id' => ((bool) $data['repeating']) ? $data['std_freq_id'] : null,
            'as_needed' => (bool) $data['as_needed'],
        ]);

        event(new RqmtElementUpdated($rqmtElement->fresh()));

        return response()->json([
            'id' => $rqmtElement->id,
            'name' => $rqmtElement->name,
        ]);
    }

    public function destroy(RqmtElement $rqmtElement): JsonResponse
    {
        Gate::authorize('delete', $rqmtElement);

        $id = $rqmtElement->id;
        $reqId = $rqmtElement->requirement_id;
        $orgId = $rqmtElement->org_id;
        $rqmtElement->delete();

        event(new RqmtElementDeleted($id, $reqId, $orgId));

        return response()->json(['ok' => true]);
    }
}
