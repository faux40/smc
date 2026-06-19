<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Events\OrganizationCreated;
use App\Events\UserRegistered;
use App\Models\Organization;
use App\Models\StdFrequency;
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
                'f_name' => $input['f_name'],
                'm_name' => $input['m_name'] ?? null,
                'l_name' => $input['l_name'],
                'prefix_name' => $input['prefix_name'] ?? null,
                'suffix_name' => $input['suffix_name'] ?? null,
                'email' => $input['email'],
                'password' => $input['password'],
            ]);

            $org->update(['owner_user_id' => $user->id]);
            $user->assignRole('Owner');

            // Seed the standard per-org frequency set so downstream forms
            // (Trainings, RqmtElements, Assignments) have a non-empty picker on
            // day one. Admins can edit / delete / add via /settings/frequencies.
            foreach (StdFrequency::STANDARD as $row) {
                StdFrequency::create([
                    'org_id' => $org->id,
                    'name' => $row['name'],
                    'repeat_days' => $row['repeat_days'],
                ]);
            }

            return [$user->fresh(), $org->fresh()];
        });

        event(new OrganizationCreated($org));
        event(new UserRegistered($user));

        return $user;
    }
}
