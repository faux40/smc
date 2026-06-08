<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

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
        return [
            'user_ids' => ['required', 'array', 'min:1'],
            'user_ids.*' => ['required', 'string', 'uuid'],
            'source_type' => ['required', 'string', 'in:direct,requirement'],
            'training_id' => ['required_if:source_type,direct', 'nullable', 'string', 'uuid'],
            'requirement_id' => ['required_if:source_type,requirement', 'nullable', 'string', 'uuid'],
        ];
    }
}
