<?php

namespace App\Support;

use App\Models\ClassTraining;
use App\Models\Completion;
use App\Models\TrainingClass;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;

/**
 * View-model for a class summary sheet: header info, the issued certificates
 * grouped per training, and — so the sheet accounts for everyone on the roster,
 * not just the winners — the same per-training grouping for the enrollees who
 * failed a topic or never finished it. Issue + expire dates are identical for
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
        $class->loadMissing([
            'classTrainings',
            'organization',
            'enrollments.user:id,f_name,m_name,l_name,prefix_name,suffix_name,employee_number,location',
        ]);
        $ctById = $class->classTrainings->keyBy('id');

        // Every completion on this class, cert or not — an un-numbered one
        // (mid re-open renumber) is still credit, so it must keep its holder
        // out of the fail/incomplete sections. The certificate list below
        // narrows to the numbered ones.
        $completions = Completion::query()
            ->whereIn('class_training_id', $ctById->keys())
            ->with('user:id,f_name,m_name,l_name,prefix_name,suffix_name,employee_number,location')
            ->orderBy('cert_id')
            ->get();

        $credited = [];
        foreach ($completions as $comp) {
            $credited[$comp->class_training_id.'|'.$comp->user_id] = true;
        }

        // Bucket each issued completion under its training, collecting the row
        // (no dates) plus the formatted issue/expire so the group header can
        // collapse them to a single value (or "varies" if they disagree).
        $buckets = [];
        foreach ($completions->whereNotNull('cert_id') as $comp) {
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

            // "Last, First, M." for the roster column; keep the sort key so the
            // group's rows can be ordered alphabetically (last, first, middle).
            $person = $user?->personName();
            $name = $person?->rosterName();

            $buckets[$comp->class_training_id]['rows'][] = [
                'name' => filled($name) ? $name : '—',
                'sort' => $person?->sortKey() ?? ['', '', ''],
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
                'rows' => self::sortedRows($bucket['rows']),
            ];
            $certificates += count($bucket['rows']);
        }

        // Everyone on the roster who earned no credit for a topic, split by
        // why. An explicit `fail` is the only thing that lands in Failed;
        // everything else — an explicit incomplete, or a class closed before
        // per-topic results existed — is Incomplete, which is what "no
        // certificate for this topic" means to the reader either way.
        $failedBy = [];
        $incompleteBy = [];
        foreach ($class->enrollments as $enrollment) {
            $person = $enrollment->user?->personName();
            $name = $person?->rosterName();
            $row = [
                'name' => filled($name) ? $name : '—',
                'sort' => $person?->sortKey() ?? ['', '', ''],
                'emp_number' => $enrollment->user?->employee_number ?? '',
                'location' => $enrollment->user?->location ?? '',
                // The instructor's close-out note — usually the reason.
                'notes' => $enrollment->notes ?? '',
            ];
            $results = $enrollment->results ?? [];

            foreach ($class->classTrainings as $ct) {
                if (isset($credited[$ct->id.'|'.$enrollment->user_id])) {
                    continue;
                }

                if (($results[$ct->id] ?? null) === 'fail') {
                    $failedBy[$ct->id][] = $row;
                } else {
                    $incompleteBy[$ct->id][] = $row;
                }
            }
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
            'failed_groups' => self::outcomeGroups($class, $failedBy),
            'incomplete_groups' => self::outcomeGroups($class, $incompleteBy),
            'generated_at' => Carbon::now(config('app.display_timezone'))->format('M j, Y g:i A'),
        ];
    }

    /**
     * Per-training groups in class-training order, skipping trainings nobody
     * landed in. Same shape as the certificate groups minus the dates.
     *
     * @param  array<string, array<int, array<string, mixed>>>  $rowsByTraining  keyed by class_training id
     * @return array<int, array{training: string, rows: array<int, array<string, mixed>>}>
     */
    private static function outcomeGroups(TrainingClass $class, array $rowsByTraining): array
    {
        $groups = [];

        foreach ($class->classTrainings as $ct) {
            $rows = $rowsByTraining[$ct->id] ?? null;

            if ($rows === null) {
                continue;
            }

            $groups[] = [
                'training' => $ct->training_name,
                'rows' => self::sortedRows($rows),
            ];
        }

        return $groups;
    }

    /**
     * Order a group's roster alphabetically (last, first, middle), then drop
     * the sort key — it's scaffolding, not part of the row's shape.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private static function sortedRows(array $rows): array
    {
        return collect($rows)
            ->sortBy('sort')
            ->map(fn (array $row) => Arr::except($row, 'sort'))
            ->values()
            ->all();
    }
}
