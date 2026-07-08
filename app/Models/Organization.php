<?php

namespace App\Models;

use Database\Factories\OrganizationFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Organization extends Model
{
    /** @use HasFactory<OrganizationFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    public const DEFAULT_DUE_SOON_DAYS = 60;

    public const DEFAULT_EXPIRING_SOON_DAYS = 30;

    protected $fillable = [
        'owner_user_id',
        'name',
        'timezone',
        'manager_digest_sent_at',
        'training_thresholds',
        'overdue_reminder_interval_days',
    ];

    protected $casts = [
        'manager_digest_sent_at' => 'datetime',
        'training_thresholds' => 'array',
    ];

    public function dueSoonDays(): int
    {
        return $this->training_thresholds['due_soon_days'] ?? self::DEFAULT_DUE_SOON_DAYS;
    }

    public function expiringSoonDays(): int
    {
        return $this->training_thresholds['expiring_soon_days'] ?? self::DEFAULT_EXPIRING_SOON_DAYS;
    }

    /**
     * The overdue re-notification interval in days, or null when disabled.
     * A stored 0 also reads as disabled (the settings UI treats blank/0 the
     * same) so callers only have to check for null / positive.
     */
    public function overdueReminderIntervalDays(): ?int
    {
        $days = $this->overdue_reminder_interval_days;

        return $days !== null && $days > 0 ? (int) $days : null;
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'org_id');
    }
}
