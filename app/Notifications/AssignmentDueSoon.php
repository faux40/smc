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
 * sees one of their assignments cross *into* `due_soon`. Edge-triggered:
 * fires once on the transition, not every day the assignment stays due.
 *
 * Delivers to the in-app inbox (`database`) + realtime bell
 * (`broadcast`); the `mail` channel is added by ChannelsWithGatedMail
 * when the deployment flag is on (Phase 15.4).
 */
class AssignmentDueSoon extends Notification implements ShouldBroadcast, ShouldQueue
{
    use ChannelsWithGatedMail, Queueable;

    /** Preference key — see NotificationPreference::TYPES. */
    public const TYPE = 'assignment_due_soon';

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
            'kind' => self::TYPE,
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
            ->subject('Requirement due soon: '.$this->assignment->name)
            ->greeting('Hello '.$notifiable->name.',')
            ->line('Your requirement "'.$this->assignment->name.'" is coming due.');

        if ($this->nextDueDate !== null) {
            $window = $this->daysUntilDue !== null
                ? ' (in '.$this->daysUntilDue.' day'.($this->daysUntilDue === 1 ? '' : 's').')'
                : '';
            $mail->line('Due date: '.$this->nextDueDate.$window.'.');
        }

        return $mail
            ->action('View your requirements', route('users.show', $notifiable))
            ->line('Log a completion before the due date to stay current.');
    }
}
