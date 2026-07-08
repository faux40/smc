<?php

namespace App\Support;

use App\Models\Training;
use Carbon\CarbonImmutable;

/**
 * F9 — single source of truth for deriving a completion's `expire_date` from
 * a training's repeat frequency: completion_date + repeat_days when the
 * training genuinely repeats, else null (never expires). Shared by every
 * write path that can mint a completion:
 *   - CompleteClass (class close-out) — uses the frozen ClassTraining
 *     snapshot (`repeating` + `repeat_days` columns copied at attach time).
 *   - CompletionsController::store/update/bulkStore — use the live Training
 *     + its stdFrequency relation.
 *
 * A training that is initial-only or as-needed (repeating=false) never gets
 * a computed expiry — "Current" is the correct, permanent status for it.
 */
class ExpiryCalculator
{
    /**
     * completion_date + repeat_days, or null when the training doesn't
     * repeat (or has no frequency set). Date-only math — no time component,
     * no timezone involved.
     */
    public static function fromRepeatDays(string $completionDate, bool $repeating, ?int $repeatDays): ?string
    {
        if (! $repeating || ! $repeatDays) {
            return null;
        }

        return CarbonImmutable::parse($completionDate)->addDays($repeatDays)->toDateString();
    }

    /**
     * Convenience wrapper for the live Training model — resolves `repeating`
     * + the frequency's `repeat_days` from its stdFrequency relation (load it
     * eagerly; this does not itself query).
     */
    public static function forTraining(Training $training, string $completionDate): ?string
    {
        return self::fromRepeatDays($completionDate, $training->repeating, $training->stdFrequency?->repeat_days);
    }
}
