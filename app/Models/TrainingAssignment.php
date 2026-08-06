<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Services\TrainingStatusService;
use Database\Factories\TrainingAssignmentFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TrainingAssignment extends Model
{
    /** @use HasFactory<TrainingAssignmentFactory> */
    use BelongsToOrganization, HasFactory, HasUuids;

    /**
     * The denormalized `status` column is canonical (ComplianceQuery + the
     * dashboard read it directly). RecalculateTrainingStatus maintains it in
     * realtime and the daily watchdog reconciles date-crossings, but rows
     * created outside that path (seeders, direct writes) would otherwise
     * persist a null bucket and vanish from the aggregates. Materialize it at
     * creation from the flattened columns + the org's amber window so a TA is
     * never stored without a status; an explicit status is left untouched.
     */
    protected static function booted(): void
    {
        static::creating(function (self $ta): void {
            if ($ta->status !== null || $ta->org_id === null) {
                return;
            }

            $window = Organization::find($ta->org_id)?->expiringSoonDays()
                ?? Organization::DEFAULT_EXPIRING_SOON_DAYS;

            $ta->status = app(TrainingStatusService::class)->statusFor($ta, $window);
        });
    }

    protected $fillable = [
        'org_id',
        'user_id',
        'training_id',
        'name',
        'expires_at',
        'last_completed_at',
        'as_needed_only',
        'status',
        // Set by the recalc when the governing credit came from a covering
        // (higher) training; null when this training satisfied itself.
        'satisfied_via_training_id',
    ];

    protected $casts = [
        'expires_at' => 'date',
        'last_completed_at' => 'date',
        'as_needed_only' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function training(): BelongsTo
    {
        return $this->belongsTo(Training::class, 'training_id');
    }

    /**
     * The covering (higher) training whose credential is currently satisfying
     * this assignment; null when it satisfies itself. withTrashed: a deleted
     * training's credentials still count, so its name must still resolve.
     */
    public function satisfiedVia(): BelongsTo
    {
        return $this->belongsTo(Training::class, 'satisfied_via_training_id')->withTrashed();
    }

    public function sources(): HasMany
    {
        return $this->hasMany(AssignmentSource::class, 'training_assignment_id');
    }

    public function activeSources(): HasMany
    {
        return $this->sources()->whereNull('removed_at');
    }
}
