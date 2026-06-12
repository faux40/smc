<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BulkTrainingAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasAnyRole(['Owner', 'SuperAdmin', 'Admin', 'Manager']) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        // training_id/requirement_id are org-scoped here (O1) so a foreign
        // id fails validation outright — the service-layer findOrFail also
        // blocks cross-org, but that guard sits one refactor away from
        // being lost.
        return [
            'user_ids' => ['required', 'array', 'min:1'],
            'user_ids.*' => ['required', 'string', 'uuid'],
            'source_type' => ['required', 'string', 'in:direct,requirement'],
            'training_id' => [
                'required_if:source_type,direct', 'nullable', 'string', 'uuid',
                Rule::exists('trainings', 'id')
                    ->where('org_id', $this->user()->org_id)
                    ->whereNull('deleted_at'),
            ],
            'requirement_id' => [
                'required_if:source_type,requirement', 'nullable', 'string', 'uuid',
                Rule::exists('requirements', 'id')
                    ->where('org_id', $this->user()->org_id)
                    ->whereNull('deleted_at'),
            ],
        ];
    }
}
