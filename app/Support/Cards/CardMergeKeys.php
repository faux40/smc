<?php

namespace App\Support\Cards;

use App\Models\CardField;

/**
 * The built-in `${key}` vocabulary a card design draws from — the person, the
 * class, the credit and the org. Custom fields ({@see CardField})
 * extend it per training; this list is what they may not shadow, so
 * `${first_name}` always means the student's first name.
 *
 * C3 uses it for that one job. C4 populates the values (CardMergeData) and
 * feeds the card builder's copy-paste list from the same constant, so the
 * catalogue can't drift from what actually merges.
 *
 * Adding a key here later is safe for templates but not automatically safe
 * for orgs: a new built-in that matches an existing custom key would change
 * that org's card, since built-ins win. Check before extending.
 */
class CardMergeKeys
{
    /**
     * Grouped for the builder's field list; the flat list below is what
     * validation compares against.
     *
     * @var array<string, list<string>>
     */
    public const GROUPS = [
        'Person' => [
            'first_name',
            'middle_name',
            'last_name',
            // The person's natural full name, as printed on the SMC cert.
            'full_name',
            'employee_number',
            'email',
        ],
        'Class' => [
            'class_name',
            'class_date',
            'class_location',
            'class_address',
            'instructor',
            'start_time',
            'end_time',
        ],
        'Training' => [
            // The class snapshot's name, not the live training's, for the same
            // reason certificates use it: reprints must stay stable.
            'training_name',
            // The snapshot's certificate title, falling back to the snapshot
            // name — the same resolution CertificateData uses, so a card and
            // a certificate for the same class always agree.
            'cert_title',
            'cert_code',
            'hours',
        ],
        'Credit' => [
            'cert_id',
            'completion_date',
            'expire_date',
        ],
        'Organization' => [
            'org_name',
        ],
        'Other' => [
            // The date the cards were generated — for "issued on" lines that
            // aren't the completion date.
            'today',
        ],
    ];

    /**
     * Every built-in key, flattened. Derived from GROUPS rather than restated,
     * so the list validation checks and the list the builder displays cannot
     * disagree.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        return array_merge(...array_values(self::GROUPS));
    }

    /** Whether a key is already spoken for by the catalogue. */
    public static function isReserved(string $key): bool
    {
        return in_array($key, self::all(), true);
    }
}
