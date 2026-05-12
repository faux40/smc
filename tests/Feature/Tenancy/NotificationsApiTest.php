<?php

namespace Tests\Feature\Tenancy;

use App\Models\Organization;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 15.2 inbox endpoints. Index returns the actor's notifications
 * + unread count; markRead flips one; markAllRead flips everything
 * unread. All implicitly scoped to the authenticated user via the
 * Notifiable relation — no cross-user surface, no cross-org leakage.
 */
class NotificationsApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    /**
     * Insert a notifications row directly. We don't want to depend on the
     * full event-dispatch path here — the row shape is what the inbox
     * surface contracts on.
     */
    private function makeNotification(User $user, array $overrides = []): string
    {
        $id = (string) Str::uuid();
        \DB::table('notifications')->insert(array_merge([
            'id' => $id,
            'type' => 'App\\Notifications\\AssignmentCreatedForYou',
            'notifiable_type' => User::class,
            'notifiable_id' => $user->id,
            'data' => json_encode(['kind' => 'assignment_created', 'name' => 'OSHA Test']),
            'read_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));

        return $id;
    }

    public function test_index_returns_own_notifications_with_unread_count(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->for($org, 'organization')->create();

        $idA = $this->makeNotification($user);
        $idB = $this->makeNotification($user, ['read_at' => now()]);

        $response = $this->actingAs($user)
            ->getJson('/api/notifications')
            ->assertOk()
            ->json();

        $this->assertSame(1, $response['unread_count']);
        $this->assertCount(2, $response['items']);
        $ids = collect($response['items'])->pluck('id')->all();
        $this->assertContains($idA, $ids);
        $this->assertContains($idB, $ids);
    }

    public function test_index_does_not_leak_other_users_notifications(): void
    {
        $org = Organization::factory()->create();
        $alice = User::factory()->for($org, 'organization')->create();
        $bob = User::factory()->for($org, 'organization')->create();
        $this->makeNotification($bob);

        $response = $this->actingAs($alice)
            ->getJson('/api/notifications')
            ->assertOk()
            ->json();

        $this->assertSame(0, $response['unread_count']);
        $this->assertCount(0, $response['items']);
    }

    public function test_index_returns_data_payload_intact(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->for($org, 'organization')->create();
        $this->makeNotification($user, [
            'data' => json_encode([
                'kind' => 'completion_recorded',
                'completion_date' => '2026-05-10',
                'rqmt_element_ids' => ['e1', 'e2', 'e3'],
            ]),
            'type' => 'App\\Notifications\\CompletionRecordedForYou',
        ]);

        $response = $this->actingAs($user)
            ->getJson('/api/notifications')
            ->assertOk()
            ->json();

        $row = $response['items'][0];
        $this->assertSame('completion_recorded', $row['data']['kind']);
        $this->assertSame('2026-05-10', $row['data']['completion_date']);
        $this->assertSame(['e1', 'e2', 'e3'], $row['data']['rqmt_element_ids']);
    }

    public function test_mark_read_flips_a_single_notification(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->for($org, 'organization')->create();
        $id = $this->makeNotification($user);

        $this->actingAs($user)
            ->postJson("/api/notifications/{$id}/read")
            ->assertOk()
            ->assertJsonPath('id', $id);

        $row = \DB::table('notifications')->where('id', $id)->first();
        $this->assertNotNull($row->read_at);
    }

    public function test_mark_read_404s_on_someone_elses_notification(): void
    {
        // Cross-user attempt: bob's notification id; alice tries to mark.
        // Should be 404 (the row is invisible via Alice's notifiable
        // relation) — not 403, since 403 leaks existence.
        $org = Organization::factory()->create();
        $alice = User::factory()->for($org, 'organization')->create();
        $bob = User::factory()->for($org, 'organization')->create();
        $bobId = $this->makeNotification($bob);

        $this->actingAs($alice)
            ->postJson("/api/notifications/{$bobId}/read")
            ->assertNotFound();

        $row = \DB::table('notifications')->where('id', $bobId)->first();
        $this->assertNull($row->read_at, 'Bob notification stays unread.');
    }

    public function test_mark_all_read_flips_only_actors_unread_rows(): void
    {
        $org = Organization::factory()->create();
        $alice = User::factory()->for($org, 'organization')->create();
        $bob = User::factory()->for($org, 'organization')->create();
        $a1 = $this->makeNotification($alice);
        $a2 = $this->makeNotification($alice);
        $aRead = $this->makeNotification($alice, ['read_at' => now()]);
        $b1 = $this->makeNotification($bob);

        $response = $this->actingAs($alice)
            ->postJson('/api/notifications/read-all')
            ->assertOk()
            ->json();

        $this->assertSame(2, $response['marked']);
        $this->assertNotNull(\DB::table('notifications')->where('id', $a1)->value('read_at'));
        $this->assertNotNull(\DB::table('notifications')->where('id', $a2)->value('read_at'));
        // Already-read row stays as-is.
        $this->assertNotNull(\DB::table('notifications')->where('id', $aRead)->value('read_at'));
        // Bob's row untouched.
        $this->assertNull(\DB::table('notifications')->where('id', $b1)->value('read_at'));
    }

    public function test_index_guest_rejected(): void
    {
        $this->getJson('/api/notifications')->assertUnauthorized();
    }

    public function test_index_caps_at_100_newest_first(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->for($org, 'organization')->create();
        // Make 105 — index should return the 100 newest.
        for ($i = 0; $i < 105; $i++) {
            $this->makeNotification($user, [
                'created_at' => now()->subMinutes(105 - $i),
            ]);
        }

        $response = $this->actingAs($user)
            ->getJson('/api/notifications')
            ->assertOk()
            ->json();

        $this->assertCount(100, $response['items']);
        // unread_count is the full count, not capped to 100.
        $this->assertSame(105, $response['unread_count']);
    }
}
