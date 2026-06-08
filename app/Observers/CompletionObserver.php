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

        $this->action->handle($completion->user_id, $completion->module_id);
    }
}
