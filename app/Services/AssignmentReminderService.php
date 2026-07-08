<?php

namespace App\Services;

use App\Models\AssignmentNotificationState;
use App\Models\Organization;
use App\Models\TrainingAssignment;
use App\Models\User;
use App\Notifications\AssignmentDueSoon;
use App\Notifications\AssignmentOverdue;
use App\Notifications\AssignmentOverdueSupervisor;
use Illuminate\Support\Facades\Date;

/**
 * F10 — the single home for "nudge the person about this assignment now".
 * Shared by the manual Remind endpoint (deliberate manager nudge) and the
 * nightly watchdog's overdue re-fire, so the escalation rules live in one
 * place:
 *
 *   - overdue      → re-send AssignmentOverdue to the employee AND, when they
 *                    have a supervisor with an email, escalate to the
 *                    supervisor via AssignmentOverdueSupervisor.
 *   - due_soon     → re-send AssignmentDueSoon to the employee only.
 *   - not_started  → send AssignmentDueSoon as a generic "please complete"
 *                    nudge (no expiry yet), employee only.
 *   - current      → nothing to remind (caller reports 422 / skips it).
 *   - as_needed    → nothing to remind (informational assignment).
 *
 * Every send stamps `assignment_notification_states.last_notified_at`, so the
 * watchdog's re-fire clock stays consistent whether the last send came from a
 * scan or a manual remind.
 */
class AssignmentReminderService
{
    public function __construct(private readonly TrainingStatusService $status) {}

    /**
     * Send the reminder appropriate to the assignment's CURRENT status.
     *
     * @return array{status: string, sent: bool, supervisor_notified: bool}
     */
    public function remind(TrainingAssignment $ta): array
    {
        $ta->loadMissing('user.supervisor');
        $user = $ta->user;

        if ($user === null) {
            return ['status' => 'unknown', 'sent' => false, 'supervisor_notified' => false];
        }

        $window = $ta->organization?->expiringSoonDays() ?? Organization::DEFAULT_EXPIRING_SOON_DAYS;
        $bucket = $this->status->statusFor($ta, $window);
        $expiresAt = $ta->expires_at?->toDateString();
        $days = $this->status->daysUntilDue($ta);
        $supervisorNotified = false;

        if ($bucket === TrainingStatusService::STATUS_OVERDUE) {
            $user->notify(new AssignmentOverdue($ta->id, $ta->training_id, $ta->name, $expiresAt, $days));
            $supervisorNotified = $this->notifyOverdueSupervisor($ta, $user);
        } elseif ($bucket === TrainingStatusService::STATUS_DUE_SOON
            || $bucket === TrainingStatusService::STATUS_NOT_STARTED) {
            $user->notify(new AssignmentDueSoon($ta->id, $ta->training_id, $ta->name, $expiresAt, $days));
        } else {
            // current / as_needed — nothing actionable to remind about.
            return ['status' => $bucket, 'sent' => false, 'supervisor_notified' => false];
        }

        $this->touchLastNotified($ta, $bucket);

        return ['status' => $bucket, 'sent' => true, 'supervisor_notified' => $supervisorNotified];
    }

    /**
     * Escalate an overdue assignment to the employee's supervisor, when they
     * have one with an email. Reused by the watchdog re-fire. Returns whether
     * a supervisor notification actually went out.
     */
    public function notifyOverdueSupervisor(TrainingAssignment $ta, ?User $user = null): bool
    {
        $user ??= $ta->user;
        $supervisor = $user?->supervisor;

        if ($supervisor === null || blank($supervisor->email)) {
            return false;
        }

        $supervisor->notify(new AssignmentOverdueSupervisor(
            $user->id,
            $user->name,
            $ta->id,
            $ta->training_id,
            $ta->name,
            $ta->expires_at?->toDateString(),
            $this->status->daysUntilDue($ta),
        ));

        return true;
    }

    /**
     * Stamp the re-fire clock. Preserves an existing row's last_seen_status
     * (watchdog bookkeeping); on first sight it seeds it from the current
     * bucket so the row is well-formed.
     */
    private function touchLastNotified(TrainingAssignment $ta, string $bucket): void
    {
        $state = AssignmentNotificationState::firstOrNew(['training_assignment_id' => $ta->id]);
        $state->org_id = $ta->org_id;
        $state->last_notified_at = Date::now();
        if (! $state->exists) {
            $state->last_seen_status = $bucket;
        }
        $state->save();
    }
}
