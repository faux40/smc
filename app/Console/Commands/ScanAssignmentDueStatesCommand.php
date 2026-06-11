<?php

namespace App\Console\Commands;

use App\Models\AssignmentNotificationState;
use App\Models\Organization;
use App\Models\TrainingAssignment;
use App\Notifications\AssignmentDueSoon;
use App\Notifications\AssignmentOverdue;
use App\Services\TrainingStatusService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Phase 15.3 daily watchdog, repointed to the TA engine in J4. Walks
 * every training assignment, computes the canonical status via
 * TrainingStatusService, and fires AssignmentDueSoon /
 * AssignmentOverdue only on the *edge transition* into those states —
 * tracked per TA in `assignment_notification_states.last_seen_status`.
 *
 * Edge-triggered (not level-triggered) so a training that sits `overdue`
 * for weeks generates exactly one notification, not one per daily run.
 * Backward transitions (overdue → current after a completion lands) are
 * silent. `as_needed` TAs are informational — never fired, no state row
 * (the role legacy `inactive` played).
 *
 * Runs in CLI context with no `currentOrgId` bound, so the org global
 * scope is a no-op and the scan spans every org in one pass.
 */
class ScanAssignmentDueStatesCommand extends Command
{
    protected $signature = 'assignments:scan-due-states';

    protected $description = 'Daily watchdog: fire AssignmentDueSoon / AssignmentOverdue on edge transitions.';

    public function handle(TrainingStatusService $status): int
    {
        $fired = 0;

        // Per-org amber windows, resolved once.
        $windows = Organization::query()
            ->get()
            ->mapWithKeys(fn (Organization $org) => [$org->id => $org->expiringSoonDays()]);

        TrainingAssignment::query()
            ->with('user')
            ->chunkById(200, function (Collection $tas) use ($status, $windows, &$fired): void {
                foreach ($tas as $ta) {
                    $bucket = $status->statusFor(
                        $ta,
                        $windows[$ta->org_id] ?? Organization::DEFAULT_EXPIRING_SOON_DAYS,
                    );

                    $fired += $this->reconcile($ta, $bucket, $status);
                }
            });

        $this->info("Watchdog complete. {$fired} notification(s) fired.");

        return self::SUCCESS;
    }

    /**
     * Compare freshly computed status against last-seen, fire on a
     * qualifying transition, persist the new status. Returns 1 if a
     * notification fired, else 0.
     */
    private function reconcile(TrainingAssignment $ta, string $bucket, TrainingStatusService $status): int
    {
        // As-needed trainings are neither tracked nor notified.
        if ($bucket === TrainingStatusService::STATUS_AS_NEEDED) {
            return 0;
        }

        $state = AssignmentNotificationState::where('training_assignment_id', $ta->id)->first();
        $fired = 0;

        if ($bucket !== $state?->last_seen_status && $ta->user !== null) {
            $expiresAt = $ta->expires_at?->toDateString();
            $days = $status->daysUntilDue($ta);

            if ($bucket === TrainingStatusService::STATUS_DUE_SOON) {
                $ta->user->notify(new AssignmentDueSoon($ta->id, $ta->training_id, $ta->name, $expiresAt, $days));
                $fired = 1;
            } elseif ($bucket === TrainingStatusService::STATUS_OVERDUE) {
                $ta->user->notify(new AssignmentOverdue($ta->id, $ta->training_id, $ta->name, $expiresAt, $days));
                $fired = 1;
            }
        }

        if ($state === null) {
            AssignmentNotificationState::create([
                'org_id' => $ta->org_id,
                'training_assignment_id' => $ta->id,
                'last_seen_status' => $bucket,
            ]);
        } elseif ($state->last_seen_status !== $bucket) {
            $state->update(['last_seen_status' => $bucket]);
        }

        return $fired;
    }
}
