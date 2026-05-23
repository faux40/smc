<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasAttachments;
use App\Models\Concerns\HasComments;
use App\Models\Concerns\HasTags;
use Database\Factories\TrainingFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Training extends Model
{
    /** @use HasFactory<TrainingFactory> */
    use BelongsToOrganization, HasAttachments, HasComments, HasFactory, HasTags, HasUuids, SoftDeletes;

    protected $fillable = [
        'org_id',
        'name',
        'description',
        'initial_only',
        'repeating',
        'std_freq_id',
        'as_needed',
        'default_hours',
    ];

    protected $casts = [
        'initial_only' => 'boolean',
        'repeating' => 'boolean',
        'as_needed' => 'boolean',
        'default_hours' => 'decimal:2',
    ];

    public function stdFrequency(): BelongsTo
    {
        return $this->belongsTo(StdFrequency::class, 'std_freq_id');
    }
}
