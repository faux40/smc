<?php

namespace App\Console\Commands;

use App\Models\AssignmentNotificationState;
use App\Models\Organization;
use App\Models\TrainingAssignment;
use App\Notifications\AssignmentDueSoon;
use App\Notifications\AssignmentOverdue;
use App\Services\TrainingStatusService;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

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

        // Per-org amber windows + overdue re-fire intervals, resolved once.
        $orgs = Organization::query()->get()->keyBy('id');

        TrainingAssignment::query()
            ->with('user')
            ->chunkById(200, function (Collection $tas) use ($status, $orgs, &$fired): void {
                foreach ($tas as $ta) {
                    $org = $orgs->get($ta->org_id);
                    $bucket = $status->statusFor(
                        $ta,
                        $org?->expiringSoonDays() ?? Organization::DEFAULT_EXPIRING_SOON_DAYS,
                    );

                    // Daily reconcile of the denormalized status — catches
                    // date-crossings (due_soon → overdue) that fire no event.
                    // Raw update: no model events, no updated_at churn.
                    if ($ta->status !== $bucket) {
                        DB::table('training_assignments')
                            ->where('id', $ta->id)
                            ->update(['status' => $bucket]);
                    }

                    $fired += $this->reconcile($ta, $bucket, $status, $org?->overdueReminderIntervalDays());
                }
            });

        $this->info("Watchdog complete. {$fired} notification(s) fired.");

        return self::SUCCESS;
    }

    /**
     * Compare freshly computed status against last-seen, fire on a
     * qualifying transition, then — for assignments that sit `overdue` — re-fire
     * on the org's re-notification interval. Persists the new status and, when a
     * notification went out, stamps `last_notified_at`. Returns 1 if a
     * notification fired, else 0.
     *
     * @param  int|null  $reminderInterval  the org's overdue re-fire interval in
     *                                      days, or null when disabled.
     */
    private function reconcile(TrainingAssignment $ta, string $bucket, TrainingStatusService $status, ?int $reminderInterval): int
    {
        // As-needed trainings are neither tracked nor notified.
        if ($bucket === TrainingStatusService::STATUS_AS_NEEDED) {
            return 0;
        }

        $state = AssignmentNotificationState::where('training_assignment_id', $ta->id)->first();
        $now = Date::now();
        $notifiedNow = false;

        // Edge transition into due_soon / overdue — the first notification.
        if ($bucket !== $state?->last_seen_status && $ta->user !== null) {
            if ($bucket === TrainingStatusService::STATUS_DUE_SOON) {
                $this->notifyDueSoon($ta, $status);
                $notifiedNow = true;
            } elseif ($bucket === TrainingStatusService::STATUS_OVERDUE) {
                $this->notifyOverdue($ta, $status);
                $notifiedNow = true;
            }
        }

        // Recurring overdue re-fire. Only for an assignment that is *still*
        // overdue this run, has a prior send to measure from, and whose org
        // opted into an interval. Guarded by `!$notifiedNow` so it can never
        // double-send on the same run the edge trigger just fired.
        if (! $notifiedNow
            && $bucket === TrainingStatusService::STATUS_OVERDUE
            && $reminderInterval !== null
            && $state !== null
            && $state->last_notified_at !== null
            && $ta->user !== null
            && $state->last_notified_at->lte($now->copy()->subDays($reminderInterval))
        ) {
            $this->notifyOverdue($ta, $status);
            $notifiedNow = true;
        }

        $this->persistState($ta, $state, $bucket, $reminderInterval, $notifiedNow, $now);

        return $notifiedNow ? 1 : 0;
    }

    private function notifyDueSoon(TrainingAssignment $ta, TrainingStatusService $status): void
    {
        $ta->user->notify(new AssignmentDueSoon(
            $ta->id, $ta->training_id, $ta->name, $ta->expires_at?->toDateString(), $status->daysUntilDue($ta),
        ));
    }

    private function notifyOverdue(TrainingAssignment $ta, TrainingStatusService $status): void
    {
        $ta->user->notify(new AssignmentOverdue(
            $ta->id, $ta->training_id, $ta->name, $ta->expires_at?->toDateString(), $status->daysUntilDue($ta),
        ));
    }

    /**
     * Upsert the state row: advance last_seen_status, stamp last_notified_at
     * when a notification just fired, and otherwise seed the re-fire clock for
     * a freshly-tracked overdue assignment (so enabling an interval doesn't
     * immediately re-notify every already-overdue assignment).
     */
    private function persistState(
        TrainingAssignment $ta,
        ?AssignmentNotificationState $state,
        string $bucket,
        ?int $reminderInterval,
        bool $notifiedNow,
        CarbonInterface $now,
    ): void {
        if ($state === null) {
            AssignmentNotificationState::create([
                'org_id' => $ta->org_id,
                'training_assignment_id' => $ta->id,
                'last_seen_status' => $bucket,
                'last_notified_at' => $notifiedNow ? $now : null,
            ]);

            return;
        }

        $update = [];
        if ($state->last_seen_status !== $bucket) {
            $update['last_seen_status'] = $bucket;
        }
        if ($notifiedNow) {
            $update['last_notified_at'] = $now;
        } elseif ($bucket === TrainingStatusService::STATUS_OVERDUE
            && $reminderInterval !== null
            && $state->last_notified_at === null) {
            // Seed the clock without notifying — the next interval boundary
            // measures from now, not from the epoch.
            $update['last_notified_at'] = $now;
        }

        if ($update !== []) {
            $state->update($update);
        }
    }
}
