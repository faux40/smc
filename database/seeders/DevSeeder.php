<?php

namespace Database\Seeders;

use App\Models\Organization;
use App\Models\StdFrequency;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Dev-only seeder: a known-good owner account for quick login.
 *
 * John Balestrini @ BG org. Email-verified so login works immediately.
 * Idempotent: re-running leaves the org + user intact. Gated to the
 * `local` env from DatabaseSeeder so production never sees this.
 */
class DevSeeder extends Seeder
{
    private const ORG_NAME = 'BG';

    private const USER_EMAIL = 'john@barrittgroup.com';

    private const USER_PASSWORD = 'Admin1234!';

    /**
     * @var array<int, array{name: string, repeat_days: int}>
     */
    private const DEFAULT_FREQUENCIES = [
        ['name' => 'Annual', 'repeat_days' => 365],
        ['name' => 'Semi-Annual', 'repeat_days' => 180],
        ['name' => 'Quarterly', 'repeat_days' => 90],
        ['name' => 'Monthly', 'repeat_days' => 30],
        ['name' => 'Every 10 days', 'repeat_days' => 10],
    ];

    public function run(): void
    {
        if (User::query()->withoutGlobalScope('organization')->where('email', self::USER_EMAIL)->exists()) {
            return;
        }

        DB::transaction(function (): void {
            $org = Organization::create(['name' => self::ORG_NAME]);

            $user = User::create([
                'org_id' => $org->id,
                'f_name' => 'John',
                'l_name' => 'Balestrini',
                'email' => self::USER_EMAIL,
                'email_verified_at' => now(),
                'password' => Hash::make(self::USER_PASSWORD),
                'status' => 'active',
            ]);

            $org->update(['owner_user_id' => $user->id]);
            $user->assignRole('Owner');

            foreach (self::DEFAULT_FREQUENCIES as $row) {
                StdFrequency::create([
                    'org_id' => $org->id,
                    'name' => $row['name'],
                    'repeat_days' => $row['repeat_days'],
                ]);
            }
        });
    }
}
