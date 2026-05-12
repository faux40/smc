<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected static ?string $password;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'org_id' => Organization::factory(),
            'f_name' => fake()->firstName(),
            'm_name' => null,
            'l_name' => fake()->lastName(),
            'prefix_name' => null,
            'suffix_name' => null,
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'status' => 'active',
        ];
    }

    /**
     * Email left null. Email verification skipped.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * Two-factor configured.
     */
    public function withTwoFactor(): static
    {
        return $this->state(fn (array $attributes) => [
            'two_factor_secret' => encrypt('secret'),
            'two_factor_recovery_codes' => encrypt(json_encode(['recovery-code-1'])),
            'two_factor_confirmed_at' => now(),
        ]);
    }

    /**
     * No-login user — `email` + `password` both null. These users are managed
     * by admins and never authenticate; they're tracked for compliance only.
     */
    public function noLogin(): static
    {
        return $this->state(fn (array $attributes) => [
            'email' => null,
            'email_verified_at' => null,
            'password' => null,
        ]);
    }

    /**
     * Attach to an existing Organization (skips the default auto-create).
     */
    public function forOrganization(Organization $org): static
    {
        return $this->state(fn (array $attributes) => [
            'org_id' => $org->id,
        ]);
    }

    /**
     * Disabled status — admin-disabled without delete.
     */
    public function disabled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'disabled',
        ]);
    }

    /**
     * Assign a role after the user is created. Caller is responsible for
     * ensuring the role exists (e.g., by seeding RoleSeeder first).
     */
    public function withRole(string $role): static
    {
        return $this->afterCreating(fn (User $user) => $user->assignRole($role));
    }
}
