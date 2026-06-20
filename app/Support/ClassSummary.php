<?php

namespace App\Support;

use App\Models\ClassTraining;
use App\Models\Completion;
use App\Models\TrainingClass;
use Illuminate\Support\Carbon;

/**
 * View-model for a class summary sheet: header info plus the issued
 * certificates grouped per training. Issue + expire dates are identical for
 * everyone within a training (the class close date and that close date plus
 * the training's lifespan), so they live once on each training's group header
 * rather than repeating on every row.
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

        // Bucket each issued completion under its training, collecting the row
        // (no dates) plus the formatted issue/expire so the group header can
        // collapse them to a single value (or "varies" if they disagree).
        $buckets = [];
        foreach ($completions as $comp) {
            /** @var ClassTraining|null $ct */
            $ct = $ctById->get($comp->class_training_id);
            $user = $comp->user;
            $issue = $comp->completion_date;
            // Prefer the completion's own stamped expiry; otherwise derive it
            // from the training's frequency (repeat_days). No frequency → no
            // fixed life.
            $expires = $comp->expire_date
                ?: (($issue && $ct && $ct->repeat_days)
                    ? Carbon::parse($issue)->addDays($ct->repeat_days)
                    : null);

            $name = $user
                ? trim(($user->l_name ?? '').', '.($user->f_name ?? ''), ', ')
                : '—';

            $buckets[$comp->class_training_id]['rows'][] = [
                'name' => $name !== '' ? $name : '—',
                'emp_number' => $user?->employee_number ?? '',
                'location' => $user?->location ?? '',
                'cert_id' => $comp->cert_id,
            ];
            $buckets[$comp->class_training_id]['issues'][] = $issue
                ? Carbon::parse($issue)->format('M j, Y') : '';
            $buckets[$comp->class_training_id]['expires'][] = $expires
                ? Carbon::parse($expires)->format('M j, Y') : '—';
        }

        // One value when every row in the training agrees, else "varies".
        $fold = function (array $values): string {
            $distinct = array_values(array_unique($values));

            return match (count($distinct)) {
                0 => '',
                1 => $distinct[0],
                default => 'varies',
            };
        };

        // Emit groups in class-training order, skipping trainings with no
        // issued certificates.
        $groups = [];
        $certificates = 0;
        foreach ($class->classTrainings as $ct) {
            $bucket = $buckets[$ct->id] ?? null;

            if ($bucket === null) {
                continue;
            }

            $groups[] = [
                'training' => $ct->training_name,
                'issue_date' => $fold($bucket['issues']),
                'expires' => $fold($bucket['expires']),
                'rows' => $bucket['rows'],
            ];
            $certificates += count($bucket['rows']);
        }

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
            'certificates' => $certificates,
            'groups' => $groups,
            'generated_at' => Carbon::now(config('app.display_timezone'))->format('M j, Y g:i A'),
        ];
    }
}
