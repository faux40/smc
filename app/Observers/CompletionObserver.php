<?php

namespace App\Observers;

use App\Actions\RecalculateTrainingStatus;
use App\Models\Completion;
use App\Models\Training;

class CompletionObserver
{
    public function __construct(
        private RecalculateTrainingStatus $action,
    ) {}

    public function saved(Completion $completion): void
    {
        $this->recalculateIfTraining($completion);
    }

    public function deleted(Completion $completion): void
    {
        $this->recalculateIfTraining($completion);
    }

    public function restored(Completion $completion): void
    {
        $this->recalculateIfTraining($completion);
    }

    private function recalculateIfTraining(Completion $completion): void
    {
        if ($completion->module_type !== Training::class) {
            return;
        }

        // With descendants: this credential may be covering lower trainings in
        // the hierarchy, whose assignments must move in the same breath.
        $this->action->handleWithDescendants(
            $completion->user_id,
            $completion->module_id,
            $completion->org_id,
        );
    }
}
