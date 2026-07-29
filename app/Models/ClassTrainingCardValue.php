<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One class topic's answer for one {@see CardField} — "what goes in
 * ${trainer_id} on the cards for First Aid in this class".
 *
 * Keyed on `class_training` rather than the class so a class teaching two
 * topics keeps their answers apart. An absent row (or a null value) means
 * "use the field's default"; the ladder value → training default → blank is
 * applied by the merge in C4.
 *
 * No SoftDeletes on purpose: clearing an answer is a hard delete, and the
 * (topic, field) unique index would collide with a tombstone on re-entry.
 */
class ClassTrainingCardValue extends Model
{
    use BelongsToOrganization, HasFactory, HasUuids;

    protected $fillable = [
        'org_id', 'class_training_id', 'card_field_id', 'value',
    ];

    /** @return BelongsTo<ClassTraining, $this> */
    public function classTraining(): BelongsTo
    {
        return $this->belongsTo(ClassTraining::class);
    }

    /** @return BelongsTo<CardField, $this> */
    public function field(): BelongsTo
    {
        return $this->belongsTo(CardField::class, 'card_field_id');
    }
}
