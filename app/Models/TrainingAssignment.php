<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
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

    protected $fillable = [
        'org_id',
        'user_id',
        'training_id',
        'name',
        'expires_at',
        'last_completed_at',
        'as_needed_only',
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

    public function sources(): HasMany
    {
        return $this->hasMany(AssignmentSource::class, 'training_assignment_id');
    }

    public function activeSources(): HasMany
    {
        return $this->sources()->whereNull('removed_at');
    }
}
