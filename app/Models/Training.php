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
        'cert_title',
        'cert_text_line_1',
        'cert_text_line_2',
        'cert_text_line_3',
        'cert_text_line_4',
        'lifespan_months',
        'cert_code',
        'show_signature_on_cert',
        'default_trainer',
        'default_training_location',
        'default_training_address',
    ];

    protected $casts = [
        'initial_only' => 'boolean',
        'repeating' => 'boolean',
        'as_needed' => 'boolean',
        'default_hours' => 'decimal:2',
        'lifespan_months' => 'integer',
        'show_signature_on_cert' => 'boolean',
    ];

    public function stdFrequency(): BelongsTo
    {
        return $this->belongsTo(StdFrequency::class, 'std_freq_id');
    }
}
