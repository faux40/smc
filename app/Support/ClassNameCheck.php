<?php

namespace App\Support;

use App\Models\ClassEnrollment;
use App\Models\Completion;
use App\Models\TrainingClass;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * View-model for a class name-check sheet: the class header plus every
 * person's name **exactly as it will print**, alphabetically by last, first,
 * middle.
 *
 * The point is proof-reading before commitment. `User::name` (certificates)
 * and the card merge key `${full_name}` both resolve to `PersonName::full()`,
 * so there is one string to check and this sheet shows that string — prefix
 * and suffix included — rather than the "Last, First, M." roster form the
 * sign-in sheet uses.
 */
class ClassNameCheck
{
    /**
     * Selectable export columns (key → label), in default order. `full_name`
     * leads and cannot be deselected — see ALWAYS.
     *
     * @var array<string, string>
     */
    public const COLUMNS = [
        'full_name' => 'Full name',
        'employee_number' => 'Employee #',
        'job_title' => 'Job title',
        'department' => 'Department',
        'location' => 'Location',
    ];

    /** Columns a selection cannot drop — the sheet is about this one. */
    public const ALWAYS = ['full_name'];

    /**
     * @return array<string, mixed>
     */
    public static function data(TrainingClass $class): array
    {
        $class->loadMissing('organization', 'classTrainings');

        // A completed class has already handed out its credit, so the people
        // whose names will be printed are exactly those who earned some. An
        // open class has not decided yet — proof-read the whole roster.
        $creditedOnly = $class->status === 'completed';

        $users = $creditedOnly
            ? self::creditedUsers($class)
            : self::rosterUsers($class);

        $rows = $users
            ->filter()
            ->unique('id')
            ->sortBy(fn ($user) => $user->personName()->sortKey())
            ->map(fn ($user) => [
                'full_name' => $user->personName()->full(),
                'employee_number' => $user->employee_number ?? '',
                'job_title' => $user->job_title ?? '',
                'department' => $user->department ?? '',
                'location' => $user->location ?? '',
            ])
            ->values()
            ->all();

        return [
            'org_name' => $class->organization?->name ?? '',
            'title' => 'Name check — '.$class->name,
            'subtitle' => self::subtitle($class, $creditedOnly),
            'credited_only' => $creditedOnly,
            'people' => count($rows),
            'rows' => $rows,
            'generated_at' => Carbon::now(config('app.display_timezone'))->format('M j, Y g:i A'),
        ];
    }

    /**
     * Everyone holding a completion against any of this class's topics —
     * the same test that decides who gets a certificate (mirrors
     * ClassSummary's `$credited` map). An un-numbered completion still
     * counts: it is credit, and its holder's name still prints.
     *
     * @return Collection<int, User>
     */
    private static function creditedUsers(TrainingClass $class): Collection
    {
        return Completion::query()
            ->whereIn('class_training_id', $class->classTrainings->pluck('id'))
            ->with('user:id,prefix_name,f_name,m_name,l_name,suffix_name,employee_number,job_title,department,location')
            ->get()
            ->map(fn (Completion $c) => $c->user);
    }

    /**
     * @return Collection<int, User>
     */
    private static function rosterUsers(TrainingClass $class): Collection
    {
        return ClassEnrollment::query()
            ->where('class_id', $class->id)
            ->with('user:id,prefix_name,f_name,m_name,l_name,suffix_name,employee_number,job_title,department,location')
            ->get()
            ->map(fn (ClassEnrollment $e) => $e->user);
    }

    /** Class facts + who is on the list, so a printed sheet is self-explaining. */
    private static function subtitle(TrainingClass $class, bool $creditedOnly): string
    {
        $parts = array_filter([
            $class->scheduled_date?->format('M j, Y'),
            ClassSignInSheet::timeRange($class->start_time, $class->end_time) ?: null,
            $class->location,
            $class->instructor ? "Instructor: {$class->instructor}" : null,
            $creditedOnly ? 'Credited attendees only' : 'Full roster',
        ]);

        return implode(' · ', $parts);
    }
}
