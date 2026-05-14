<?php

namespace App\Notifications;

use App\Models\Assignment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to a User when the daily watchdog (`assignments:scan-due-states`)
 * sees one of their assignments cross *into* `overdue`. Edge-triggered:
 * fires once on the transition. An assignment that goes straight from
 * `current` to `overdue` (watchdog missed the `due_soon` window) fires
 * only this, not `AssignmentDueSoon`.
 *
 * Phase 15.3: database + broadcast channels. Mail channel (gated by the
 * user's notification preferences) lands in 15.4.
 */
class AssignmentOverdue extends Notification implements ShouldBroadcast
{
    use Queueable;

    public function __construct(
        public readonly Assignment $assignment,
        public readonly ?string $nextDueDate,
        public readonly ?int $daysUntilDue,
    ) {
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database', 'broadcast'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'assignment_overdue',
            'assignment_id' => $this->assignment->id,
            'requirement_id' => $this->assignment->requirement_id,
            'name' => $this->assignment->name,
            'next_due_date' => $this->nextDueDate,
            'days_until_due' => $this->daysUntilDue,
        ];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toArray($notifiable));
    }
}
