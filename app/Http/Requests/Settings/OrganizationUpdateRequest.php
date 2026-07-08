<?php

namespace App\Http\Requests\Settings;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class OrganizationUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && $user->hasAnyRole(['Owner', 'SuperAdmin']);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'timezone' => ['required', 'timezone'],
            'due_soon_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'expiring_soon_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            // 0 is accepted and normalised to null (disabled) in the controller.
            'overdue_reminder_interval_days' => ['nullable', 'integer', 'min:0', 'max:365'],
        ];
    }
}
