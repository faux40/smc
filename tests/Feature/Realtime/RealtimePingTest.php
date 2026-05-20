<?php

namespace Tests\Feature\Realtime;

use App\Events\RealtimePing;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Smoke test for the realtime substrate.
 *
 * Permanent — verifies the broadcaster wires up cleanly. If broadcasting
 * breaks (channels file fails to load, Reverb config drifts, the
 * `ShouldBroadcast` contract regresses), this test surfaces it before
 * any feature-level test does.
 */
class RealtimePingTest extends TestCase
{
    public function test_event_is_broadcastable_and_targets_realtime_ping_channel(): void
    {
        $event = new RealtimePing(message: 'hello', originTab: 'tab-uuid-abc');

        $this->assertInstanceOf(ShouldBroadcast::class, $event);

        $channels = $event->broadcastOn();
        $this->assertCount(1, $channels);
        $this->assertInstanceOf(Channel::class, $channels[0]);
        $this->assertSame('realtime-ping', $channels[0]->name);
    }

    public function test_broadcast_payload_includes_origin_tab(): void
    {
        $event = new RealtimePing(message: 'hi', originTab: 'tab-uuid-xyz');

        $payload = $event->broadcastWith();

        $this->assertSame('hi', $payload['message']);
        $this->assertSame('tab-uuid-xyz', $payload['origin_tab']);
    }

    public function test_origin_tab_resolves_from_request_header_when_not_passed(): void
    {
        request()->headers->set('X-Origin-Tab', 'header-resolved-tab');

        $event = new RealtimePing(message: 'auto-resolve');

        $this->assertSame('header-resolved-tab', $event->broadcastWith()['origin_tab']);
    }

    public function test_ping_endpoint_dispatches_event_with_origin_tab_from_header(): void
    {
        Event::fake([RealtimePing::class]);

        $response = $this->withHeaders(['X-Origin-Tab' => 'http-tab-uuid'])
            ->postJson('/realtime/ping', ['message' => 'hi from test']);

        $response->assertOk()->assertJson(['ok' => true]);

        Event::assertDispatched(
            RealtimePing::class,
            fn (RealtimePing $event) => $event->message === 'hi from test'
                && $event->broadcastWith()['origin_tab'] === 'http-tab-uuid'
        );
    }

    public function test_ping_endpoint_works_without_origin_tab_header(): void
    {
        Event::fake([RealtimePing::class]);

        $this->postJson('/realtime/ping', ['message' => 'no-tab'])->assertOk();

        Event::assertDispatched(
            RealtimePing::class,
            fn (RealtimePing $event) => $event->broadcastWith()['origin_tab'] === null
        );
    }
}
