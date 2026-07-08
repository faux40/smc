<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-user notification toggle (Phase 15.5). One row per
 * (user, type, channel); a *missing* row reads as enabled — the table
 * only ever records a deviation from the all-on default.
 *
 * Consulted by `ChannelsWithGatedMail::via()` beneath the
 * deployment-level `notifications.mail_enabled` flag from 15.4.
 */
class NotificationPreference extends Model
{
    use HasUuids;

    /**
     * Canonical notification types — must match each notification
     * class's `TYPE` constant (and the `kind` in its `toArray()`).
     */
    public const TYPES = [
        'assignment_created',
        'completion_recorded',
        'assignment_due_soon',
        'assignment_overdue',
        'assignment_overdue_supervisor',
        'manager_digest',
    ];

    /**
     * Logical channels exposed to the user. `inapp` covers the
     * database + broadcast Laravel channels as a unit; `mail` is email.
     */
    public const CHANNELS = ['inapp', 'mail'];

    protected $fillable = [
        'user_id',
        'type',
        'channel',
        'enabled',
    ];

    protected $casts = [
        'enabled' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Whether the given notifiable permits `$type` on `$channel`.
     * Absent row → true (the default is on).
     */
    public static function allows(object $notifiable, string $type, string $channel): bool
    {
        $row = static::query()
            ->where('user_id', $notifiable->getKey())
            ->where('type', $type)
            ->where('channel', $channel)
            ->first();

        return $row === null ? true : $row->enabled;
    }
}
