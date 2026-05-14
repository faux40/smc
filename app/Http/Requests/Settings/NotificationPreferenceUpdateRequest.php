<?php

namespace App\Http\Requests\Settings;

use App\Models\NotificationPreference;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Phase 15.5 — validates the full type × channel toggle matrix. Every
 * known (type, channel) cell must be present and boolean; unknown keys
 * fall outside the generated rules and are dropped by `validated()`.
 */
class NotificationPreferenceUpdateRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = ['preferences' => ['required', 'array']];

        foreach (NotificationPreference::TYPES as $type) {
            $rules["preferences.{$type}"] = ['required', 'array'];

            foreach (NotificationPreference::CHANNELS as $channel) {
                $rules["preferences.{$type}.{$channel}"] = ['required', 'boolean'];
            }
        }

        return $rules;
    }
}
