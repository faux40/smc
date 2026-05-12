<?php

namespace Tests\Feature\Realtime;

use App\Broadcasting\OrgChannel;
use App\Models\Organization;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `org.{orgId}` is registered as a class-based private channel so the
 * auth callback is directly unit-testable without going through
 * /broadcasting/auth (which short-circuits under the null test driver).
 */
class OrgChannelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_grants_same_org_user_with_password(): void
    {
        $user = User::factory()->create();

        $this->assertTrue((new OrgChannel())->join($user, $user->org_id));
    }

    public function test_denies_cross_org_user(): void
    {
        $a = User::factory()->create();
        $b = User::factory()->create();

        $this->assertFalse((new OrgChannel())->join($a, $b->org_id));
    }

    public function test_denies_no_login_user(): void
    {
        $org = Organization::factory()->create();
        $noLogin = User::factory()->forOrganization($org)->noLogin()->create();

        $this->assertFalse((new OrgChannel())->join($noLogin, $org->id));
    }
}
