<?php

namespace App\Support;

use App\Models\ClassTraining;
use App\Models\Completion;
use App\Models\Training;
use App\Models\TrainingClass;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Builds the per-certificate view-models rendered by the `pdf.certificate`
 * blade. One row per issued completion.
 *
 * Cert content (title / body) is resolved against the frozen
 * class_training snapshot when the completion came from a class — so reprints
 * are stable and per-class edits are honoured — otherwise it falls back to the
 * live Training (e.g. a manual / imported completion with no class).
 */
class CertificateData
{
    /**
     * Every issued certificate for a completed class.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function forClass(TrainingClass $class): array
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
                $ct = $ctById->get($comp->class_training_id);

                return $ct === null ? null : self::row($comp, $ct, $class, $orgName);
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * A single completion's certificate. Resolves content from the class
     * snapshot if the completion came from a class, else from the training.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function forCompletion(Completion $completion): array
    {
        $completion->loadMissing('user:id,f_name,m_name,l_name,prefix_name,suffix_name', 'organization');

        $class = null;
        $source = null;

        if ($completion->class_training_id !== null) {
            /** @var ClassTraining|null $ct */
            $ct = ClassTraining::with('trainingClass.organization')->find($completion->class_training_id);

            if ($ct !== null) {
                $source = $ct;
                $class = $ct->trainingClass;
            }
        }

        // No (resolvable) class snapshot → resolve from the live training module.
        if ($source === null) {
            $module = $completion->module;
            $source = $module instanceof Training ? $module : null;
        }

        $orgName = $class?->organization?->name
            ?? $completion->organization?->name
            ?? '';

        return [self::row($completion, $source, $class, $orgName)];
    }

    /**
     * Assemble one certificate row. `$source` is the content owner — a
     * ClassTraining snapshot or a Training (or null when neither resolves);
     * `$class` carries the event-level fields (instructor / signature) and is
     * null for a non-class completion.
     *
     * @return array<string, mixed>
     */
    private static function row(
        Completion $comp,
        ?Model $source,
        ?TrainingClass $class,
        string $orgName,
    ): array {
        $issue = $comp->completion_date;
        // A training's lifespan is its frequency: the recurrence interval
        // (repeat_days). ClassTraining snapshots it; a live Training carries it
        // via its std_frequency. No frequency → no fixed life.
        $repeatDays = $source instanceof ClassTraining
            ? $source->repeat_days
            : ($source instanceof Training ? $source->stdFrequency?->repeat_days : null);

        // Prefer the completion's own stamped expiry; fall back to issue +
        // frequency for records that never had one stamped.
        $expires = $comp->expire_date
            ?? (($issue && $repeatDays) ? Carbon::parse($issue)->addDays($repeatDays) : null);

        $title = $source?->cert_title ?: self::sourceName($source);
        $text = $source?->cert_text;
        $hours = $comp->hours ?? ($source instanceof ClassTraining ? $source->hours : null);

        return [
            'org_name' => $orgName,
            'student_name' => $comp->user?->name,
            'cert_title' => $title,
            // Markdown → safe HTML subset (raw HTML stripped) for the cert body;
            // gives the author bold/italic plus intuitive line handling: a
            // single newline is a visible line break (soft_break → <br>), a
            // blank line starts a new paragraph.
            'cert_html' => filled($text)
                ? Str::markdown($text, [
                    'html_input' => 'strip',
                    'allow_unsafe_links' => false,
                    'renderer' => ['soft_break' => "<br>\n"],
                ])
                : '',
            'cert_id' => $comp->cert_id ?: $comp->cert_ident,
            'issue_date' => $issue ? Carbon::parse($issue)->format('F j, Y') : '',
            'expires' => $expires ? Carbon::parse($expires)->format('F j, Y') : '—',
            'hours' => $hours !== null ? number_format((float) $hours, 2) : '—',
            'trainer' => $class?->instructor,
            'show_signature' => (bool) $class?->show_signature,
        ];
    }

    /**
     * The certificate background as a base64 data URI (drawn full-page under
     * the cert text via CSS), or null when no background image is present.
     * Resolves config('certificates.background') if set, else auto-detects
     * storage/app/private/cert_background.{png,jpg,jpeg,gif,webp}.
     */
    public static function backgroundDataUri(): ?string
    {
        // An explicit config path is used exactly (no fallback); otherwise
        // auto-detect cert_background.<ext> in storage/app/private.
        if (is_string($explicit = config('certificates.background')) && $explicit !== '') {
            $candidates = [$explicit];
        } else {
            $base = storage_path('app/private/cert_background');
            $candidates = array_map(
                fn (string $ext): string => "{$base}.{$ext}",
                ['png', 'jpg', 'jpeg', 'gif', 'webp'],
            );
        }

        foreach ($candidates as $path) {
            if (! is_file($path)) {
                continue;
            }

            $mime = match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
                'jpg', 'jpeg' => 'image/jpeg',
                'gif' => 'image/gif',
                'webp' => 'image/webp',
                default => 'image/png',
            };

            return 'data:'.$mime.';base64,'.base64_encode((string) file_get_contents($path));
        }

        return null;
    }

    /** The display name of the content source (snapshot or training). */
    private static function sourceName(?Model $source): string
    {
        return match (true) {
            $source instanceof ClassTraining => (string) $source->training_name,
            $source instanceof Training => (string) $source->name,
            default => '',
        };
    }
}
