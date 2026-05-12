<?php

namespace App\Concerns;

use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

trait ProfileValidationRules
{
    /**
     * @return array<string, array<int, ValidationRule|array<mixed>|string>>
     */
    protected function profileRules(int|string|null $userId = null): array
    {
        return [
            ...$this->nameRules(),
            'email' => $this->emailRules($userId),
        ];
    }

    /**
     * Five-column name shape: f_name + l_name required, the rest optional.
     *
     * @return array<string, array<int, ValidationRule|array<mixed>|string>>
     */
    protected function nameRules(): array
    {
        return [
            'f_name' => ['required', 'string', 'max:255'],
            'm_name' => ['nullable', 'string', 'max:255'],
            'l_name' => ['required', 'string', 'max:255'],
            'prefix_name' => ['nullable', 'string', 'max:32'],
            'suffix_name' => ['nullable', 'string', 'max:32'],
        ];
    }

    /**
     * @return array<int, ValidationRule|array<mixed>|string>
     */
    protected function emailRules(int|string|null $userId = null): array
    {
        return [
            'required',
            'string',
            'email',
            'max:255',
            $userId === null
                ? Rule::unique(User::class)
                : Rule::unique(User::class)->ignore($userId),
        ];
    }
}
