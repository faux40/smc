<?php

namespace App\Http\Requests;

use App\Models\Assignment;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Validator for POST /api/bulk-assignments. The bulk flow ships its own
 * Request class because the payload shape differs (a `pairs[]` array of
 * {user_id, requirement_id} versus AssignmentRequest's flat fields), and
 * because some rules reach cross-org state — authorising here (instead
 * of in the controller) means cross-org callers see 403, not 422 with
 * leaked foreign-row info. Same pattern as CompletionRequest.
 *
 * Timing rules mirror AssignmentRequest verbatim — at-least-one flag,
 * initial_only ⇄ repeating mutex, std_freq required when repeating,
 * start_date required, end_date ≥ start.
 */
class BulkAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::check('create', Assignment::class);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $orgId = Auth::user()->org_id;

        return [
            'pairs' => ['required', 'array', 'min:1'],
            'pairs.*.user_id' => [
                'required',
                'string',
                Rule::exists('users', 'id')
                    ->where('org_id', $orgId)
                    ->whereNull('deleted_at'),
            ],
            'pairs.*.requirement_id' => [
                'required',
                'string',
                Rule::exists('requirements', 'id')
                    ->where('org_id', $orgId)
                    ->whereNull('deleted_at'),
            ],
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
            'start_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
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
                $v->errors()->add('repeating', 'An assignment can be initial-only OR repeating, not both.');
            }

            if ($repeating && empty($data['std_freq_id'])) {
                $v->errors()->add('std_freq_id', 'Repeating assignments require a frequency.');
            }
        });
    }
}
