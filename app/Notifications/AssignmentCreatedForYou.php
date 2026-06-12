<?php

namespace App\Notifications;

use App\Notifications\Concerns\ChannelsWithGatedMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to a User when an admin / manager assigns them a training —
 * directly (name = the training) or via a requirement set (name = the
 * requirement; one notification per set, not one per exploded
 * training). Fires only when the assignment is genuinely new for the
 * user, not when an existing training gains an extra source.
 *
 * Carries plain values, never models — training assignments
 * hard-delete, and a serialized model reference would fail in the queue
 * worker if the TA vanished before the job ran (J6).
 *
 * Delivers to the in-app inbox (`database`) + realtime bell
 * (`broadcast`); the `mail` channel is added by ChannelsWithGatedMail
 * when the deployment flag is on (Phase 15.4).
 */
class AssignmentCreatedForYou extends Notification implements ShouldBroadcast, ShouldQueue
{
    use ChannelsWithGatedMail, Queueable;

    /** Preference key — see NotificationPreference::TYPES. */
    public const TYPE = 'assignment_created';

    public function __construct(
        public readonly string $name,
        public readonly ?string $trainingId = null,
        public readonly ?string $requirementId = null,
    ) {}

    /**
     * Payload persisted into notifications.data + delivered to the
     * frontend on broadcast. The inbox UI renders directly from this
     * shape.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => self::TYPE,
            'name' => $this->name,
            'training_id' => $this->trainingId,
            'requirement_id' => $this->requirementId,
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('New assignment: '.$this->name)
            ->greeting('Hello '.$notifiable->name.',')
            ->line('A new training assignment has been added for you: '.$this->name.'.')
            ->action('View your trainings', route('users.show', $notifiable))
            ->line('Check your detail page for the due date and current status.');
    }
}
