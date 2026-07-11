<?php

namespace App\Http\Controllers;

use App\Events\MergeValuesChanged;
use App\Models\MergeField;
use App\Models\MergeValue;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Org merge data (Phase D1) — one value per (field, location, department)
 * variation; '' location/department = the org-wide default row. Writes
 * are an idempotent PUT upsert keyed on the variation; clearing an
 * override is DELETE (hard — see MergeValue).
 */
class MergeValuesController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', MergeValue::class);

        $values = MergeValue::query()
            ->where('org_id', $request->user()->org_id)
            ->orderBy('merge_field_id')
            ->orderBy('location')
            ->orderBy('department')
            ->get();

        return response()->json($values->map(fn (MergeValue $v) => $this->serialize($v)));
    }

    public function upsert(Request $request): JsonResponse
    {
        Gate::authorize('create', MergeValue::class);

        $orgId = $request->user()->org_id;

        $data = $request->validate([
            'merge_field_id' => ['required', 'string'],
            'location' => ['nullable', 'string', 'max:255'],
            'department' => ['nullable', 'string', 'max:255'],
        ]);

        // Resolve the field un-scoped, then check visibility explicitly —
        // a foreign org's field is a validation failure, not a 404, so the
        // form can surface it on the field input.
        $field = MergeField::query()->find($data['merge_field_id']);
        if ($field === null || ($field->org_id !== null && $field->org_id !== $orgId)) {
            throw ValidationException::withMessages([
                'merge_field_id' => 'Unknown merge field.',
            ]);
        }

        // The value's shape is the field type's contract (string vs array) —
        // MergeFieldsController blocks type changes while values exist for
        // the same reason.
        $validated = $request->validate([
            'value' => $this->valueRules($field),
            ...($field->type === 'list' ? ['value.*' => ['string', 'max:2000']] : []),
        ]);

        $value = MergeValue::query()->updateOrCreate(
            [
                'org_id' => $orgId,
                'merge_field_id' => $field->id,
                'location' => $data['location'] ?? '',
                'department' => $data['department'] ?? '',
            ],
            ['value' => $validated['value']],
        );

        event(new MergeValuesChanged($orgId));

        return response()->json($this->serialize($value));
    }

    public function destroy(MergeValue $mergeValue): JsonResponse
    {
        Gate::authorize('delete', $mergeValue);

        $orgId = $mergeValue->org_id;
        $mergeValue->delete();

        event(new MergeValuesChanged($orgId));

        return response()->json(['ok' => true]);
    }

    /**
     * @return array<int, mixed>
     */
    private function valueRules(MergeField $field): array
    {
        return match ($field->type) {
            'list' => ['required', 'array', 'max:200'],
            'date' => ['required', 'date_format:Y-m-d'],
            'multiline' => ['required', 'string', 'max:20000'],
            default => ['required', 'string', 'max:2000'],
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(MergeValue $v): array
    {
        return [
            'id' => $v->id,
            'merge_field_id' => $v->merge_field_id,
            'location' => $v->location,
            'department' => $v->department,
            'value' => $v->value,
        ];
    }
}
