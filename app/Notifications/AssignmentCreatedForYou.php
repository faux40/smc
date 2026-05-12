<?php

namespace App\Notifications;

use App\Models\Assignment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to a User when an admin / manager creates an assignment for
 * them. Phase 15.1: database channel persists the row; broadcast
 * channel lights up the in-app inbox bell in realtime (the inbox UI
 * lands in 15.2). Mail channel comes in 15.4.
 */
class AssignmentCreatedForYou extends Notification implements ShouldBroadcast
{
    use Queueable;

    public function __construct(public readonly Assignment $assignment)
    {
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database', 'broadcast'];
    }

    /**
     * Payload persisted into notifications.data + delivered to the
     * frontend on broadcast. The inbox UI in 15.2 renders directly
     * from this shape.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'assignment_created',
            'assignment_id' => $this->assignment->id,
            'requirement_id' => $this->assignment->requirement_id,
            'name' => $this->assignment->name,
            'description' => $this->assignment->description,
            'start_date' => optional($this->assignment->start_date)->toDateString(),
            'end_date' => optional($this->assignment->end_date)->toDateString(),
        ];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        // Wrap the same payload for the realtime channel so 15.2's
        // inbox store can upsert on receipt.
        return new BroadcastMessage($this->toArray($notifiable));
    }
}
