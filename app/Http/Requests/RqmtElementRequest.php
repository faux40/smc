<?php

namespace App\Http\Requests;

use App\Models\Training;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Shared validator for create + update of rqmt_elements.
 *
 * Timing rules mirror TrainingRequest:
 * - at least one of initial_only / repeating / as_needed must be true
 * - initial_only ⇄ repeating are mutually exclusive
 * - std_freq_id required when repeating; must be same-org
 *
 * Module rules (create only):
 * - module_type ∈ ALLOWED_MODULE_TYPES (Training today)
 * - module_id must resolve to a same-org row of that type
 */
class RqmtElementRequest extends FormRequest
{
    /**
     * Whitelist of module types that can be wired into a rqmt_element.
     * Append as future modules (Inspection / Certification / etc.) land.
     */
    public const ALLOWED_MODULE_TYPES = [
        Training::class,
    ];

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
        ];

        if ($isCreate) {
            // Module fields only set on create. Updating the element doesn't
            // re-point at a different module — the user would delete + recreate.
            $rules['module_type'] = ['required', 'string', Rule::in(self::ALLOWED_MODULE_TYPES)];
            $rules['module_id'] = ['required', 'string'];
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
                $v->errors()->add('repeating', 'An element can be initial-only OR repeating, not both.');
            }

            if ($repeating && empty($data['std_freq_id'])) {
                $v->errors()->add('std_freq_id', 'Repeating elements require a frequency.');
            }

            // Module same-org check (create only — module_type/_id aren't editable on update).
            if ($this->isMethod('post')) {
                $type = $data['module_type'] ?? null;
                $id = $data['module_id'] ?? null;
                if ($type && $id && in_array($type, self::ALLOWED_MODULE_TYPES, true)) {
                    /** @var class-string<Model> $type */
                    $row = $type::query()->withoutGlobalScope('organization')->find($id);
                    $orgId = Auth::user()->org_id;
                    if ($row === null || $row->org_id !== $orgId) {
                        $v->errors()->add('module_id', 'The selected module must belong to your organization.');
                    }
                }
            }
        });
    }
}
