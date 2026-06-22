<?php

namespace Tests\Unit\Support;

use App\Support\ReportGrouping;
use PHPUnit\Framework\TestCase;

/**
 * Flattens report rows into a render list of group-header + data-row items for
 * the PDF blade. Grouping is nested in the given key order (precedence); each
 * group header carries its leaf-row count.
 */
class ReportGroupingTest extends TestCase
{
    /** Minimal rows shaped like ReportsController::reportRows() output. */
    private function rows(): array
    {
        return [
            ['user_id' => 'u1', 'user' => 'Lee, Sam', 'location' => 'Yard', 'department' => 'Ops', 'training' => 'CPR', 'status' => 'Current'],
            ['user_id' => 'u2', 'user' => 'Doe, Jane', 'location' => 'Yard', 'department' => 'Admin', 'training' => 'CPR', 'status' => 'Expired'],
            ['user_id' => 'u3', 'user' => 'Roe, Max', 'location' => 'Dock', 'department' => 'Ops', 'training' => 'First Aid', 'status' => 'Current'],
        ];
    }

    public function test_no_group_keys_returns_each_row_as_a_row_item(): void
    {
        $items = ReportGrouping::flatten($this->rows(), []);

        $this->assertCount(3, $items);
        $this->assertSame('row', $items[0]['type']);
        $this->assertSame('Lee, Sam', $items[0]['data']['user']);
    }

    public function test_single_key_emits_a_header_with_count_before_each_groups_rows(): void
    {
        $items = ReportGrouping::flatten($this->rows(), ['location']);

        // Groups sorted by label: Dock (1) then Yard (2).
        $this->assertSame(['type' => 'group', 'level' => 0, 'label' => 'Location: Dock', 'count' => 1], $items[0]);
        $this->assertSame('row', $items[1]['type']);
        $this->assertSame('Roe, Max', $items[1]['data']['user']);

        $this->assertSame(['type' => 'group', 'level' => 0, 'label' => 'Location: Yard', 'count' => 2], $items[2]);
        $this->assertSame('row', $items[3]['type']);
        $this->assertSame('row', $items[4]['type']);
    }

    public function test_nested_keys_group_in_precedence_order(): void
    {
        $items = ReportGrouping::flatten($this->rows(), ['location', 'department']);
        $shape = array_map(
            fn (array $i) => $i['type'] === 'group'
                ? ['g', $i['level'], $i['label'], $i['count']]
                : ['r', $i['data']['user']],
            $items,
        );

        $this->assertSame([
            ['g', 0, 'Location: Dock', 1],
            ['g', 1, 'Department: Ops', 1],
            ['r', 'Roe, Max'],
            ['g', 0, 'Location: Yard', 2],
            ['g', 1, 'Department: Admin', 1],
            ['r', 'Doe, Jane'],
            ['g', 1, 'Department: Ops', 1],
            ['r', 'Lee, Sam'],
        ], $shape);
    }

    public function test_reordering_keys_changes_the_nesting(): void
    {
        $items = ReportGrouping::flatten($this->rows(), ['department', 'location']);

        // Department is now the outer level.
        $this->assertSame(['type' => 'group', 'level' => 0, 'label' => 'Department: Admin', 'count' => 1], $items[0]);
        $this->assertSame(['type' => 'group', 'level' => 1, 'label' => 'Location: Yard', 'count' => 1], $items[1]);
    }

    public function test_users_group_by_id_not_display_name(): void
    {
        $rows = [
            ['user_id' => 'u1', 'user' => 'Smith, Pat', 'location' => 'A'],
            ['user_id' => 'u2', 'user' => 'Smith, Pat', 'location' => 'B'], // same display, different person
        ];
        $groups = array_values(array_filter(
            ReportGrouping::flatten($rows, ['user']),
            fn (array $i) => $i['type'] === 'group',
        ));

        // Two distinct user_ids → two groups, even though the names collide.
        $this->assertCount(2, $groups);
        $this->assertSame('User: Smith, Pat', $groups[0]['label']);
    }

    public function test_empty_values_render_as_none(): void
    {
        $rows = [['user_id' => 'u1', 'user' => 'Lee, Sam', 'department' => '—']];
        $items = ReportGrouping::flatten($rows, ['department']);

        $this->assertSame('Department: (none)', $items[0]['label']);
    }

    public function test_sanitize_drops_unknown_and_duplicate_keys_preserving_order(): void
    {
        $this->assertSame(
            ['location', 'department'],
            ReportGrouping::sanitize(['location', 'bogus', 'department', 'location']),
        );
    }
}
