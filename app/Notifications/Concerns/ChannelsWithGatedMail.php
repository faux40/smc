<?php

namespace App\Notifications\Concerns;

use Illuminate\Notifications\Messages\BroadcastMessage;

/**
 * Shared channel wiring for SMC's per-user notifications.
 *
 * Every notification delivers to the in-app inbox (`database`) and the
 * realtime bell (`broadcast`) unconditionally. The `mail` channel is
 * added only when the deployment-level flag is on AND the recipient
 * actually has a mail route — no-login users have `email = null`, so
 * `routeNotificationFor('mail')` returns null and they are skipped
 * without a special case.
 *
 * Phase 15.5 will extend `via()` to also consult per-user, per-type
 * `notification_preferences`; `config('notifications.mail_enabled')`
 * stays the outermost gate.
 *
 * Consumers must define `toArray()` — `toBroadcast()` here wraps it.
 */
trait ChannelsWithGatedMail
{
    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        $channels = ['database', 'broadcast'];

        if (config('notifications.mail_enabled') && $notifiable->routeNotificationFor('mail')) {
            $channels[] = 'mail';
        }

        return $channels;
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toArray($notifiable));
    }
}
