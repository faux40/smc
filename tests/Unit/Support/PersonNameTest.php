<?php

namespace Tests\Unit\Support;

use App\Support\PersonName;
use Tests\TestCase;

class PersonNameTest extends TestCase
{
    public function test_full_composes_natural_order_with_prefix_and_suffix(): void
    {
        $name = new PersonName('Dr.', 'Ada', 'Augusta', 'Lovelace', 'III');

        $this->assertSame('Dr. Ada Augusta Lovelace III', $name->full());
    }

    public function test_full_drops_empty_segments_and_collapses_whitespace(): void
    {
        $name = new PersonName(null, ' Frank ', null, 'Forklift', null);

        $this->assertSame('Frank Forklift', $name->full());
    }

    public function test_full_is_empty_when_no_parts(): void
    {
        $this->assertSame('', (new PersonName)->full());
    }

    public function test_sortable_puts_last_name_first_with_first_and_middle(): void
    {
        $name = new PersonName(null, 'Ada', 'Augusta', 'Lovelace', null);

        $this->assertSame('Lovelace, Ada Augusta', $name->sortable());
    }

    public function test_sortable_drops_prefix_and_suffix(): void
    {
        $name = new PersonName('Dr.', 'John', 'Q', 'Smith', 'III');

        $this->assertSame('Smith, John Q', $name->sortable());
    }

    public function test_sortable_with_last_name_only_has_no_trailing_comma(): void
    {
        $name = new PersonName(null, null, null, 'Lovelace', null);

        $this->assertSame('Lovelace', $name->sortable());
    }

    public function test_sortable_with_first_name_only_has_no_leading_comma(): void
    {
        $name = new PersonName(null, 'Ada', null, null, null);

        $this->assertSame('Ada', $name->sortable());
    }

    public function test_sortable_is_empty_when_no_parts(): void
    {
        $this->assertSame('', (new PersonName)->sortable());
    }

    public function test_short_is_first_and_last_only(): void
    {
        $name = new PersonName('Dr.', 'Ada', 'Augusta', 'Lovelace', 'III');

        $this->assertSame('Ada Lovelace', $name->short());
    }

    public function test_short_with_single_part(): void
    {
        $this->assertSame('Ada', (new PersonName(null, 'Ada', null, null, null))->short());
        $this->assertSame('Lovelace', (new PersonName(null, null, null, 'Lovelace', null))->short());
    }

    public function test_initials_use_first_and_last_uppercased(): void
    {
        $name = new PersonName(null, 'ada', 'augusta', 'lovelace', null);

        $this->assertSame('AL', $name->initials());
    }

    public function test_initials_fall_back_to_single_available_part(): void
    {
        $this->assertSame('A', (new PersonName(null, 'Ada', null, null, null))->initials());
        $this->assertSame('L', (new PersonName(null, null, null, 'Lovelace', null))->initials());
        $this->assertSame('', (new PersonName)->initials());
    }
}
