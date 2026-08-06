<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\RqmtElementFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
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

    /**
     * The name this element displays: the override when one is set, else the
     * module's live name. `name` is a deliberate label only — attach-time
     * snapshots were retired after a training rename stranded three elements
     * on a name no training carried anymore.
     */
    public function effectiveName(): ?string
    {
        if ($this->name !== null && $this->name !== '') {
            return $this->name;
        }

        return $this->moduleLiveName();
    }

    /**
     * The module's current name, tolerant of soft-deleted trainings and of
     * running without an authenticated user (queued broadcasts) — a dead or
     * unscoped module must degrade the label, never blank it.
     */
    public function moduleLiveName(): ?string
    {
        if ($this->module_type === Training::class) {
            return Training::query()
                ->withoutGlobalScope('organization')
                ->withTrashed()
                ->whereKey($this->module_id)
                ->value('name');
        }

        return $this->module?->name;
    }

    /**
     * Completions that credit this element. v15 spec moved the link to the
     * `completion_elements` pivot — a completion may credit several elements.
     */
    public function completions(): BelongsToMany
    {
        return $this->belongsToMany(
            Completion::class,
            'completion_elements',
            'rqmt_element_id',
            'completion_id',
        );
    }
}
