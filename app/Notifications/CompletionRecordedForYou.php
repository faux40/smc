<?php

namespace App\Notifications;

use App\Models\Completion;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to a User when an admin / manager records a completion that
 * credits them. Acts as a paper-trail receipt — "your X was logged".
 * Phase 15.1: database + broadcast. Mail channel comes in 15.4.
 */
class CompletionRecordedForYou extends Notification implements ShouldBroadcast
{
    use Queueable;

    public function __construct(public readonly Completion $completion)
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

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toArray($notifiable));
    }
}
