<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\CompletionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Completion extends Model
{
    /** @use HasFactory<CompletionFactory> */
    use BelongsToOrganization, HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'org_id',
        'user_id',
        'rqmt_element_id',
        'module_type',
        'module_id',
        'completion_date',
        'certification_date',
        'expire_date',
        'cert_ident',
        'notes',
    ];

    protected $casts = [
        'completion_date' => 'date',
        'certification_date' => 'date',
        'expire_date' => 'date',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function rqmtElement(): BelongsTo
    {
        return $this->belongsTo(RqmtElement::class, 'rqmt_element_id');
    }

    /**
     * The underlying module record satisfied by this completion.
     */
    public function module(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'module_type', 'module_id');
    }
}
