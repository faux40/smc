<?php

namespace Tests\Feature\Realtime;

use App\Events\OrganizationCreated;
use App\Events\OrganizationDeleted;
use App\Events\OrganizationUpdated;
use App\Events\UserRegistered;
use App\Events\UserSoftDeleted;
use App\Events\UserUpdated;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Per the Reverb-first principle: each state-mutating lifecycle controller
 * dispatches a corresponding event, and each event broadcasts on the
 * org.{orgId} private channel with origin_tab in the payload.
 */
class LifecycleBroadcastsTest extends TestCase
{
    use RefreshDatabase;

    public static function lifecycleEvents(): array
    {
        return [
            'OrganizationCreated' => [OrganizationCreated::class],
            'OrganizationUpdated' => [OrganizationUpdated::class],
            'OrganizationDeleted' => [OrganizationDeleted::class],
            'UserRegistered' => [UserRegistered::class],
            'UserSoftDeleted' => [UserSoftDeleted::class],
        ];
    }

    #[DataProvider('lifecycleEvents')]
    public function test_event_implements_should_broadcast(string $eventClass): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->forOrganization($org)->create();

        $event = $this->instantiate($eventClass, $org, $user);

        $this->assertInstanceOf(ShouldBroadcast::class, $event);
    }

    #[DataProvider('lifecycleEvents')]
    public function test_event_broadcasts_on_org_private_channel(string $eventClass): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->forOrganization($org)->create();

        $event = $this->instantiate($eventClass, $org, $user);

        $channels = $event->broadcastOn();
        $this->assertNotEmpty($channels);
        $this->assertInstanceOf(PrivateChannel::class, $channels[0]);
        $this->assertSame("private-org.{$org->id}", $channels[0]->name);
    }

    #[DataProvider('lifecycleEvents')]
    public function test_event_payload_includes_origin_tab(string $eventClass): void
    {
        request()->headers->set('X-Origin-Tab', 'tab-xyz');

        $org = Organization::factory()->create();
        $user = User::factory()->forOrganization($org)->create();

        $event = $this->instantiate($eventClass, $org, $user);

        $this->assertArrayHasKey('origin_tab', $event->broadcastWith());
        $this->assertSame('tab-xyz', $event->broadcastWith()['origin_tab']);
    }

    /**
     * The store patches its cached rows from these payloads, so the sortable
     * (last-name-first) display name must ride along — otherwise realtime
     * updates would revert list rows to a stale or unformatted name.
     */
    public function test_user_broadcasts_carry_sortable_name(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->forOrganization($org)
            ->create(['f_name' => 'Ada', 'm_name' => 'Augusta', 'l_name' => 'Lovelace']);

        foreach ([new UserRegistered($user), new UserUpdated($user)] as $event) {
            $payload = $event->broadcastWith();
            $this->assertArrayHasKey('sort_name', $payload);
            $this->assertSame('Lovelace, Ada Augusta', $payload['sort_name']);
        }
    }

    private function instantiate(string $eventClass, Organization $org, User $user): object
    {
        // Per-event constructor signature: org-flavored events take an Org;
        // user-flavored take a User. Both kinds resolve org_id internally
        // so broadcastOn() can produce the right channel.
        return match (true) {
            str_contains($eventClass, 'Organization') => new $eventClass($org),
            default => new $eventClass($user),
        };
    }
}
