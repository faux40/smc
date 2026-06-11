<?php

namespace App\Notifications;

use App\Notifications\Concerns\ChannelsWithGatedMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to a User when the daily watchdog (`assignments:scan-due-states`)
 * sees one of their training assignments cross *into* `due_soon`.
 * Edge-triggered: fires once on the transition, not every day the
 * training stays due.
 *
 * Carries plain values, never models — training assignments hard-delete,
 * and a serialized model reference would fail in the queue worker if the
 * TA vanished before the job ran (J6).
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
        public readonly string $trainingAssignmentId,
        public readonly string $trainingId,
        public readonly string $name,
        public readonly ?string $expiresAt,
        public readonly ?int $daysUntilDue,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => self::TYPE,
            'training_assignment_id' => $this->trainingAssignmentId,
            'training_id' => $this->trainingId,
            'name' => $this->name,
            'next_due_date' => $this->expiresAt,
            'days_until_due' => $this->daysUntilDue,
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject('Training due soon: '.$this->name)
            ->greeting('Hello '.$notifiable->name.',')
            ->line('Your training "'.$this->name.'" is coming due.');

        if ($this->expiresAt !== null) {
            $window = $this->daysUntilDue !== null
                ? ' (in '.$this->daysUntilDue.' day'.($this->daysUntilDue === 1 ? '' : 's').')'
                : '';
            $mail->line('Due date: '.$this->expiresAt.$window.'.');
        }

        return $mail
            ->action('View your trainings', route('users.show', $notifiable))
            ->line('Log a completion before the due date to stay current.');
    }
}
