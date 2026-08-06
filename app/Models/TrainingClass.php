<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasAttachments;
use App\Models\Concerns\HasTags;
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
    use BelongsToOrganization, HasAttachments, HasFactory, HasTags, HasUuids, SoftDeletes;

    protected $table = 'classes';

    protected $fillable = [
        'org_id',
        'name',
        'scheduled_date',
        'start_time',
        'end_time',
        'location',
        'address',
        'instructor',
        'show_signature',
        'total_hours',
        'min_students',
        'max_students',
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
        'min_students' => 'integer',
        'max_students' => 'integer',
        'show_signature' => 'boolean',
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
