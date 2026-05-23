<?php

namespace App\Support;

use App\Models\ClassTraining;
use App\Models\Completion;
use App\Models\TrainingClass;
use Illuminate\Support\Carbon;

/**
 * Builds the per-certificate view-models for a completed class: one row per
 * issued completion (passed student × topic), resolved against the frozen
 * class_training snapshot so reprints are stable.
 */
class ClassCertificates
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public static function rows(TrainingClass $class): array
    {
        $class->loadMissing('classTrainings', 'organization');
        $ctById = $class->classTrainings->keyBy('id');

        $completions = Completion::query()
            ->whereIn('class_training_id', $ctById->keys())
            ->whereNotNull('cert_id')
            ->with('user:id,f_name,m_name,l_name,prefix_name,suffix_name')
            ->orderBy('cert_id')
            ->get();

        $orgName = $class->organization?->name ?? '';

        return $completions
            ->map(function (Completion $comp) use ($ctById, $class, $orgName): ?array {
                /** @var ClassTraining|null $ct */
                $ct = $ctById->get($comp->class_training_id);

                if ($ct === null) {
                    return null;
                }

                $issue = $comp->completion_date;
                $expires = ($issue && $ct->lifespan_months)
                    ? Carbon::parse($issue)->addMonths($ct->lifespan_months)
                    : null;

                $lines = array_values(array_filter([
                    $ct->cert_text_line_1,
                    $ct->cert_text_line_2,
                    $ct->cert_text_line_3,
                    $ct->cert_text_line_4,
                ], fn ($l) => filled($l)));

                return [
                    'org_name' => $orgName,
                    'student_name' => $comp->user?->name,
                    'cert_title' => $ct->cert_title ?: $ct->training_name,
                    'text_lines' => $lines,
                    'cert_id' => $comp->cert_id,
                    'issue_date' => $issue ? Carbon::parse($issue)->format('F j, Y') : '',
                    'expires' => $expires ? $expires->format('F j, Y') : '—',
                    'hours' => $ct->hours !== null ? number_format((float) $ct->hours, 2) : '—',
                    'trainer' => $class->instructor,
                    'show_signature' => (bool) $ct->show_signature_on_cert,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }
}
