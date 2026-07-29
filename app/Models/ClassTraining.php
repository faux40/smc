<?php

namespace App\Models;

use Database\Factories\ClassTrainingFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A training attached to a class, with a snapshot of the training's fields at
 * attach time (so later edits to the Training don't rewrite history) + the
 * allocated hours and (Phase B) the computed expiry. Org scope flows through
 * the parent class.
 */
class ClassTraining extends Model
{
    /** @use HasFactory<ClassTrainingFactory> */
    use HasFactory, HasUuids;

    protected $table = 'class_training';

    protected $fillable = [
        'class_id',
        'training_id',
        'training_name',
        'initial_only',
        'repeating',
        'as_needed',
        'repeat_days',
        'std_freq_name',
        'hours',
        'expire_date',
        'cert_title',
        'cert_text',
        'cert_code',
    ];

    protected $casts = [
        'initial_only' => 'boolean',
        'repeating' => 'boolean',
        'as_needed' => 'boolean',
        'repeat_days' => 'integer',
        'hours' => 'decimal:2',
        'expire_date' => 'date',
    ];

    /** @return BelongsTo<TrainingClass, $this> */
    public function trainingClass(): BelongsTo
    {
        return $this->belongsTo(TrainingClass::class, 'class_id');
    }

    /** @return BelongsTo<Training, $this> */
    public function training(): BelongsTo
    {
        return $this->belongsTo(Training::class, 'training_id');
    }

    /**
     * This topic's answers for its training's custom card fields (C3).
     * Definitions live on the Training; only the answers are per-class.
     *
     * @return HasMany<ClassTrainingCardValue, $this>
     */
    public function cardValues(): HasMany
    {
        return $this->hasMany(ClassTrainingCardValue::class, 'class_training_id');
    }
}
