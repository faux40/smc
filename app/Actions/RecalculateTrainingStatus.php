<?php

namespace App\Actions;

use App\Models\Completion;
use App\Models\Training;
use App\Models\TrainingAssignment;

class RecalculateTrainingStatus
{
    /**
     * Recompute expires_at and last_completed_at on every TrainingAssignment
     * for the given (user, training) pair, based on the user's completion
     * history. Called by the CompletionObserver on every save/delete.
     */
    public function handle(string $userId, string $trainingId): void
    {
        $assignments = TrainingAssignment::where('user_id', $userId)
            ->where('training_id', $trainingId)
            ->get();

        if ($assignments->isEmpty()) {
            return;
        }

        $latest = Completion::where('user_id', $userId)
            ->where('module_type', Training::class)
            ->where('module_id', $trainingId)
            ->orderByDesc('completion_date')
            ->first();

        [$expiresAt, $lastCompletedAt] = $this->computeStatus($latest, $trainingId);

        foreach ($assignments as $assignment) {
            $assignment->update([
                'expires_at' => $expiresAt,
                'last_completed_at' => $lastCompletedAt,
            ]);
        }
    }

    /**
     * @return array{0: \Carbon\CarbonInterface|null, 1: \Carbon\CarbonInterface|null}
     */
    private function computeStatus(?Completion $latest, string $trainingId): array
    {
        if ($latest === null) {
            return [null, null];
        }

        $lastCompletedAt = $latest->completion_date;

        // Explicit expiry on the completion record takes precedence.
        if ($latest->expire_date !== null) {
            return [$latest->expire_date, $lastCompletedAt];
        }

        // Fall back to training frequency when the completion has no expiry.
        $training = Training::with('stdFrequency')->find($trainingId);

        if ($training?->repeating && $training->stdFrequency) {
            $expiresAt = $lastCompletedAt->addDays($training->stdFrequency->repeat_days);

            return [$expiresAt, $lastCompletedAt];
        }

        // initial_only or as_needed — completed forever / no automatic expiry.
        return [null, $lastCompletedAt];
    }
}
