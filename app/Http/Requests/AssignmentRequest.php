<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Shared validator for create + update of assignments.
 *
 * Timing rules mirror RqmtElementRequest:
 * - at least one of initial_only / repeating / as_needed must be true
 * - initial_only ⇄ repeating are mutually exclusive
 * - std_freq_id required when repeating; must be same-org
 *
 * Date rules:
 * - start_date is required at create + update (admin must set it explicitly)
 * - end_date is optional; if present, must be ≥ start_date
 *
 * FK rules:
 * - user_id and requirement_id are required on create only (relationship
 *   doesn't move on update — admin would delete + recreate). Both must
 *   resolve to same-org rows.
 */
class AssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Policy authz happens in the controller.
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $orgId = Auth::user()->org_id;
        $isCreate = $this->isMethod('post');

        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
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

        if ($isCreate) {
            $rules['user_id'] = [
                'required',
                'string',
                Rule::exists('users', 'id')
                    ->where('org_id', $orgId)
                    ->whereNull('deleted_at'),
            ];
            $rules['requirement_id'] = [
                'required',
                'string',
                Rule::exists('requirements', 'id')
                    ->where('org_id', $orgId)
                    ->whereNull('deleted_at'),
            ];
        }

        return $rules;
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
