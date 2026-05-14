<?php

namespace App\Notifications;

use App\Models\Assignment;
use App\Notifications\Concerns\ChannelsWithGatedMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to a User when the daily watchdog (`assignments:scan-due-states`)
 * sees one of their assignments cross *into* `overdue`. Edge-triggered:
 * fires once on the transition. An assignment that goes straight from
 * `current` to `overdue` (watchdog missed the `due_soon` window) fires
 * only this, not `AssignmentDueSoon`.
 *
 * Delivers to the in-app inbox (`database`) + realtime bell
 * (`broadcast`); the `mail` channel is added by ChannelsWithGatedMail
 * when the deployment flag is on (Phase 15.4).
 */
class AssignmentOverdue extends Notification implements ShouldBroadcast, ShouldQueue
{
    use ChannelsWithGatedMail, Queueable;

    public function __construct(
        public readonly Assignment $assignment,
        public readonly ?string $nextDueDate,
        public readonly ?int $daysUntilDue,
    ) {
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

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->error()
            ->subject('Requirement overdue: '.$this->assignment->name)
            ->greeting('Hello '.$notifiable->name.',')
            ->line('Your requirement "'.$this->assignment->name.'" is now overdue.');

        if ($this->nextDueDate !== null) {
            // daysUntilDue is negative once overdue — report the magnitude.
            $overdueBy = $this->daysUntilDue !== null
                ? ' ('.abs($this->daysUntilDue).' day'.(abs($this->daysUntilDue) === 1 ? '' : 's').' overdue)'
                : '';
            $mail->line('Due date was: '.$this->nextDueDate.$overdueBy.'.');
        }

        return $mail
            ->action('View your requirements', route('users.show', $notifiable))
            ->line('Please log a completion as soon as possible to return to compliance.');
    }
}
