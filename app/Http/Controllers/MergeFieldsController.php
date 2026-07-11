<?php

namespace App\Http\Controllers;

use App\Events\MergeFieldsChanged;
use App\Models\MergeField;
use App\Models\MergeValue;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Merge-field definitions — the `${key}` vocabulary doc templates draw
 * from (Phase D1). Index returns system + org fields; definition CRUD
 * touches org fields only (policy blocks system rows).
 */
class MergeFieldsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', MergeField::class);

        $fields = MergeField::query()
            ->visibleTo($request->user()->org_id)
            // Grouped-form order: named groups alphabetically, ungrouped
            // last (IS NULL sorts 0/1 identically on Postgres + sqlite),
            // then explicit seq, then label as the tiebreak.
            ->orderByRaw('field_group IS NULL')
            ->orderBy('field_group')
            ->orderBy('seq')
            ->orderBy('label')
            ->get();

        return response()->json($fields->map(fn (MergeField $f) => $this->serialize($f)));
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('create', MergeField::class);

        $data = $request->validate($this->rules($request, null));

        $field = MergeField::create([
            ...$data,
            'org_id' => $request->user()->org_id,
        ]);

        event(new MergeFieldsChanged($field->org_id));

        return response()->json($this->serialize($field), 201);
    }

    public function update(Request $request, MergeField $mergeField): JsonResponse
    {
        Gate::authorize('update', $mergeField);

        $data = $request->validate($this->rules($request, $mergeField));

        $mergeField->update($data);

        event(new MergeFieldsChanged($mergeField->org_id));

        return response()->json($this->serialize($mergeField->fresh()));
    }

    public function destroy(MergeField $mergeField): JsonResponse
    {
        Gate::authorize('delete', $mergeField);

        $orgId = $mergeField->org_id;

        // Values are hard rows under a soft-deleted definition: clear them
        // so a re-created key starts blank rather than resurrecting stale
        // data (mirrors TagsController's explicit pivot clear).
        MergeValue::withoutGlobalScope('organization')
            ->where('merge_field_id', $mergeField->id)
            ->delete();
        $mergeField->delete();

        event(new MergeFieldsChanged($orgId));

        return response()->json(['ok' => true]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function rules(Request $request, ?MergeField $existing): array
    {
        $orgId = $request->user()->org_id;

        return [
            'key' => [
                'required', 'string', 'max:64',
                // The ${key} token grammar. Also what D2's template
                // placeholder-extraction will match.
                'regex:/^[a-z][a-z0-9_]*$/',
                // One definition per key across system + this org (no
                // shadowing — decision 2026-07-11). Soft-deleted rows
                // don't block; renames exclude self.
                function (string $attribute, mixed $value, \Closure $fail) use ($orgId, $existing): void {
                    $clash = MergeField::query()
                        ->where('key', $value)
                        ->where(fn ($q) => $q->whereNull('org_id')->orWhere('org_id', $orgId))
                        ->when($existing, fn ($q) => $q->whereKeyNot($existing->id))
                        ->exists();
                    if ($clash) {
                        $fail('That key is already defined (system fields cannot be shadowed).');
                    }
                },
            ],
            'label' => ['required', 'string', 'max:255'],
            'type' => [
                'required', Rule::in(MergeField::TYPES),
                // Stored values are shaped by type (string vs array) —
                // changing type under existing data would break consumers.
                function (string $attribute, mixed $value, \Closure $fail) use ($existing): void {
                    if ($existing && $value !== $existing->type && $existing->values()->withoutGlobalScope('organization')->exists()) {
                        $fail('Type cannot change while values exist — clear the stored values first.');
                    }
                },
            ],
            'field_group' => ['nullable', 'string', 'max:255'],
            'help' => ['nullable', 'string', 'max:2000'],
            'seq' => ['sometimes', 'integer', 'min:0'],
            'draft' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(MergeField $f): array
    {
        return [
            'id' => $f->id,
            'key' => $f->key,
            'label' => $f->label,
            'type' => $f->type,
            'field_group' => $f->field_group,
            'help' => $f->help,
            'seq' => $f->seq,
            'draft' => $f->draft,
            'is_system' => $f->isSystem(),
            'can_edit' => Gate::check('update', $f),
            'can_delete' => Gate::check('delete', $f),
        ];
    }
}
