<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\CompletionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Completion extends Model
{
    /** @use HasFactory<CompletionFactory> */
    use BelongsToOrganization, HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'org_id',
        'user_id',
        'module_type',
        'module_id',
        'completion_date',
        'certification_date',
        'expire_date',
        'cert_ident',
        'cert_id',
        'class_training_id',
        'hours',
        'notes',
    ];

    protected $casts = [
        'completion_date' => 'date',
        'certification_date' => 'date',
        'expire_date' => 'date',
        'hours' => 'float',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Elements this completion satisfies. One completion can credit several
     * rqmt_elements (and therefore several Requirements) per v15 spec.
     * Schema permits zero rows; the application layer (FormRequest) enforces
     * min:1 at create/update so the "credit for unassigned" path stays open.
     */
    public function rqmtElements(): BelongsToMany
    {
        return $this->belongsToMany(
            RqmtElement::class,
            'completion_elements',
            'completion_id',
            'rqmt_element_id',
        );
    }

    /**
     * The underlying module record satisfied by this completion.
     */
    public function module(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'module_type', 'module_id');
    }
}
