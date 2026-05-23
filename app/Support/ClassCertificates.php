<?php

namespace App\Support;

use App\Models\ClassTraining;
use App\Models\Completion;
use App\Models\TrainingClass;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

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

                return [
                    'org_name' => $orgName,
                    'student_name' => $comp->user?->name,
                    'cert_title' => $ct->cert_title ?: $ct->training_name,
                    // Markdown → safe HTML subset (raw HTML stripped) for the
                    // certificate body; gives the author control over line
                    // breaks plus light bold/italic styling.
                    'cert_html' => filled($ct->cert_text)
                        ? Str::markdown($ct->cert_text, ['html_input' => 'strip', 'allow_unsafe_links' => false])
                        : '',
                    'cert_id' => $comp->cert_id,
                    'issue_date' => $issue ? Carbon::parse($issue)->format('F j, Y') : '',
                    'expires' => $expires ? $expires->format('F j, Y') : '—',
                    'hours' => $ct->hours !== null ? number_format((float) $ct->hours, 2) : '—',
                    'trainer' => $class->instructor,
                    'show_signature' => (bool) $class->show_signature,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }
}
