<?php

namespace App\Support\Cards;

use App\Models\CardField;
use App\Models\ClassTraining;
use App\Models\Completion;
use App\Models\TrainingClass;
use App\Support\CertificateData;
use App\Support\ExpiryCalculator;
use Illuminate\Support\Carbon;

/**
 * The values behind a card's `${keys}` (custom-certs C4) — one row per person
 * who earned a class topic's credit.
 *
 * Reads the frozen `class_training` snapshot rather than the live Training for
 * the same reason {@see CertificateData} does: a reprint months
 * later must say what the card said the first time.
 *
 * Every value is a string, never null — a null reaching the merge prints
 * "null" or leaves `${key}` visible on purchased stock, and card stock is not
 * something you get to re-run cheaply.
 */
class CardMergeData
{
    /** Dates are short: a wallet card has no room for "June 1, 2026". */
    private const DATE_FORMAT = 'm/d/Y';

    /**
     * Every card for one class topic, in the certificate print order (last,
     * first, middle) so a stack of cards collates with a stack of certs.
     *
     * @return list<array<string, string>>
     */
    public static function forTopic(ClassTraining $topic): array
    {
        $topic->loadMissing('trainingClass.organization', 'training.cardFields', 'cardValues');

        $class = $topic->trainingClass;

        // cert_id is what "issued" means, exactly as for certificates — a
        // completion without one was never given a certificate to match.
        $completions = Completion::query()
            ->withoutGlobalScope('organization')
            ->where('class_training_id', $topic->id)
            ->whereNotNull('cert_id')
            ->with('user:id,f_name,m_name,l_name,prefix_name,suffix_name,employee_number,email')
            ->orderBy('cert_id')
            ->get()
            ->sortBy(fn (Completion $c) => $c->user?->personName()->sortKey() ?? ['', '', ''])
            ->values();

        $custom = self::customValues($topic);
        $shared = self::classValues($topic, $class);

        return $completions
            ->map(fn (Completion $c) => [
                // Custom first, built-ins last: C3 rejects a reserved key, so
                // a collision can only arise from a key added to the catalogue
                // later — and then the built-in must win rather than the row
                // quietly taking on a second meaning.
                ...$custom,
                ...$shared,
                ...self::personValues($c),
                ...self::creditValues($c, $topic),
            ])
            ->all();
    }

    /**
     * Values shared by every card in the run: the class, the topic snapshot,
     * the org, and the date the cards were made.
     *
     * @return array<string, string>
     */
    private static function classValues(ClassTraining $topic, ?TrainingClass $class): array
    {
        return [
            'class_name' => self::str($class?->name),
            'class_date' => self::date($class?->scheduled_date),
            'class_location' => self::str($class?->location),
            'class_address' => self::str($class?->address),
            'instructor' => self::str($class?->instructor),
            'start_time' => self::time($class?->start_time),
            'end_time' => self::time($class?->end_time),
            'training_name' => self::str($topic->training_name),
            // Snapshot cert_title, else the snapshot name — mirrors
            // CertificateData so card and certificate never disagree.
            'cert_title' => self::str($topic->cert_title ?: $topic->training_name),
            'cert_code' => self::str($topic->cert_code),
            'org_name' => self::str($class?->organization?->name),
            'today' => Carbon::now(config('app.display_timezone'))->format(self::DATE_FORMAT),
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function personValues(Completion $completion): array
    {
        $user = $completion->user;

        return [
            'first_name' => self::str($user?->f_name),
            'middle_name' => self::str($user?->m_name),
            'last_name' => self::str($user?->l_name),
            'full_name' => $user === null ? '' : $user->personName()->full(),
            'employee_number' => self::str($user?->employee_number),
            'email' => self::str($user?->email),
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function creditValues(Completion $completion, ClassTraining $topic): array
    {
        // Prefer the stamped expiry; fall back to completion + the snapshot's
        // interval for records that never had one (certificates do the same).
        $expires = $completion->expire_date
            ?? ($completion->completion_date === null ? null : ExpiryCalculator::fromRepeatDays(
                Carbon::parse($completion->completion_date)->toDateString(),
                (bool) $topic->repeating,
                $topic->repeat_days,
            ));

        return [
            'cert_id' => self::str($completion->cert_id),
            'completion_date' => self::date($completion->completion_date),
            'expire_date' => self::date($expires),
            'hours' => self::hours($completion->hours ?? $topic->hours),
        ];
    }

    /**
     * The training's custom fields for this class: the answer recorded here,
     * else the field's default, else blank.
     *
     * @return array<string, string>
     */
    private static function customValues(ClassTraining $topic): array
    {
        $answers = $topic->cardValues->keyBy('card_field_id');

        return $topic->training?->cardFields
            ->mapWithKeys(function (CardField $field) use ($answers): array {
                $value = $answers->get($field->id)?->value ?? $field->default_value ?? '';

                // A rich value travels as its own markdown, marked so the
                // post-merge pass can turn it into real runs. It used to be
                // flattened to plain text here, which threw the formatting
                // away before anything downstream could act on it.
                return [$field->key => $field->type === 'rich' ? RichTextMarkup::mark($value) : $value];
            })
            ->all() ?? [];
    }

    private static function str(mixed $value): string
    {
        return $value === null ? '' : (string) $value;
    }

    private static function date(mixed $value): string
    {
        return $value === null || $value === ''
            ? ''
            : Carbon::parse($value)->format(self::DATE_FORMAT);
    }

    /** "8:00 AM" from an HH:MM column, tolerating a stored seconds component. */
    private static function time(?string $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        return Carbon::createFromFormat('H:i', substr($value, 0, 5))->format('g:i A');
    }

    /** 4.00 → "4", 4.50 → "4.5": "4.00 hours" on a wallet card reads like a bug. */
    private static function hours(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        return rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.');
    }
}
