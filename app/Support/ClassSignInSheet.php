<?php

namespace App\Support;

use App\Models\ClassEnrollment;
use App\Models\TrainingClass;

/**
 * View-model for a class sign-in sheet: header info plus the numbered roster
 * rows (enrolled names with a blank signature column), padded with blank rows
 * so a sparse class still fills the page.
 */
class ClassSignInSheet
{
    /** Minimum rows so the sheet looks full even with few/no students. */
    private const MIN_ROWS = 18;

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

        $rowCount = max(self::MIN_ROWS, $names->count());
        $rows = [];
        for ($i = 0; $i < $rowCount; $i++) {
            $rows[] = $names[$i] ?? '';
        }

        return [
            'org_name' => $class->organization?->name ?? '',
            'title' => $class->name,
            'date' => $class->scheduled_date?->format('M j, Y'),
            'location' => $class->location,
            'length' => $class->total_hours !== null
                ? number_format((float) $class->total_hours, 2).' hrs'
                : null,
            'students' => $names->count(),
            'trainer' => $class->instructor,
            'training_location' => $class->training_location,
            'training_address' => $class->training_address,
            'rows' => $rows,
        ];
    }
}
