<?php

namespace Tests\Unit\Support;

use App\Support\Cards\CardMergeKeys;
use PHPUnit\Framework\TestCase;

/**
 * The built-in `${key}` vocabulary a card design can merge. C3 only needs it
 * to stop a custom field shadowing a catalogue key; C4 fills in the values
 * and the builder's copy-paste list from the same source.
 */
class CardMergeKeysTest extends TestCase
{
    public function test_every_built_in_key_is_a_legal_merge_key(): void
    {
        // Same grammar custom keys are held to, so the two sets are
        // comparable and `${key}` extraction treats them identically.
        foreach (CardMergeKeys::all() as $key) {
            $this->assertMatchesRegularExpression('/^[a-z][a-z0-9_]*$/', $key);
        }
    }

    public function test_the_catalogue_has_no_duplicates(): void
    {
        // Also guards the groups: the same key filed under two headings would
        // show up twice in the builder's field list.
        $keys = CardMergeKeys::all();

        $this->assertSame(array_values(array_unique($keys)), array_values($keys));
    }

    public function test_it_covers_the_person_class_and_credit_a_card_prints(): void
    {
        // A wallet card without these is not a wallet card.
        foreach ([
            'first_name', 'last_name', 'full_name',
            'class_date', 'instructor',
            'training_name', 'cert_id', 'completion_date', 'expire_date',
            'org_name',
        ] as $key) {
            $this->assertContains($key, CardMergeKeys::all());
        }
    }

    public function test_it_reports_which_keys_are_taken(): void
    {
        $this->assertTrue(CardMergeKeys::isReserved('first_name'));
        // What a custom field is actually for.
        $this->assertFalse(CardMergeKeys::isReserved('trainer_id'));
    }
}
