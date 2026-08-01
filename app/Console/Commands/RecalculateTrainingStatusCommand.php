<?php

namespace App\Console\Commands;

use App\Actions\RecalculateTrainingStatus;
use App\Models\Organization;
use Illuminate\Console\Command;

class RecalculateTrainingStatusCommand extends Command
{
    protected $signature = 'training-assignments:recalculate-status
                            {--org= : Limit recalculation to a single org ID}';

    protected $description = 'Recompute expires_at and last_completed_at for all training assignments from completion history';

    public function handle(RecalculateTrainingStatus $action): int
    {
        $orgId = $this->option('org');

        $orgs = $orgId
            ? Organization::where('id', $orgId)->get()
            : Organization::all();

        if ($orgs->isEmpty()) {
            $this->warn('No organizations found.');

            return self::SUCCESS;
        }

        $totalProcessed = 0;

        foreach ($orgs as $org) {
            $result = $action->handleAll($org->id);
            $totalProcessed += $result['processed'];
            $this->line("  {$org->name}: {$result['processed']} pair(s) processed");
        }

        $this->info("Done. {$totalProcessed} total (user, training) pair(s) recalculated.");

        return self::SUCCESS;
    }
}
