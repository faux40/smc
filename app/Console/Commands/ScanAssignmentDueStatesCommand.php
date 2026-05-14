<?php

namespace App\Console\Commands;

use App\Models\Assignment;
use App\Models\AssignmentNotificationState;
use App\Models\User;
use App\Notifications\AssignmentDueSoon;
use App\Notifications\AssignmentOverdue;
use App\Services\UserComplianceCalculator;
use Illuminate\Console\Command;

/**
 * Phase 15.3 daily watchdog. Walks every user's assignments, computes
 * the current compliance status via UserComplianceCalculator, and fires
 * AssignmentDueSoon / AssignmentOverdue only on the *edge transition*
 * into those states — tracked per assignment in
 * `assignment_notification_states.last_seen_status`.
 *
 * Edge-triggered (not level-triggered) so an assignment that sits
 * `overdue` for weeks generates exactly one notification, not one per
 * daily run. Backward transitions (overdue → current after a completion
 * lands) and `inactive` assignments are silent — `inactive` doesn't even
 * get a state row.
 *
 * Runs in CLI context with no `currentOrgId` bound, so the org global
 * scope is a no-op and the scan spans every org in one pass.
 */
class ScanAssignmentDueStatesCommand extends Command
{
    protected $signature = 'assignments:scan-due-states';

    protected $description = 'Daily watchdog: fire AssignmentDueSoon / AssignmentOverdue on edge transitions.';

    public function handle(UserComplianceCalculator $calculator): int
    {
        $fired = 0;

        User::query()->each(function (User $user) use ($calculator, &$fired): void {
            $result = $calculator->compute($user);

            // Index this user's assignments by id once so reconcile()
            // can pass the model to the notification without an N+1.
            $assignments = Assignment::query()
                ->where('user_id', $user->id)
                ->get()
                ->keyBy('id');

            foreach ($result['groups'] as $status => $rows) {
                // Inactive assignments are neither tracked nor notified.
                if ($status === UserComplianceCalculator::STATUS_INACTIVE) {
                    continue;
                }

                foreach ($rows as $row) {
                    $assignment = $assignments->get($row['assignment_id']);
                    if ($assignment === null) {
                        continue;
                    }

                    $fired += $this->reconcile($user, $assignment, $row, $status);
                }
            }
        });

        $this->info("Watchdog complete. {$fired} notification(s) fired.");

        return self::SUCCESS;
    }

    /**
     * Compare freshly computed status against last-seen, fire on a
     * qualifying transition, persist the new status. Returns 1 if a
     * notification fired, else 0.
     *
     * @param  array<string, mixed>  $row
     */
    private function reconcile(User $user, Assignment $assignment, array $row, string $status): int
    {
        $state = AssignmentNotificationState::where('assignment_id', $assignment->id)->first();
        $fired = 0;

        if ($status !== $state?->last_seen_status) {
            if ($status === UserComplianceCalculator::STATUS_DUE_SOON) {
                $user->notify(new AssignmentDueSoon(
                    $assignment,
                    $row['next_due_date'],
                    $row['days_until_due'],
                ));
                $fired = 1;
            } elseif ($status === UserComplianceCalculator::STATUS_OVERDUE) {
                $user->notify(new AssignmentOverdue(
                    $assignment,
                    $row['next_due_date'],
                    $row['days_until_due'],
                ));
                $fired = 1;
            }
        }

        if ($state === null) {
            AssignmentNotificationState::create([
                'org_id' => $assignment->org_id,
                'assignment_id' => $assignment->id,
                'last_seen_status' => $status,
            ]);
        } elseif ($state->last_seen_status !== $status) {
            $state->update(['last_seen_status' => $status]);
        }

        return $fired;
    }
}
