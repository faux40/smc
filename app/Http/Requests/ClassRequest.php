<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

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
            'instructor' => ['nullable', 'string', 'max:255'],
            'total_hours' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
