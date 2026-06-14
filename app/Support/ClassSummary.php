<?php

namespace App\Support;

use App\Models\ClassTraining;
use App\Models\Completion;
use App\Models\TrainingClass;
use Illuminate\Support\Carbon;

/**
 * View-model for a class summary sheet: header info plus a "Certificate
 * Issued" table (one row per issued completion, with the student's employee
 * number + location and the cert id / dates).
 */
class ClassSummary
{
    /**
     * @return array<string, mixed>
     */
    public static function data(TrainingClass $class): array
    {
        $class->loadMissing('classTrainings', 'organization');
        $ctById = $class->classTrainings->keyBy('id');

        $completions = Completion::query()
            ->whereIn('class_training_id', $ctById->keys())
            ->whereNotNull('cert_id')
            ->with('user:id,f_name,m_name,l_name,prefix_name,suffix_name,employee_number,location')
            ->orderBy('cert_id')
            ->get();

        $rows = $completions->map(function (Completion $comp) use ($ctById) {
            /** @var ClassTraining|null $ct */
            $ct = $ctById->get($comp->class_training_id);
            $user = $comp->user;
            $issue = $comp->completion_date;
            // Prefer the completion's own expiry (imported records carry a
            // real expire_date even when the topic has no lifespan_months);
            // otherwise derive it from issue date + lifespan.
            $expires = $comp->expire_date
                ?: (($issue && $ct && $ct->lifespan_months)
                    ? Carbon::parse($issue)->addMonths($ct->lifespan_months)
                    : null);

            $name = $user
                ? trim(($user->l_name ?? '').', '.($user->f_name ?? ''), ', ')
                : '—';

            return [
                'name' => $name !== '' ? $name : '—',
                'emp_number' => $user?->employee_number ?? '',
                'location' => $user?->location ?? '',
                'cert_id' => $comp->cert_id,
                'issue_date' => $issue ? Carbon::parse($issue)->format('M j, Y') : '',
                'expires' => $expires ? Carbon::parse($expires)->format('M j, Y') : '—',
            ];
        })->values()->all();

        $trainings = $class->classTrainings->map(fn (ClassTraining $ct) => [
            'name' => $ct->training_name,
            'hours' => $ct->hours !== null
                ? number_format((float) $ct->hours, 2).' hrs'
                : '—',
            'frequency' => $ct->std_freq_name,
        ])->values()->all();

        return [
            'org_name' => $class->organization?->name ?? '',
            'title' => $class->name,
            'trainings' => $trainings,
            'start_date' => $class->scheduled_date?->format('M j, Y'),
            'end_date' => $class->scheduled_date?->format('M j, Y'),
            'closed_date' => $class->completion_date?->format('M j, Y'),
            'time' => ClassSignInSheet::timeRange($class->start_time, $class->end_time),
            'length' => $class->total_hours !== null
                ? number_format((float) $class->total_hours, 2).' hrs'
                : null,
            'trainer' => $class->instructor,
            'location' => $class->location,
            'address' => $class->address,
            'notes' => $class->notes,
            'certificates' => count($rows),
            'rows' => $rows,
            'generated_at' => Carbon::now(config('app.display_timezone'))->format('M j, Y g:i A'),
        ];
    }
}
