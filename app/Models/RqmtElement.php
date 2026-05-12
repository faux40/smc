<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\RqmtElementFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class RqmtElement extends Model
{
    /** @use HasFactory<RqmtElementFactory> */
    use BelongsToOrganization, HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'org_id',
        'requirement_id',
        'module_type',
        'module_id',
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

    public function requirement(): BelongsTo
    {
        return $this->belongsTo(Requirement::class, 'requirement_id');
    }

    /**
     * The underlying module record (Training today; future Inspection/Cert/etc.).
     * `module_id` is `string` because future modules may use non-UUID PKs.
     */
    public function module(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'module_type', 'module_id');
    }

    public function stdFrequency(): BelongsTo
    {
        return $this->belongsTo(StdFrequency::class, 'std_freq_id');
    }

    public function completions(): HasMany
    {
        return $this->hasMany(Completion::class, 'rqmt_element_id');
    }
}
