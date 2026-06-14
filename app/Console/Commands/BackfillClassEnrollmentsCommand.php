<?php

namespace App\Console\Commands;

use App\Actions\BackfillClassEnrollments;
use Illuminate\Console\Command;

/**
 * Create missing class_enrollments from completions — fixes imported
 * (TrainingWise) classes that show "Enrolled: 0" with an empty roster
 * because the import created completions but no enrollment rows. Idempotent.
 */
class BackfillClassEnrollmentsCommand extends Command
{
    protected $signature = 'tw:backfill-enrollments {--org= : Limit to one organization id}';

    protected $description = 'Create missing class_enrollments from completions (fixes imported classes showing Enrolled: 0).';

    public function handle(BackfillClassEnrollments $action): int
    {
        $created = $action->handle($this->option('org') ?: null);

        $this->info("Backfilled {$created} class enrollment(s) from completions.");

        return self::SUCCESS;
    }
}
