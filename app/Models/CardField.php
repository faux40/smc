<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A custom `${key}` a card design can merge beyond the built-in catalogue
 * ({@see \App\Support\Cards\CardMergeKeys}) — trainer id, endorsement, and
 * whatever else a purchased card prints.
 *
 * Defined on the TRAINING and inherited by every class that teaches it; the
 * per-class answers live in {@see ClassTrainingCardValue}, so the definition
 * is stated once and each class fills it in.
 *
 * Unlike CardStock / CardTemplate there is no system scope: a field only
 * means anything alongside the training whose card prints it.
 */
class CardField extends Model
{
    use BelongsToOrganization, HasFactory, HasUuids;

    /** One-line plain text, or a markdown subset (C5 renders the formatting). */
    public const TYPES = ['short', 'rich'];

    /** Chars accepted in a `short` value — the plan's "100 characters, no formatting". */
    public const SHORT_MAX = 100;

    /** Chars accepted in a `rich` value, markdown included. */
    public const RICH_MAX = 2000;

    protected $fillable = [
        'org_id', 'training_id', 'key', 'label', 'type', 'default_value', 'seq',
    ];

    protected function casts(): array
    {
        return [
            'seq' => 'integer',
        ];
    }

    /** @return BelongsTo<Training, $this> */
    public function training(): BelongsTo
    {
        return $this->belongsTo(Training::class);
    }

    /** @return HasMany<ClassTrainingCardValue, $this> */
    public function values(): HasMany
    {
        return $this->hasMany(ClassTrainingCardValue::class);
    }

    /** The token an author types into the template. */
    public function placeholder(): string
    {
        return '${'.$this->key.'}';
    }

    /** Longest value this field accepts, by type. */
    public function maxLength(): int
    {
        return $this->type === 'rich' ? self::RICH_MAX : self::SHORT_MAX;
    }
}
