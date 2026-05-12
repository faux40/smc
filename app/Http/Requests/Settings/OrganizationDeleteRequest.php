<?php

namespace App\Http\Requests\Settings;

use App\Concerns\PasswordValidationRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class OrganizationDeleteRequest extends FormRequest
{
    use PasswordValidationRules;

    /**
     * Only the Owner can delete the organization.
     */
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && $user->hasRole('Owner');
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'password' => $this->currentPasswordRules(),
        ];
    }
}
