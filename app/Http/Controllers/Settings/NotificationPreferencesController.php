<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\NotificationPreferenceUpdateRequest;
use App\Models\NotificationPreference;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Phase 15.5 — per-user notification preferences. The page renders the
 * full type × channel matrix; `update()` upserts every cell. Missing
 * rows read as enabled, so the table only ever holds the user's
 * explicit choices.
 */
class NotificationPreferencesController extends Controller
{
    public function edit(Request $request): Response
    {
        $user = $request->user();

        $rows = NotificationPreference::query()
            ->where('user_id', $user->id)
            ->get()
            ->keyBy(fn (NotificationPreference $p) => $p->type.'.'.$p->channel);

        // Build the dense matrix the page binds to — every known cell,
        // defaulting absent rows to enabled.
        $preferences = [];
        foreach (NotificationPreference::TYPES as $type) {
            foreach (NotificationPreference::CHANNELS as $channel) {
                $preferences[$type][$channel] = $rows->get("{$type}.{$channel}")?->enabled ?? true;
            }
        }

        return Inertia::render('settings/Notifications', [
            'preferences' => $preferences,
            // The deployment-level mail flag (Phase 15.4). When off, the
            // page disables the email column — a user can't opt into a
            // channel the deployment has switched off.
            'mailEnabled' => (bool) config('notifications.mail_enabled'),
        ]);
    }

    public function update(NotificationPreferenceUpdateRequest $request): RedirectResponse
    {
        $user = $request->user();

        foreach ($request->validated()['preferences'] as $type => $channels) {
            foreach ($channels as $channel => $enabled) {
                NotificationPreference::updateOrCreate(
                    ['user_id' => $user->id, 'type' => $type, 'channel' => $channel],
                    ['enabled' => $enabled],
                );
            }
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Notification preferences updated.')]);

        return to_route('notification-preferences.edit');
    }
}
