<?php

namespace App\Actions;

use App\Models\ClassEnrollment;
use App\Models\Completion;
use App\Models\TrainingClass;
use Illuminate\Database\Eloquent\Collection;

/**
 * Backfill class_enrollments from completions.
 *
 * The TrainingWise import creates classes + completions (certs) but no
 * enrollment rows, so imported classes show "Enrolled: 0" and an empty roster
 * even though people clearly attended. This reconciles that: every user
 * holding a (live) completion tied to one of a class's topics gets an
 * enrollment — status `passed`, since they earned the cert. Idempotent:
 * re-running never duplicates, and an existing enrollment is left untouched.
 * Manual classes (enroll → complete) already have rows, so they're unaffected.
 */
class BackfillClassEnrollments
{
    /**
     * @param  string|null  $orgId  limit to one organization (null = all)
     * @return int number of enrollment rows created
     */
    public function handle(?string $orgId = null): int
    {
        $created = 0;

        TrainingClass::query()
            ->when($orgId, fn ($q) => $q->where('org_id', $orgId))
            ->with('classTrainings:id,class_id')
            ->chunkById(200, function (Collection $classes) use (&$created) {
                foreach ($classes as $class) {
                    $created += $this->backfillClass($class);
                }
            });

        return $created;
    }

    private function backfillClass(TrainingClass $class): int
    {
        $ctIds = $class->classTrainings->pluck('id');

        if ($ctIds->isEmpty()) {
            return 0;
        }

        // Distinct users with a live completion against this class's topics.
        $userIds = Completion::query()
            ->whereIn('class_training_id', $ctIds)
            ->distinct()
            ->pluck('user_id');

        $created = 0;

        foreach ($userIds as $userId) {
            $enrollment = ClassEnrollment::firstOrCreate(
                ['class_id' => $class->id, 'user_id' => $userId],
                ['status' => 'passed'],
            );

            if ($enrollment->wasRecentlyCreated) {
                $created++;
            }
        }

        return $created;
    }
}
