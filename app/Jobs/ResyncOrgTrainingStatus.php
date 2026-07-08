<?php

namespace App\Jobs;

use App\Actions\RecalculateTrainingStatus;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Re-materialize every training assignment's status for one org.
 *
 * The dashboard/compliance rollups read the denormalized
 * training_assignments.status, which bakes in the org's amber (expiring-soon)
 * window. When an owner widens or narrows that window the stored buckets are
 * stale until something recomputes them — previously only the nightly
 * watchdog. This job runs the batched org resync off-request so the change
 * propagates promptly without blocking the settings save.
 */
class ResyncOrgTrainingStatus implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $orgId,
    ) {}

    public function handle(RecalculateTrainingStatus $recalculate): void
    {
        $recalculate->handleAll($this->orgId);
    }
}
