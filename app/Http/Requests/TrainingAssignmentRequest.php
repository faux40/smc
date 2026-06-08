<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * Validates the two store paths:
 *   - source_type = "direct"       requires training_id
 *   - source_type = "requirement"  requires requirement_id
 *
 * Both paths require user_id scoped to the actor's org.
 */
class TrainingAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Policy authz handled in the controller.
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $orgId = Auth::user()->org_id;

        return [
            'source_type' => ['required', 'string', Rule::in(['direct', 'requirement'])],

            'user_id' => [
                'required',
                'string',
                Rule::exists('users', 'id')
                    ->where('org_id', $orgId)
                    ->whereNull('deleted_at'),
            ],

            'training_id' => [
                'required_if:source_type,direct',
                'nullable',
                'string',
                Rule::exists('trainings', 'id')
                    ->where('org_id', $orgId)
                    ->whereNull('deleted_at'),
            ],

            'requirement_id' => [
                'required_if:source_type,requirement',
                'nullable',
                'string',
                Rule::exists('requirements', 'id')
                    ->where('org_id', $orgId)
                    ->whereNull('deleted_at'),
            ],
        ];
    }
}
