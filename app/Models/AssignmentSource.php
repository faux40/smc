<?php

namespace App\Models;

use Database\Factories\AssignmentSourceFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class AssignmentSource extends Model
{
    /** @use HasFactory<AssignmentSourceFactory> */
    use HasFactory, HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'training_assignment_id',
        'sourceable_type',
        'sourceable_id',
        'added_at',
        'removed_at',
    ];

    protected $casts = [
        'added_at' => 'datetime',
        'removed_at' => 'datetime',
    ];

    public function trainingAssignment(): BelongsTo
    {
        return $this->belongsTo(TrainingAssignment::class, 'training_assignment_id');
    }

    /** The model that caused this assignment (Requirement, or null = direct). */
    public function sourceable(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'sourceable_type', 'sourceable_id');
    }

    public function isDirect(): bool
    {
        return $this->sourceable_type === null;
    }

    public function isActive(): bool
    {
        return $this->removed_at === null;
    }
}
