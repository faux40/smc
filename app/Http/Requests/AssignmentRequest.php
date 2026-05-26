<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * Shared validator for create + update of assignments.
 *
 * Timing lives on the requirement's elements, not the assignment, so this
 * request only covers identity + the active window.
 *
 * Date rules:
 * - start_date is required at create + update (admin must set it explicitly)
 * - end_date is optional; if present, must be ≥ start_date
 *
 * FK rules:
 * - user_id and requirement_id are required on create only (relationship
 *   doesn't move on update — admin would delete + recreate). Both must
 *   resolve to same-org rows.
 * - on create, the (user, requirement) pair must not already have an active
 *   (un-ended, non-deleted) assignment — backed by the partial unique index
 *   `assignments_active_unique`. This rule just turns the would-be DB error
 *   into a clean 422.
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
            'description' => ['nullable', 'string'],
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
                // No duplicate active assignment for this (user, requirement).
                Rule::unique('assignments', 'requirement_id')
                    ->where(fn ($q) => $q
                        ->where('user_id', $this->input('user_id'))
                        ->whereNull('end_date')
                        ->whereNull('deleted_at')),
            ];
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'requirement_id.unique' => 'This user already has an active assignment for that requirement.',
        ];
    }
}
