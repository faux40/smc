<?php

namespace App\Http\Controllers;

use App\Events\RequirementCreated;
use App\Events\RequirementDeleted;
use App\Events\RequirementUpdated;
use App\Models\Requirement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class RequirementsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Requirement::class);

        $rows = Requirement::query()
            ->where('org_id', $request->user()->org_id)
            ->withCount('elements')
            ->orderBy('name')
            ->get();

        return response()->json($rows->map(fn (Requirement $r) => [
            'id' => $r->id,
            'name' => $r->name,
            'description' => $r->description,
            'elements_count' => $r->elements_count ?? 0,
            'can_edit' => Gate::check('update', $r),
            'can_delete' => Gate::check('delete', $r),
        ]));
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('create', Requirement::class);

        $data = $request->validate(
            $this->rules($request->user()->org_id),
        );

        $req = Requirement::create([
            'org_id' => $request->user()->org_id,
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
        ]);

        event(new RequirementCreated($req));

        return response()->json($this->serialize($req), 201);
    }

    public function update(Request $request, Requirement $requirement): JsonResponse
    {
        Gate::authorize('update', $requirement);

        $data = $request->validate(
            $this->rules($requirement->org_id, $requirement),
        );

        $requirement->update($data);

        event(new RequirementUpdated($requirement->fresh()));

        return response()->json($this->serialize($requirement->fresh()));
    }

    public function destroy(Requirement $requirement): JsonResponse
    {
        Gate::authorize('delete', $requirement);

        $id = $requirement->id;
        $orgId = $requirement->org_id;
        $requirement->delete();

        event(new RequirementDeleted($id, $orgId));

        return response()->json(['ok' => true]);
    }

    /**
     * Validation rules for store + update. Name must be unique within the
     * org, case-insensitive, ignoring soft-deleted rows (and the row being
     * updated). Backed by the `requirements_org_name_unique` index.
     *
     * @return array<string, array<int, mixed>>
     */
    private function rules(string $orgId, ?Requirement $existing = null): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:255',
                function (string $attribute, mixed $value, \Closure $fail) use ($orgId, $existing): void {
                    $exists = Requirement::query()
                        ->where('org_id', $orgId)
                        ->whereNull('deleted_at')
                        ->whereRaw('lower(name) = ?', [mb_strtolower((string) $value)])
                        ->when($existing, fn ($q) => $q->whereKeyNot($existing->id))
                        ->exists();

                    if ($exists) {
                        $fail('A requirement with this name already exists.');
                    }
                },
            ],
            'description' => ['nullable', 'string'],
        ];
    }

    private function serialize(Requirement $r): array
    {
        return [
            'id' => $r->id,
            'name' => $r->name,
            'description' => $r->description,
        ];
    }
}
