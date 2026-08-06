<?php

namespace App\Http\Requests;

use App\Models\Training;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Shared validation for create + update. Both shapes are identical; the
 * controller calls authorize() (create) or Gate::authorize('update',...)
 * before resolving validated().
 *
 * Custom rules enforced via withValidator():
 * - At least one of initial_only / repeating / as_needed must be true.
 * - initial_only and repeating are mutually exclusive (per claude_thoughts.md).
 * - std_freq_id is required when repeating=true; nulled otherwise.
 * - std_freq_id must belong to the actor's org when set.
 */
class TrainingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Per-action policy authz happens in the controller.
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $orgId = Auth::user()->org_id;

        return [
            'name' => ['required', 'string', 'max:255'],
            'nickname' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'default_hours' => ['nullable', 'numeric', 'min:0'],
            // Certificate content defaults (snapshotted onto a class topic).
            'cert_title' => ['nullable', 'string', 'max:255'],
            'cert_text' => ['nullable', 'string', 'max:2000'],
            'cert_code' => ['nullable', 'string', 'max:32'],
            // The custom card design printed for this training. System
            // templates (org_id NULL) are shared and assignable; another
            // org's is not visible, let alone selectable.
            'card_template_id' => [
                'nullable',
                'string',
                Rule::exists('card_templates', 'id')
                    ->where(fn ($q) => $q->whereNull('org_id')->orWhere('org_id', $orgId))
                    ->whereNull('deleted_at'),
            ],
            // The stock those cards print onto by default. Same visibility
            // rule as the design: system stocks (org_id NULL) are shared and
            // assignable, another org's is not.
            'card_stock_id' => [
                'nullable',
                'string',
                Rule::exists('card_stocks', 'id')
                    ->where(fn ($q) => $q->whereNull('org_id')->orWhere('org_id', $orgId))
                    ->whereNull('deleted_at'),
            ],
            'default_trainer' => ['nullable', 'string', 'max:255'],
            'default_location' => ['nullable', 'string', 'max:255'],
            'default_address' => ['nullable', 'string', 'max:1000'],
            'initial_only' => ['required', 'boolean'],
            'repeating' => ['required', 'boolean'],
            'as_needed' => ['required', 'boolean'],
            'std_freq_id' => [
                'nullable',
                'string',
                Rule::exists('std_frequencies', 'id')
                    ->where('org_id', $orgId)
                    ->whereNull('deleted_at'),
            ],
            // Hierarchy: the higher training whose credential satisfies this
            // one. Own-org and live only — the picker never offers deleted
            // trainings, though existing chains keep hopping through them.
            'superseded_by_id' => [
                'nullable',
                'string',
                Rule::exists('trainings', 'id')
                    ->where('org_id', $orgId)
                    ->whereNull('deleted_at'),
            ],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $data = $v->getData();
            $initial = (bool) ($data['initial_only'] ?? false);
            $repeating = (bool) ($data['repeating'] ?? false);
            $asNeeded = (bool) ($data['as_needed'] ?? false);

            if (! $initial && ! $repeating && ! $asNeeded) {
                $v->errors()->add('initial_only', 'At least one timing flag (initial-only / repeating / as-needed) must be true.');
            }

            if ($initial && $repeating) {
                $v->errors()->add('repeating', 'A training can be initial-only OR repeating, not both.');
            }

            if ($repeating && empty($data['std_freq_id'])) {
                $v->errors()->add('std_freq_id', 'Repeating trainings require a frequency.');
            }

            $this->rejectHierarchyCycles($v, $data['superseded_by_id'] ?? null);
        });
    }

    /**
     * A training may not (transitively) be satisfied by itself. Walk up from
     * the proposed parent; reaching the edited training is a cycle. Deleted
     * trainings are included in the walk — existing chains hop through them,
     * so a cycle routed through one is just as much a cycle. The depth cap is
     * a sanity bound, not a feature: no real discipline ladders ten levels.
     */
    private function rejectHierarchyCycles(Validator $v, ?string $parentId): void
    {
        if ($parentId === null) {
            return;
        }

        // Route model on update; null on store, where no cycle is possible
        // (nothing can point at a training that doesn't exist yet).
        $editing = $this->route('training');
        $editingId = is_object($editing) ? $editing->id : $editing;

        if ($editingId !== null && $parentId === $editingId) {
            $v->errors()->add('superseded_by_id', 'A training cannot be satisfied by itself.');

            return;
        }

        $seen = [];
        $currentId = $parentId;

        for ($depth = 0; $currentId !== null; $depth++) {
            if ($currentId === $editingId || isset($seen[$currentId])) {
                $v->errors()->add('superseded_by_id', 'This would create a loop in the training hierarchy.');

                return;
            }

            if ($depth >= 10) {
                $v->errors()->add('superseded_by_id', 'The training hierarchy is too deep (10 levels at most).');

                return;
            }

            $seen[$currentId] = true;
            $currentId = Training::withTrashed()
                ->whereKey($currentId)
                ->value('superseded_by_id');
        }
    }
}
