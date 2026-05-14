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
 * Sent to a User when an admin / manager creates an assignment for
 * them. Delivers to the in-app inbox (`database`) + realtime bell
 * (`broadcast`); the `mail` channel is added by ChannelsWithGatedMail
 * when the deployment flag is on (Phase 15.4).
 */
class AssignmentCreatedForYou extends Notification implements ShouldBroadcast, ShouldQueue
{
    use ChannelsWithGatedMail, Queueable;

    public function __construct(public readonly Assignment $assignment)
    {
    }

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
            'kind' => 'assignment_created',
            'assignment_id' => $this->assignment->id,
            'requirement_id' => $this->assignment->requirement_id,
            'name' => $this->assignment->name,
            'description' => $this->assignment->description,
            'start_date' => optional($this->assignment->start_date)->toDateString(),
            'end_date' => optional($this->assignment->end_date)->toDateString(),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject('New requirement assigned: '.$this->assignment->name)
            ->greeting('Hello '.$notifiable->name.',')
            ->line('A new requirement has been assigned to you: '.$this->assignment->name.'.');

        if ($this->assignment->description) {
            $mail->line($this->assignment->description);
        }

        if ($this->assignment->start_date) {
            $mail->line('Start date: '.$this->assignment->start_date->toDateString());
        }

        return $mail
            ->action('View your requirements', route('users.show', $notifiable))
            ->line('Log your completion once the requirement is met.');
    }
}
