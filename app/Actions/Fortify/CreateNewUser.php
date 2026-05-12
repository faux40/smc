<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Events\OrganizationCreated;
use App\Events\UserRegistered;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\CreatesNewUsers;

/**
 * New-user-creates-new-org transaction.
 *
 * Cloning the template org keeps org defaults (std_frequencies, future
 * seed rows) in a single place; we just copy the row and back-fill the
 * owner. The new user becomes the org's first member, owner, and Owner
 * role-holder.
 */
class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules, ProfileValidationRules;

    /**
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        Validator::make($input, [
            ...$this->profileRules(),
            'org_name' => ['required', 'string', 'max:255'],
            'password' => $this->passwordRules(),
        ])->validate();

        [$user, $org] = DB::transaction(function () use ($input): array {
            $org = Organization::create(['name' => $input['org_name']]);

            $user = User::create([
                'org_id' => $org->id,
                'name' => $input['name'],
                'email' => $input['email'],
                'password' => $input['password'],
            ]);

            $org->update(['owner_user_id' => $user->id]);
            $user->assignRole('Owner');

            return [$user->fresh(), $org->fresh()];
        });

        event(new OrganizationCreated($org));
        event(new UserRegistered($user));

        return $user;
    }
}
