<?php

namespace Tests\Feature\Notifications;

use App\Models\Organization;
use App\Models\User;
use App\Notifications\ManagerComplianceDigest;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Phase 15.6 — `digests:send-manager-compliance`. The command runs
 * hourly and fires for an org only when it's Monday 08:00 in *that
 * org's* timezone, fanning the digest out to manager+ users and
 * guarding against a double-send via `manager_digest_sent_at`.
 *
 * Anchor date: 2026-05-18 is a Monday. America/New_York is UTC-4 in
 * May (EDT), so Monday 08:00 there is 12:00 UTC.
 */
class ManagerComplianceDigestCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function orgWithManager(string $timezone = 'UTC'): array
    {
        $org = Organization::factory()->create(['timezone' => $timezone]);
        $manager = User::factory()->for($org, 'organization')->withRole('Manager')->create();

        return [$org, $manager];
    }

    private function runAt(string $utc): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse($utc, 'UTC'));
        $this->artisan('digests:send-manager-compliance')->assertSuccessful();
    }

    public function test_sends_at_monday_8am_org_local(): void
    {
        [$org, $manager] = $this->orgWithManager('UTC');

        Notification::fake();
        $this->runAt('2026-05-18 08:00:00');

        Notification::assertSentTo($manager, ManagerComplianceDigest::class);
        $this->assertNotNull($org->fresh()->manager_digest_sent_at);
    }

    public function test_silent_outside_the_8am_hour(): void
    {
        [, $manager] = $this->orgWithManager('UTC');

        Notification::fake();
        $this->runAt('2026-05-18 09:00:00');

        Notification::assertNothingSent();
    }

    public function test_silent_on_other_days(): void
    {
        [$org, $manager] = $this->orgWithManager('UTC');

        Notification::fake();
        $this->runAt('2026-05-19 08:00:00'); // Tuesday

        Notification::assertNothingSent();
        $this->assertNull($org->fresh()->manager_digest_sent_at);
    }

    public function test_respects_org_timezone(): void
    {
        [, $nyManager] = $this->orgWithManager('America/New_York');
        [, $utcManager] = $this->orgWithManager('UTC');

        Notification::fake();
        // 12:00 UTC == Monday 08:00 in New York (EDT), == Monday 12:00 in UTC.
        $this->runAt('2026-05-18 12:00:00');

        Notification::assertSentTo($nyManager, ManagerComplianceDigest::class);
        Notification::assertNotSentTo($utcManager, ManagerComplianceDigest::class);
    }

    public function test_double_send_guard_within_the_hour(): void
    {
        [, $manager] = $this->orgWithManager('UTC');

        Notification::fake();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-18 08:00:00', 'UTC'));
        $this->artisan('digests:send-manager-compliance')->assertSuccessful();
        $this->artisan('digests:send-manager-compliance')->assertSuccessful();

        // Second run sees manager_digest_sent_at within this week → skips.
        Notification::assertSentToTimes($manager, ManagerComplianceDigest::class, 1);
    }

    public function test_fans_out_to_manager_plus_roles_only(): void
    {
        $org = Organization::factory()->create(['timezone' => 'UTC']);
        $owner = User::factory()->for($org, 'organization')->withRole('Owner')->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();
        $manager = User::factory()->for($org, 'organization')->withRole('Manager')->create();
        $selfView = User::factory()->for($org, 'organization')->withRole('SelfView')->create();
        $none = User::factory()->for($org, 'organization')->withRole('None')->create();

        Notification::fake();
        $this->runAt('2026-05-18 08:00:00');

        Notification::assertSentTo($owner, ManagerComplianceDigest::class);
        Notification::assertSentTo($admin, ManagerComplianceDigest::class);
        Notification::assertSentTo($manager, ManagerComplianceDigest::class);
        Notification::assertNotSentTo($selfView, ManagerComplianceDigest::class);
        Notification::assertNotSentTo($none, ManagerComplianceDigest::class);
    }

    public function test_skips_an_org_with_no_manager_users(): void
    {
        $org = Organization::factory()->create(['timezone' => 'UTC']);
        $none = User::factory()->for($org, 'organization')->withRole('None')->create();

        Notification::fake();
        $this->runAt('2026-05-18 08:00:00');

        Notification::assertNothingSent();
        // No recipients → no compute, no timestamp written.
        $this->assertNull($org->fresh()->manager_digest_sent_at);
    }
}
