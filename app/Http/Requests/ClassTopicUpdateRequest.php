<?php

namespace App\Http\Requests;

use App\Models\CardField;
use App\Models\ClassTraining;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Collection;
use Illuminate\Validation\Validator;

/**
 * Editing one topic of a class: its hours, its per-class certificate
 * overrides, and its answers for the training's custom card fields.
 *
 * Every rule is `sometimes` — the endpoint's contract is "only touch what was
 * sent", so a cert-only edit doesn't blank hours and a card-values edit
 * doesn't blank either.
 *
 * card_values arrives as {card_field_id: value}. The acceptable length and
 * whether line breaks are allowed depend on the field's own `type`, so the
 * checks live in withValidator() where the definitions can be loaded.
 */
class ClassTopicUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Policy authz + the completed-class guard live in the controller.
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'hours' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            // Per-class cert overrides: seeded from the training snapshot at
            // attach time, then editable for this class only. cert_text is
            // Markdown (rendered on the certificate).
            'cert_title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'cert_text' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'cert_code' => ['sometimes', 'nullable', 'string', 'max:32'],
            // Custom card fields (C3): a partial map is fine — an absent field
            // means "no change", an empty/null value means "clear it".
            'card_values' => ['sometimes', 'array'],
            'card_values.*' => ['nullable', 'string'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $values = $this->input('card_values');

            if (! is_array($values) || $values === []) {
                return;
            }

            $fields = $this->fieldsForTopic();

            foreach ($values as $fieldId => $value) {
                $field = $fields->get((string) $fieldId);

                if ($field === null) {
                    // Definitions are inherited from this topic's training;
                    // anything else is a question this class was never asked.
                    $validator->errors()->add(
                        "card_values.{$fieldId}",
                        'That card field does not belong to this topic.',
                    );

                    continue;
                }

                if (! is_string($value) || $value === '') {
                    continue;
                }

                $max = $field->maxLength();

                if (mb_strlen($value) > $max) {
                    $validator->errors()->add(
                        "card_values.{$fieldId}",
                        "“{$field->label}” may not be longer than {$max} characters.",
                    );
                }

                if ($field->type !== 'rich' && preg_match('/\R/u', $value) === 1) {
                    $validator->errors()->add(
                        "card_values.{$fieldId}",
                        "“{$field->label}” must be a single line.",
                    );
                }
            }
        });
    }

    /**
     * The card fields this topic's training defines, keyed by id.
     *
     * @return Collection<string, CardField>
     */
    private function fieldsForTopic(): Collection
    {
        $topic = $this->route('classTraining');

        if (! $topic instanceof ClassTraining) {
            return new Collection;
        }

        return CardField::query()
            ->where('training_id', $topic->training_id)
            ->get()
            ->keyBy('id');
    }
}
