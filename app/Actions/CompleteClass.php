<?php

namespace App\Actions;

use App\Models\ClassTraining;
use App\Models\Completion;
use App\Models\Training;
use App\Models\TrainingClass;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Close a class out: stamp per-training expiries, reconcile completions
 * against the per-topic pass/fail decisions, roll enrollee statuses up,
 * and lock the class. Extracted from ClassesController::complete() so the
 * dev seeders generate class credit through the exact same path (the
 * CompletionObserver → RecalculateTrainingStatus chain fires from the
 * Completion writes here). The caller owns authorization, validation, and
 * broadcasting the returned issued / de-issued completions.
 */
class CompleteClass
{
    /**
     * Reconcile completions per enrollee × training pair. Each topic is
     * tri-state:
     *   passed   + no cert → issue one;  passed + has cert → keep it;
     *   failed   + has cert → de-issue it;
     *   UNMARKED → leave as-is (preserve any existing cert).
     * So a re-close only applies the topics actually marked, and the
     * attendees you didn't touch keep their original certificates. New
     * cert ids continue after the highest suffix used on this date.
     *
     * @param  Collection<string, array{id: string, notes?: string|null, results?: array<int, array{class_training_id: string, passed: bool}>}>  $marks  keyed by enrollment id
     * @param  Collection<string, string|null>  $expireOverrides  expire_date keyed by class_training_id
     * @return array{issued: list<Completion>, deIssued: list<array{id: string, user_id: string}>}
     */
    public function handle(
        TrainingClass $class,
        CarbonImmutable $completionDate,
        Collection $marks,
        Collection $expireOverrides,
    ): array {
        $issued = [];
        $deIssued = [];

        DB::transaction(function () use ($class, $marks, $completionDate, $expireOverrides, &$issued, &$deIssued) {
            $class->load(['enrollments', 'classTrainings']);
            $totalTopics = $class->classTrainings->count();

            // Per-training expiry: instructor override, else computed from the
            // snapshot freq (class date + repeat_days; none for initial/as-needed).
            $expiryFor = [];

            foreach ($class->classTrainings as $ct) {
                $expire = $expireOverrides->has($ct->id)
                    ? $expireOverrides->get($ct->id)
                    : ($ct->repeating && $ct->repeat_days
                        ? $completionDate->addDays($ct->repeat_days)->toDateString()
                        : null);
                $ct->update(['expire_date' => $expire]);
                $expiryFor[$ct->id] = $expire;
            }

            $ctIds = $class->classTrainings->pluck('id');
            $dateStr = $completionDate->format('Ymd');
            $certSeq = $this->maxCertSeqForDate($ctIds, $dateStr);

            foreach ($class->enrollments as $enrollment) {
                $enrollMark = $marks->get($enrollment->id) ?? [];
                $results = collect($enrollMark['results'] ?? [])->pluck('passed', 'class_training_id');

                $passedCount = 0;

                foreach ($class->classTrainings as $ct) {
                    $decision = $results->get($ct->id); // true | false | null (unmarked)
                    $existing = Completion::query()
                        ->where('class_training_id', $ct->id)
                        ->where('user_id', $enrollment->user_id)
                        ->first();

                    if ($decision === false) {
                        if ($existing !== null) {
                            // Explicitly failed → de-issue.
                            $deIssued[] = ['id' => $existing->id, 'user_id' => $existing->user_id];
                            $existing->delete();
                        }

                        continue;
                    }

                    if ($decision === null) {
                        // Unmarked → keep the credit. Re-open clears cert ids,
                        // so re-mint one here if it's missing (current code).
                        if ($existing !== null) {
                            $passedCount++;
                            $this->ensureCertId($existing, $ct, $dateStr, $certSeq);
                        }

                        continue;
                    }

                    // Passed.
                    $passedCount++;

                    if ($existing !== null) {
                        // Already credited — keep the record, re-mint its cert id
                        // if re-open cleared it (so a corrected cert_code applies).
                        $this->ensureCertId($existing, $ct, $dateStr, $certSeq);

                        continue;
                    }

                    if ($ct->training_id === null) {
                        continue; // snapshot-only (deleted training) — can't credit fresh.
                    }

                    $certSeq++;
                    $issued[] = Completion::create([
                        'org_id' => $class->org_id,
                        'user_id' => $enrollment->user_id,
                        'module_type' => Training::class,
                        'module_id' => $ct->training_id,
                        'completion_date' => $completionDate->toDateString(),
                        'expire_date' => $expiryFor[$ct->id],
                        'cert_id' => $this->makeCertId($ct, $dateStr, $certSeq),
                        'class_training_id' => $ct->id,
                        'hours' => $ct->hours,
                    ]);
                }

                $enrollment->update([
                    'status' => $this->rollUpStatus($passedCount, $totalTopics),
                    'notes' => $enrollMark['notes'] ?? $enrollment->notes,
                ]);
            }

            $class->update([
                'status' => 'completed',
                'completion_date' => $completionDate->toDateString(),
                'completed_at' => now(),
            ]);
        });

        return ['issued' => $issued, 'deIssued' => $deIssued];
    }

    /** The cert id for a topic: `{cert_code}{YYYYMMDD}-{NNN}` (code → CERT if unset). */
    private function makeCertId(ClassTraining $ct, string $dateStr, int $seq): string
    {
        $code = $ct->cert_code !== null && $ct->cert_code !== ''
            ? $ct->cert_code
            : 'CERT';

        return sprintf('%s%s-%03d', $code, $dateStr, $seq);
    }

    /**
     * Mint a cert id for an existing completion that has none (re-open clears
     * them) using the current cert_code; advances the per-date sequence. A
     * completion that still has a number is left untouched.
     */
    private function ensureCertId(Completion $completion, ClassTraining $ct, string $dateStr, int &$certSeq): void
    {
        if ($completion->cert_id !== null) {
            return;
        }

        $certSeq++;
        $completion->update(['cert_id' => $this->makeCertId($ct, $dateStr, $certSeq)]);
    }

    /** Roll an enrollee's per-topic pass count up to a single status. */
    private function rollUpStatus(int $passedCount, int $totalTopics): string
    {
        return match (true) {
            $totalTopics > 0 && $passedCount >= $totalTopics => 'passed',
            $passedCount > 0 => 'partial',
            default => 'incomplete',
        };
    }

    /**
     * Highest `-NNN` suffix already used on this date across the class's certs
     * (incl. soft-deleted), so newly-issued ids on a re-close never collide
     * with preserved or previously-issued ones. cert id = `{code}{YYYYMMDD}-{NNN}`.
     *
     * @param  Collection<int, string>  $ctIds
     */
    private function maxCertSeqForDate(Collection $ctIds, string $dateStr): int
    {
        $max = 0;
        $needle = $dateStr.'-';

        Completion::withTrashed()
            ->whereIn('class_training_id', $ctIds)
            ->whereNotNull('cert_id')
            ->pluck('cert_id')
            ->each(function (string $certId) use (&$max, $needle): void {
                $pos = strpos($certId, $needle);

                if ($pos !== false) {
                    $max = max($max, (int) substr($certId, $pos + strlen($needle)));
                }
            });

        return $max;
    }
}
