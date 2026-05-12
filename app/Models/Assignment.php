<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\AssignmentFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Assignment extends Model
{
    /** @use HasFactory<AssignmentFactory> */
    use BelongsToOrganization, HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'org_id',
        'user_id',
        'requirement_id',
        'initial_only',
        'repeating',
        'std_freq_id',
        'as_needed',
        'name',
        'description',
        'start_date',
        'end_date',
    ];

    protected $casts = [
        'initial_only' => 'boolean',
        'repeating' => 'boolean',
        'as_needed' => 'boolean',
        'start_date' => 'date',
        'end_date' => 'date',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function requirement(): BelongsTo
    {
        return $this->belongsTo(Requirement::class, 'requirement_id');
    }

    public function stdFrequency(): BelongsTo
    {
        return $this->belongsTo(StdFrequency::class, 'std_freq_id');
    }
}
