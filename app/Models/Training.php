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
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Training extends Model
{
    /** @use HasFactory<TrainingFactory> */
    use BelongsToOrganization, HasAttachments, HasComments, HasFactory, HasTags, HasUuids, SoftDeletes;

    protected $fillable = [
        'org_id',
        'name',
        'nickname',
        'description',
        'initial_only',
        'repeating',
        'std_freq_id',
        'as_needed',
        'default_hours',
        'cert_title',
        'cert_text',
        'cert_code',
        'card_template_id',
        'card_stock_id',
        'default_trainer',
        'default_location',
        'default_address',
        // Hierarchy: the higher training whose credential satisfies this one
        // (Authorized points at Competent). Chains upward, transitively.
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

    /**
     * The higher trainings whose credentials satisfy this one — ANY of them
     * (OR-semantics). Edges form a DAG walked by TrainingLadder; diamonds are
     * legal, cycles are refused by TrainingRequest.
     *
     * @return BelongsToMany<Training, $this>
     */
    public function satisfiers(): BelongsToMany
    {
        return $this->belongsToMany(
            self::class,
            'training_satisfiers',
            'training_id',
            'satisfied_by_id',
        )->withTrashed();
    }

    /**
     * Inverse: the lower trainings this one's credential (directly) satisfies.
     *
     * @return BelongsToMany<Training, $this>
     */
    public function satisfies(): BelongsToMany
    {
        return $this->belongsToMany(
            self::class,
            'training_satisfiers',
            'satisfied_by_id',
            'training_id',
        )->withTrashed();
    }

    /**
     * Custom `${key}` definitions for this training's card design, in the
     * order the admin arranged them — which is the order they're entered on a
     * class and listed in the card builder.
     *
     * @return HasMany<CardField, $this>
     */
    public function cardFields(): HasMany
    {
        return $this->hasMany(CardField::class)->orderBy('seq')->orderBy('key');
    }
}
