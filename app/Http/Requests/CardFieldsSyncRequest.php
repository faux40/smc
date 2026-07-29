<?php

namespace App\Http\Requests;

use App\Models\CardField;
use App\Support\Cards\CardMergeKeys;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * The whole set of a training's custom card fields, in display order.
 * `present` rather than `required` on the array so clearing every field is
 * expressible.
 *
 * Custom rules via withValidator(): a `short` value is one line capped at
 * CardField::SHORT_MAX, a `rich` one may be multiline up to RICH_MAX — the
 * limit depends on the sibling `type`, which a flat rule can't see.
 */
class CardFieldsSyncRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Policy authz happens in the controller.
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // Not the plan's "4 fields" — that's the editor's starting point,
            // not a limit. This ceiling only exists so a payload can't be
            // unbounded; no card prints 50 custom values.
            'fields' => ['present', 'array', 'max:50'],
            // An id must already belong to THIS training, or a sync could
            // adopt and rewrite another training's field.
            'fields.*.id' => [
                'nullable', 'string', 'uuid',
                Rule::exists('card_fields', 'id')
                    ->where('training_id', $this->route('training')->id),
            ],
            'fields.*.key' => [
                'required', 'string', 'max:64',
                // Same grammar as merge_fields: what goes inside ${…} with no
                // quoting surprises in the template's XML.
                'regex:/^[a-z][a-z0-9_]*$/',
                'distinct',
                function (string $attribute, mixed $value, callable $fail): void {
                    if (is_string($value) && CardMergeKeys::isReserved($value)) {
                        $fail("The key “{$value}” is already a built-in card field.");
                    }
                },
            ],
            'fields.*.label' => ['required', 'string', 'max:255'],
            'fields.*.type' => ['required', Rule::in(CardField::TYPES)],
            'fields.*.default_value' => ['nullable', 'string'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            foreach ((array) $this->input('fields', []) as $i => $row) {
                $value = is_array($row) ? ($row['default_value'] ?? null) : null;

                if (! is_string($value) || $value === '') {
                    continue;
                }

                $rich = (is_array($row) ? ($row['type'] ?? null) : null) === 'rich';
                $max = $rich ? CardField::RICH_MAX : CardField::SHORT_MAX;

                if (mb_strlen($value) > $max) {
                    $validator->errors()->add(
                        "fields.{$i}.default_value",
                        "The default may not be longer than {$max} characters.",
                    );
                }

                // A short field is "100 characters, no formatting" — line
                // breaks are formatting, and they'd overflow a card's
                // single-line frame.
                if (! $rich && preg_match('/\R/u', $value) === 1) {
                    $validator->errors()->add(
                        "fields.{$i}.default_value",
                        'The default must be a single line. Use a formatted field for multiple lines.',
                    );
                }
            }
        });
    }
}
