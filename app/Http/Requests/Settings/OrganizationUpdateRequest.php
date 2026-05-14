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
            // Phase 15.6 — drives org-local scheduling of the weekly
            // manager digest. `timezone` rule accepts any valid IANA
            // identifier.
            'timezone' => ['required', 'timezone'],
        ];
    }
}
