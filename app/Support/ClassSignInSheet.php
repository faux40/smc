<?php

namespace App\Support;

use App\Models\ClassEnrollment;
use App\Models\TrainingClass;
use Illuminate\Support\Carbon;

/**
 * View-model for a class sign-in sheet: header info plus the numbered roster
 * rows (enrolled names with a blank signature column), padded with blank rows
 * so a sparse class still fills the page.
 */
class ClassSignInSheet
{
    /**
     * Row capacities for a Letter page. CSS math (96dpi): content height
     * = 11in − 1.15in top margin (hosts the repeating Chromium header)
     * − 0.75in bottom (footer) ≈ 869px; each roster row is 36px + 1px
     * collapsed border ≈ 37px; the column-header row ≈ 36px. Page 1 also
     * carries the header block (org/title line + "Sign In Sheet" heading +
     * info table ≈ 200px, more with a multi-line address) → (869−200−36)/37
     * ≈ 17, held at 16 to absorb a long address. Continuation pages repeat
     * only the column headers → (869−36)/37 ≈ 22.5 → 22.
     *
     * Public so the tests (and anything else sizing the sheet) share the
     * numbers. Nudge here if a visual pass shows over/underflow.
     */
    public const FIRST_PAGE_ROWS = 16;

    public const NEXT_PAGE_ROWS = 22;

    /**
     * @return array<string, mixed>
     */
    public static function data(TrainingClass $class): array
    {
        $class->loadMissing('organization');

        $names = ClassEnrollment::query()
            ->where('class_id', $class->id)
            ->with('user:id,f_name,m_name,l_name,prefix_name,suffix_name')
            ->get()
            ->map(fn (ClassEnrollment $e) => $e->user?->name)
            ->filter()
            ->sort()
            ->values();

        $rowCount = self::rowCount($names->count(), $class->max_students);
        $rows = [];
        for ($i = 0; $i < $rowCount; $i++) {
            $rows[] = $names[$i] ?? '';
        }

        return [
            'org_name' => $class->organization?->name ?? '',
            'title' => $class->name,
            'date' => $class->scheduled_date?->format('M j, Y'),
            'time' => self::timeRange($class->start_time, $class->end_time),
            'location' => $class->location,
            'address' => $class->address,
            'length' => $class->total_hours !== null
                ? number_format((float) $class->total_hours, 2).' hrs'
                : null,
            'students' => $names->count(),
            // Reference max (0 = unset) → the sheet header reads "7 of 20".
            'max_students' => $class->max_students > 0 ? $class->max_students : null,
            'trainer' => $class->instructor,
            'generated_at' => Carbon::now(config('app.display_timezone'))->format('M j, Y g:i A'),
            'rows' => $rows,
        ];
    }

    /**
     * How many roster rows to print. With a reference max on the class, the
     * sheet is exactly that size — unless MORE people are enrolled, in which
     * case everyone prints and the max is ignored (no blanks). With no max,
     * pad the last page full of blank rows (walk-ins), never half-empty.
     */
    private static function rowCount(int $enrolled, ?int $max): int
    {
        if ($max !== null && $max > 0) {
            return max($max, $enrolled);
        }

        if ($enrolled <= self::FIRST_PAGE_ROWS) {
            return self::FIRST_PAGE_ROWS;
        }

        $overflowPages = (int) ceil(($enrolled - self::FIRST_PAGE_ROWS) / self::NEXT_PAGE_ROWS);

        return self::FIRST_PAGE_ROWS + $overflowPages * self::NEXT_PAGE_ROWS;
    }

    /** "8:00 AM – 12:00 PM" / "from 8:00 AM" / "" from optional HH:MM strings. */
    public static function timeRange(?string $start, ?string $end): string
    {
        $fmt = fn (?string $t) => filled($t)
            ? Carbon::createFromFormat('H:i', $t)->format('g:i A')
            : null;

        $s = $fmt($start);
        $e = $fmt($end);

        return match (true) {
            $s !== null && $e !== null => "{$s} – {$e}",
            $s !== null => "from {$s}",
            $e !== null => "until {$e}",
            default => '',
        };
    }
}
