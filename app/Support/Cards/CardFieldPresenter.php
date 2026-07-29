<?php

namespace App\Support\Cards;

use App\Models\CardField;

/**
 * One shape for a card-field definition, wherever it's served: the Admin
 * editor on a training reads it from the card-fields endpoint, and the
 * Manager's entry form reads it embedded in the class-detail payload. Same
 * keys both times, so one frontend type and one component cover both.
 */
class CardFieldPresenter
{
    /**
     * The definition alone. A per-class answer is added by the caller that
     * has one (`value`), since a bare definition has no answer to report.
     *
     * @return array<string, mixed>
     */
    public static function definition(CardField $field): array
    {
        return [
            'id' => $field->id,
            'key' => $field->key,
            // What the author types into the slide.
            'placeholder' => $field->placeholder(),
            'label' => $field->label,
            'type' => $field->type,
            'default_value' => $field->default_value,
            'max_length' => $field->maxLength(),
            'seq' => $field->seq,
        ];
    }
}
