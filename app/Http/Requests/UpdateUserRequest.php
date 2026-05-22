<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
{
    /**
     * Reserve Owner for the ownership-transfer flow (deferred phase). Every
     * other role is grantable via the edit form. Future tightening: descend
     * grants to actor's max — for now, any admin-or-higher can grant any
     * non-Owner role.
     */
    public const ASSIGNABLE_ROLES = ['SuperAdmin', 'Admin', 'Manager', 'SelfEdit', 'SelfView', 'None'];

    public function authorize(): bool
    {
        return Gate::allows('update', $this->route('user'));
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var User $target */
        $target = $this->route('user');

        // Owner is locked: this form must not mutate role for an Owner
        // target. `prohibited` rejects any incoming role value so a
        // stale client (or a hostile caller) can't silently demote
        // the Owner. Reassignment happens via the (planned) ownership-
        // transfer flow.
        $roleRule = $target->hasRole('Owner')
            ? ['prohibited']
            : ['required', Rule::in(self::ASSIGNABLE_ROLES)];

        return [
            'f_name' => ['required', 'string', 'max:255'],
            'm_name' => ['nullable', 'string', 'max:255'],
            'l_name' => ['required', 'string', 'max:255'],
            'prefix_name' => ['nullable', 'string', 'max:32'],
            'suffix_name' => ['nullable', 'string', 'max:32'],
            'email' => [
                'nullable',
                'string',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($target->id)->whereNull('deleted_at'),
            ],
            'role' => $roleRule,
            'status' => ['required', Rule::in(['active', 'disabled'])],

            // Optional profile fields. Supervisor must be another (non-self)
            // user in the same org; end_date can't precede start_date.
            'department' => ['nullable', 'string', 'max:255'],
            'location' => ['nullable', 'string', 'max:255'],
            'job_title' => ['nullable', 'string', 'max:255'],
            'supervisor_id' => [
                'nullable',
                'string',
                Rule::notIn([$target->id]),
                Rule::exists('users', 'id')
                    ->where('org_id', $target->org_id)
                    ->whereNull('deleted_at'),
            ],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
        ];
    }
}
