<?php

namespace Tests\Feature\Events;

use App\Events\TrainingAssignmentCreated;
use App\Models\AssignmentSource;
use App\Models\Organization;
use App\Models\Requirement;
use App\Models\Training;
use App\Models\TrainingAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * J6 — broadcast events must not serialize hard-deletable models.
 * TrainingAssignments hard-delete by design (break-out converts/deletes
 * TAs in quick succession), so the event captures a plain payload at
 * dispatch time. Pinned by round-tripping the event through
 * serialize()/unserialize() — what the queue does — after the TA is gone.
 */
class TrainingAssignmentCreatedTest extends TestCase
{
    use RefreshDatabase;

    private function makeTa(): array
    {
        $org = Organization::factory()->create();
        $user = User::factory()->for($org, 'organization')->create();
        $training = Training::factory()->for($org, 'organization')->create();
        $req = Requirement::factory()->for($org, 'organization')->create();
        $ta = TrainingAssignment::factory()->create([
            'org_id' => $org->id,
            'user_id' => $user->id,
            'training_id' => $training->id,
            'name' => 'Fall Protection',
        ]);
        AssignmentSource::create([
            'training_assignment_id' => $ta->id,
            'sourceable_type' => Requirement::class,
            'sourceable_id' => $req->id,
            'added_at' => now(),
        ]);

        return [$org, $ta->fresh()->load('activeSources'), $req];
    }

    public function test_queued_broadcast_survives_ta_hard_delete(): void
    {
        [$org, $ta, $req] = $this->makeTa();

        $event = new TrainingAssignmentCreated($ta, actorId: 'actor-1');

        // The queue serializes the event at dispatch and unserializes it in
        // the worker — by which time the TA may already be hard-deleted.
        $frozen = serialize($event);
        $taId = $ta->id;
        $ta->delete();

        /** @var TrainingAssignmentCreated $thawed */
        $thawed = unserialize($frozen);

        $payload = $thawed->broadcastWith();
        $this->assertSame($taId, $payload['id']);
        $this->assertSame('Fall Protection', $payload['name']);
        $this->assertSame($req->id, $payload['active_sources'][0]['sourceable_id']);
        $this->assertSame('actor-1', $thawed->actorId);
        $this->assertSame(
            'private-org.'.$org->id,
            (string) $thawed->broadcastOn()[0],
        );
    }

    public function test_origin_tab_is_captured_at_dispatch_time(): void
    {
        // RealtimeOrigin reads the X-Origin-Tab request header. The payload
        // must capture it while the HTTP request is still alive — by
        // broadcast time the queue worker has no request to read.
        [, $ta] = $this->makeTa();

        request()->headers->set('X-Origin-Tab', 'tab-123');
        $event = new TrainingAssignmentCreated($ta);
        request()->headers->remove('X-Origin-Tab');

        $payload = unserialize(serialize($event))->broadcastWith();
        $this->assertSame('tab-123', $payload['origin_tab']);
    }
}
