<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\TrainingFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Training extends Model
{
    /** @use HasFactory<TrainingFactory> */
    use BelongsToOrganization, HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'org_id',
        'name',
        'description',
        'initial_only',
        'repeating',
        'std_freq_id',
        'as_needed',
    ];

    protected $casts = [
        'initial_only' => 'boolean',
        'repeating' => 'boolean',
        'as_needed' => 'boolean',
    ];

    public function stdFrequency(): BelongsTo
    {
        return $this->belongsTo(StdFrequency::class, 'std_freq_id');
    }
}
