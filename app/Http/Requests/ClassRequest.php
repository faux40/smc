<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create + update validation for a class. Per-action policy authz happens in
 * the controller. Sub-resources (trainings, enrollments) have their own
 * endpoints + inline validation.
 */
class ClassRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'scheduled_date' => ['required', 'date'],
            'location' => ['nullable', 'string', 'max:255'],
            'training_location' => ['nullable', 'string', 'max:255'],
            'training_address' => ['nullable', 'string', 'max:1000'],
            'instructor' => ['nullable', 'string', 'max:255'],
            'total_hours' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
            // Optional at-create-time training picker (snapshotted on store).
            'training_ids' => ['nullable', 'array'],
            'training_ids.*' => [
                'string',
                Rule::exists('trainings', 'id')
                    ->where('org_id', $this->user()->org_id)
                    ->whereNull('deleted_at'),
            ],
        ];
    }
}
