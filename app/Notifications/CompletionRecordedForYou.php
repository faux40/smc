<?php

namespace App\Notifications;

use App\Models\Completion;
use App\Notifications\Concerns\ChannelsWithGatedMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to a User when an admin / manager records a completion that
 * credits them. Acts as a paper-trail receipt — "your X was logged".
 * Delivers to the in-app inbox (`database`) + realtime bell
 * (`broadcast`); the `mail` channel is added by ChannelsWithGatedMail
 * when the deployment flag is on (Phase 15.4).
 */
class CompletionRecordedForYou extends Notification implements ShouldBroadcast, ShouldQueue
{
    use ChannelsWithGatedMail, Queueable;

    public function __construct(public readonly Completion $completion)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'completion_recorded',
            'completion_id' => $this->completion->id,
            'module_type' => $this->completion->module_type,
            'module_id' => $this->completion->module_id,
            'completion_date' => optional($this->completion->completion_date)->toDateString(),
            'expire_date' => optional($this->completion->expire_date)->toDateString(),
            // Element ids so the inbox can link "credits Requirements X, Y".
            'rqmt_element_ids' => $this->completion->rqmtElements->pluck('id')->all(),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $moduleName = $this->completion->module?->name ?? 'a requirement module';

        $mail = (new MailMessage)
            ->subject('Completion recorded: '.$moduleName)
            ->greeting('Hello '.$notifiable->name.',')
            ->line('A completion has been recorded on your behalf for '.$moduleName.'.');

        if ($this->completion->completion_date) {
            $mail->line('Completion date: '.$this->completion->completion_date->toDateString());
        }

        if ($this->completion->expire_date) {
            $mail->line('Expires: '.$this->completion->expire_date->toDateString());
        }

        return $mail
            ->action('View your record', route('users.show', $notifiable))
            ->line('No action is needed — this is a confirmation for your records.');
    }
}
