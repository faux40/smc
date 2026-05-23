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
    /** Rows that fill one Letter page at the current font + 0.75in padding.
     *  We always pad up to a whole number of pages so the last page is full
     *  of blank rows (e.g. for walk-ins), never half-empty. */
    private const ROWS_PER_PAGE = 14;

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

        $pages = max(1, (int) ceil($names->count() / self::ROWS_PER_PAGE));
        $rowCount = $pages * self::ROWS_PER_PAGE;
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
            'trainer' => $class->instructor,
            'rows' => $rows,
        ];
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
