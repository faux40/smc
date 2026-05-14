<?php

namespace App\Notifications;

use App\Models\Assignment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to a User when the daily watchdog (`assignments:scan-due-states`)
 * sees one of their assignments cross *into* `due_soon`. Edge-triggered:
 * fires once on the transition, not every day the assignment stays due.
 *
 * Phase 15.3: database + broadcast channels. Mail channel (gated by the
 * user's notification preferences) lands in 15.4.
 */
class AssignmentDueSoon extends Notification implements ShouldBroadcast
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
            'kind' => 'assignment_due_soon',
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
