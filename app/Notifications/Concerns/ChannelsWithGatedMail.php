<?php

namespace App\Notifications\Concerns;

use App\Models\NotificationPreference;
use Illuminate\Notifications\Messages\BroadcastMessage;

/**
 * Shared channel wiring for SMC's per-user notifications.
 *
 * Channel selection runs through two gates:
 *   1. Per-user preference (Phase 15.5) — `NotificationPreference`
 *      toggles, keyed on the consuming class's `TYPE` constant. A
 *      missing row reads as enabled, so the default is all-on.
 *   2. Deployment-level mail flag (Phase 15.4) —
 *      `config('notifications.mail_enabled')` is the outermost gate on
 *      the mail channel; a user can't opt *into* mail when the
 *      deployment has it off.
 *
 * The two logical channels: `inapp` covers Laravel's `database` +
 * `broadcast` channels as a unit (the inbox row and the realtime bell
 * move together); `mail` is email. No-login users have `email = null`,
 * so `routeNotificationFor('mail')` returns null and they never get
 * mail regardless of preference.
 *
 * Consumers must define `toArray()` (wrapped by `toBroadcast()` here)
 * and a `TYPE` class constant.
 */
trait ChannelsWithGatedMail
{
    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        $channels = [];

        if (NotificationPreference::allows($notifiable, static::TYPE, 'inapp')) {
            $channels[] = 'database';
            $channels[] = 'broadcast';
        }

        if (config('notifications.mail_enabled')
            && $notifiable->routeNotificationFor('mail')
            && NotificationPreference::allows($notifiable, static::TYPE, 'mail')) {
            $channels[] = 'mail';
        }

        return $channels;
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toArray($notifiable));
    }
}
