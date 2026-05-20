<?php

namespace App\Events;

use App\Models\Assignment;
use App\Support\RealtimeOrigin;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AssignmentUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public readonly Assignment $assignment) {}

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('org.'.$this->assignment->org_id)];
    }

    public function broadcastWith(): array
    {
        return [
            'id' => $this->assignment->id,
            'user_id' => $this->assignment->user_id,
            'requirement_id' => $this->assignment->requirement_id,
            'name' => $this->assignment->name,
            'description' => $this->assignment->description,
            'initial_only' => $this->assignment->initial_only,
            'repeating' => $this->assignment->repeating,
            'std_freq_id' => $this->assignment->std_freq_id,
            'as_needed' => $this->assignment->as_needed,
            'start_date' => optional($this->assignment->start_date)->toDateString(),
            'end_date' => optional($this->assignment->end_date)->toDateString(),
            'origin_tab' => RealtimeOrigin::tab(),
        ];
    }
}
