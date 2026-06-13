<?php

namespace App\Actions;

use App\Events\UserRegistered;
use App\Models\User;

/**
 * Single place that creates a tenant user. Shared by the single-add path
 * (UsersController::store) and the bulk-add path (UsersController::bulkStore)
 * so creation semantics — no-login default (null password), role assignment,
 * the UserRegistered broadcast — never drift between them.
 */
class CreateUser
{
    /** Profile columns this action will copy from the input row, if present. */
    private const FIELDS = [
        'f_name', 'm_name', 'l_name', 'prefix_name', 'suffix_name', 'email',
        'department', 'location', 'job_title', 'employee_number',
        'supervisor_id', 'start_date', 'end_date',
    ];

    /**
     * @param  array<string, mixed>  $data  validated row (extra keys ignored)
     */
    public function handle(string $orgId, array $data, string $role = 'None'): User
    {
        $attrs = ['org_id' => $orgId, 'password' => null];
        foreach (self::FIELDS as $f) {
            $attrs[$f] = $data[$f] ?? null;
        }

        $user = User::create($attrs);
        $user->assignRole($role);

        event(new UserRegistered($user->fresh()));

        return $user;
    }
}
