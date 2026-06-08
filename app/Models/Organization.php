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

    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'org_id');
    }
}
