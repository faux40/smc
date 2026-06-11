<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Models\User;
use App\Notifications\ManagerComplianceDigest;
use App\Services\TrainingStatusService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;

/**
 * Phase 15.6 — weekly manager compliance digest.
 *
 * Scheduled hourly. Each run, for every org: if it's currently Monday
 * 08:00 in the org's timezone and the digest hasn't already gone out
 * this week, compute the rollup once and fan it out to the org's
 * Owner / SuperAdmin / Admin / Manager users.
 *
 * `organizations.manager_digest_sent_at` guards against a double-send
 * within the matching hour and on manual re-runs — the send only fires
 * when that timestamp is null or older than the start of the current
 * week in the org's timezone.
 *
 * Runs in CLI context with no `currentOrgId` bound, so the org global
 * scope is a no-op and one pass spans every org.
 */
class SendManagerComplianceDigestCommand extends Command
{
    protected $signature = 'digests:send-manager-compliance';

    protected $description = 'Send the weekly compliance digest to manager+ users at Monday 08:00 org-local time.';

    private const MANAGER_PLUS_ROLES = ['Owner', 'SuperAdmin', 'Admin', 'Manager'];

    private const ROLLUP_LIMIT = 10;

    public function handle(TrainingStatusService $status): int
    {
        $sent = 0;

        Organization::query()->each(function (Organization $org) use ($status, &$sent): void {
            $now = CarbonImmutable::now($org->timezone);

            if (! $now->isMonday() || $now->hour !== 8) {
                return;
            }

            // Already sent this week (org-local)? Skip.
            if ($org->manager_digest_sent_at !== null
                && $org->manager_digest_sent_at->gte($now->startOfWeek())) {
                return;
            }

            $recipients = User::query()
                ->where('org_id', $org->id)
                ->role(self::MANAGER_PLUS_ROLES)
                ->get();

            if ($recipients->isEmpty()) {
                return;
            }

            $digest = new ManagerComplianceDigest(
                orgName: $org->name,
                summary: $status->orgSummary($org),
                topOverdue: $status->topOverdueUsers($org, self::ROLLUP_LIMIT),
                topDueSoon: $status->topDueSoon($org, self::ROLLUP_LIMIT),
            );

            Notification::send($recipients, $digest);
            $sent += $recipients->count();

            $org->update(['manager_digest_sent_at' => now()]);
        });

        $this->info("Manager digest run complete. {$sent} notification(s) sent.");

        return self::SUCCESS;
    }
}
