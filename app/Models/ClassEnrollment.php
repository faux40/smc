<?php

namespace App\Models;

use Database\Factories\ClassEnrollmentFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A user's enrollment in a class. Status is `enrolled` on the roster; at close
 * the instructor sets `passed` or `incomplete` (+ notes). Org scope flows
 * through the parent class.
 */
class ClassEnrollment extends Model
{
    /** @use HasFactory<ClassEnrollmentFactory> */
    use HasFactory, HasUuids;

    protected $table = 'class_enrollments';

    protected $fillable = [
        'class_id',
        'user_id',
        'status',
        'notes',
    ];

    /** @return BelongsTo<TrainingClass, $this> */
    public function trainingClass(): BelongsTo
    {
        return $this->belongsTo(TrainingClass::class, 'class_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
