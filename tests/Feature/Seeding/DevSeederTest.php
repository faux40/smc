<?php

namespace Tests\Feature\Seeding;

use App\Models\Organization;
use App\Models\StdFrequency;
use App\Models\User;
use Database\Seeders\DevSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DevSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_seeder_creates_john_balestrini_owner_of_bg(): void
    {
        $this->seed(DevSeeder::class);

        $user = User::query()
            ->withoutGlobalScope('organization')
            ->where('email', 'john@barrittgroup.com')
            ->first();

        $this->assertNotNull($user);
        $this->assertSame('John', $user->f_name);
        $this->assertSame('Balestrini', $user->l_name);
        $this->assertNotNull($user->email_verified_at);
        $this->assertTrue(Hash::check('Admin1234!', $user->password));
        $this->assertTrue($user->hasRole('Owner'));

        $org = Organization::find($user->org_id);
        $this->assertSame('BG', $org->name);
        $this->assertSame($user->id, $org->owner_user_id);
    }

    public function test_seeder_creates_default_std_frequencies_for_bg(): void
    {
        $this->seed(DevSeeder::class);

        $user = User::query()->withoutGlobalScope('organization')->where('email', 'john@barrittgroup.com')->firstOrFail();

        $names = StdFrequency::query()
            ->withoutGlobalScope('organization')
            ->where('org_id', $user->org_id)
            ->pluck('name')
            ->all();

        $this->assertContains('Annual', $names);
        $this->assertContains('Semi-Annual', $names);
        $this->assertContains('Quarterly', $names);
        $this->assertContains('Monthly', $names);
        $this->assertContains('Every 10 days', $names);
    }

    public function test_seeder_is_idempotent(): void
    {
        $this->seed(DevSeeder::class);
        $this->seed(DevSeeder::class);
        $this->seed(DevSeeder::class);

        $this->assertSame(
            1,
            User::query()->withoutGlobalScope('organization')->where('email', 'john@barrittgroup.com')->count(),
        );
    }
}
