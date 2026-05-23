<?php

namespace App\Models;

use Database\Factories\ClassTrainingFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
        'lifespan_months',
        'cert_code',
    ];

    protected $casts = [
        'initial_only' => 'boolean',
        'repeating' => 'boolean',
        'as_needed' => 'boolean',
        'repeat_days' => 'integer',
        'hours' => 'decimal:2',
        'expire_date' => 'date',
        'lifespan_months' => 'integer',
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
}
