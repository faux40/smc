<?php

namespace App\Notifications;

use App\Notifications\Concerns\ChannelsWithGatedMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * F10 — supervisor escalation for an overdue training. Sent to the overdue
 * employee's supervisor when an overdue *reminder* goes out (the Part A
 * recurring re-fire, or a manual "Remind"), never on the first edge into
 * overdue — escalation follows repeated / deliberate nudges, not the initial
 * alert. Gated on the supervisor actually having an email.
 *
 * Carries plain values, never models (training assignments hard-delete; a
 * serialized reference would fail in the queue worker if the TA vanished
 * first). Delivers to the in-app inbox + realtime bell; `mail` is added by
 * ChannelsWithGatedMail when the deployment flag is on.
 */
class AssignmentOverdueSupervisor extends Notification implements ShouldBroadcast, ShouldQueue
{
    use ChannelsWithGatedMail, Queueable;

    /** Preference key — see NotificationPreference::TYPES. */
    public const TYPE = 'assignment_overdue_supervisor';

    public function __construct(
        public readonly string $employeeId,
        public readonly string $employeeName,
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
            'employee_id' => $this->employeeId,
            'employee_name' => $this->employeeName,
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
            ->error()
            ->subject('Overdue training for '.$this->employeeName.': '.$this->name)
            ->greeting('Hello '.$notifiable->name.',')
            ->line($this->employeeName.'’s training "'.$this->name.'" is overdue.');

        if ($this->expiresAt !== null) {
            $overdueBy = $this->daysUntilDue !== null
                ? ' ('.abs($this->daysUntilDue).' day'.(abs($this->daysUntilDue) === 1 ? '' : 's').' overdue)'
                : '';
            $mail->line('Due date was: '.$this->expiresAt.$overdueBy.'.');
        }

        return $mail
            ->action('View their record', route('users.show', $this->employeeId))
            ->line('As their supervisor, please follow up so they can return to compliance.');
    }
}
