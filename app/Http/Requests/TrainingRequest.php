<?php

namespace App\Http\Requests;

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
            'description' => ['nullable', 'string'],
            'default_hours' => ['nullable', 'numeric', 'min:0'],
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
        });
    }
}
