<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\TrainingClassFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A scheduled training class (table `classes` — `Class` is a reserved word).
 * Phase A is scheduling + roster; Phase B closes it out and generates
 * completions for passed enrollees.
 */
class TrainingClass extends Model
{
    /** @use HasFactory<TrainingClassFactory> */
    use BelongsToOrganization, HasFactory, HasUuids, SoftDeletes;

    protected $table = 'classes';

    protected $fillable = [
        'org_id',
        'name',
        'scheduled_date',
        'location',
        'instructor',
        'total_hours',
        'notes',
        'status',
        'completion_date',
        'completed_at',
    ];

    protected $casts = [
        'scheduled_date' => 'date',
        'completion_date' => 'date',
        'completed_at' => 'datetime',
        'total_hours' => 'decimal:2',
    ];

    /** @return HasMany<ClassTraining, $this> */
    public function classTrainings(): HasMany
    {
        return $this->hasMany(ClassTraining::class, 'class_id');
    }

    /** @return HasMany<ClassEnrollment, $this> */
    public function enrollments(): HasMany
    {
        return $this->hasMany(ClassEnrollment::class, 'class_id');
    }
}
