<?php

namespace Tests\Feature\Seeding;

use App\Models\Completion;
use App\Models\Organization;
use App\Models\Requirement;
use App\Models\Training;
use App\Models\TrainingAssignment;
use App\Models\TrainingClass;
use App\Models\User;
use App\Services\TrainingStatusService;
use Database\Seeders\DevDataSeeder;
use Database\Seeders\DevScenarioSeeder;
use Database\Seeders\DevSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Invariant tests for DevScenarioSeeder (Phase N). Like DevDataSeederTest,
 * asserts shapes and the named scenario cases — not faker-rotated names.
 */
class DevScenarioSeederTest extends TestCase
{
    use RefreshDatabase;

    private const ORG_NAME = 'BG';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(DevSeeder::class);
        $this->seed(DevDataSeeder::class);
        $this->seed(DevScenarioSeeder::class);
    }

    private function bgOrg(): Organization
    {
        return Organization::query()
            ->withoutGlobalScope('organization')
            ->where('name', self::ORG_NAME)
            ->firstOrFail();
    }

    /** @return Collection<int, TrainingClass> */
    private function bgClasses()
    {
        return TrainingClass::query()
            ->withoutGlobalScope('organization')
            ->where('org_id', $this->bgOrg()->id)
            ->with(['classTrainings', 'enrollments'])
            ->get();
    }

    public function test_seeds_two_scheduled_future_classes_and_three_completed_ones(): void
    {
        $classes = $this->bgClasses();

        $scheduled = $classes->where('status', 'scheduled');
        $completed = $classes->where('status', 'completed');

        $this->assertCount(2, $scheduled);
        $this->assertCount(3, $completed);

        foreach ($scheduled as $class) {
            $this->assertTrue(
                $class->scheduled_date->toDateString() > now()->toDateString(),
                "Scheduled class {$class->name} should be in the future.",
            );
            $this->assertGreaterThan(0, $class->enrollments->count());
        }

        foreach ($completed as $class) {
            $this->assertNotNull($class->completion_date);
            $this->assertNotNull($class->completed_at);
        }
    }

    public function test_every_class_topic_has_hours_set(): void
    {
        foreach ($this->bgClasses() as $class) {
            $this->assertGreaterThan(0, $class->classTrainings->count());

            foreach ($class->classTrainings as $ct) {
                $this->assertNotNull(
                    $ct->hours,
                    "Topic {$ct->training_name} in {$class->name} should have hours.",
                );
            }

            $this->assertGreaterThan(0, (float) $class->total_hours);
        }
    }

    public function test_completed_rosters_cover_passed_partial_and_incomplete(): void
    {
        $statuses = $this->bgClasses()
            ->where('status', 'completed')
            ->flatMap(fn (TrainingClass $c) => $c->enrollments->pluck('status'))
            ->unique()
            ->values()
            ->all();

        foreach (['passed', 'partial', 'incomplete'] as $status) {
            $this->assertContains($status, $statuses);
        }
    }

    public function test_close_out_went_through_the_real_path_and_issued_certs(): void
    {
        $org = $this->bgOrg();

        $ctIds = $this->bgClasses()
            ->where('status', 'completed')
            ->flatMap(fn (TrainingClass $c) => $c->classTrainings->pluck('id'));

        $classCompletions = Completion::query()
            ->withoutGlobalScope('organization')
            ->where('org_id', $org->id)
            ->whereIn('class_training_id', $ctIds)
            ->get();

        $this->assertGreaterThan(0, $classCompletions->count());

        foreach ($classCompletions as $completion) {
            // CompleteClass stamps cert id + the topic's snapshot hours.
            $this->assertNotNull($completion->cert_id);
            $this->assertMatchesRegularExpression('/^CERT\d{8}-\d{3}$/', $completion->cert_id);
            $this->assertNotNull($completion->hours);
        }
    }

    public function test_multi_source_user_gets_the_strictest_frequency(): void
    {
        $org = $this->bgOrg();

        $victor = User::query()
            ->withoutGlobalScope('organization')
            ->where('org_id', $org->id)
            ->where('f_name', 'Victor')->where('l_name', 'Reyes')
            ->firstOrFail();

        $fallProtection = Training::query()
            ->withoutGlobalScope('organization')
            ->where('org_id', $org->id)
            ->where('name', 'Fall Protection')
            ->firstOrFail();

        $ta = TrainingAssignment::query()
            ->withoutGlobalScope('organization')
            ->where('user_id', $victor->id)
            ->where('training_id', $fallProtection->id)
            ->with('activeSources')
            ->firstOrFail();

        // Direct + two requirement sources on one TA.
        $this->assertCount(3, $ta->activeSources);
        $this->assertCount(1, $ta->activeSources->whereNull('sourceable_type'));

        $reqNames = Requirement::query()
            ->withoutGlobalScope('organization')
            ->whereIn('id', $ta->activeSources->pluck('sourceable_id')->filter())
            ->pluck('name')
            ->sort()
            ->values()
            ->all();
        $this->assertSame(['Roofing Crew', 'Tower Rescue'], $reqNames);

        // Completion was 30 days ago with no explicit expiry; the Quarterly
        // (90d) element must beat Semi-Annual (180d) and the Annual template.
        $this->assertNotNull($ta->last_completed_at);
        $this->assertSame(
            $ta->last_completed_at->addDays(90)->toDateString(),
            $ta->expires_at->toDateString(),
        );
    }

    public function test_retroactive_completion_credits_the_later_assignment(): void
    {
        $org = $this->bgOrg();

        $priya = User::query()
            ->withoutGlobalScope('organization')
            ->where('org_id', $org->id)
            ->where('f_name', 'Priya')->where('l_name', 'Natarajan')
            ->firstOrFail();

        $loto = Training::query()
            ->withoutGlobalScope('organization')
            ->where('org_id', $org->id)
            ->where('name', 'Lockout/Tagout')
            ->firstOrFail();

        $completion = Completion::query()
            ->withoutGlobalScope('organization')
            ->where('user_id', $priya->id)
            ->where('module_type', Training::class)
            ->where('module_id', $loto->id)
            ->firstOrFail();

        $ta = TrainingAssignment::query()
            ->withoutGlobalScope('organization')
            ->where('user_id', $priya->id)
            ->where('training_id', $loto->id)
            ->firstOrFail();

        // The pre-existing completion credits the assignment retroactively
        // (Annual template → completion + 365d).
        $this->assertSame(
            $completion->completion_date->toDateString(),
            $ta->last_completed_at?->toDateString(),
        );
        $this->assertSame(
            $completion->completion_date->addDays(365)->toDateString(),
            $ta->expires_at?->toDateString(),
        );
    }

    public function test_every_dashboard_bucket_is_populated(): void
    {
        $summary = app(TrainingStatusService::class)->orgSummary($this->bgOrg());

        foreach (TrainingStatusService::STATUSES as $bucket) {
            $this->assertGreaterThan(
                0,
                $summary['counts'][$bucket],
                "Dashboard bucket '{$bucket}' should be populated after seeding.",
            );
        }
    }

    public function test_new_scenario_entities_are_tagged(): void
    {
        $org = $this->bgOrg();

        foreach (['Roofing Crew', 'Tower Rescue'] as $name) {
            $req = Requirement::query()
                ->withoutGlobalScope('organization')
                ->where('org_id', $org->id)
                ->where('name', $name)
                ->firstOrFail();
            $this->assertGreaterThan(0, $req->tags()->count(), "{$name} should carry tags.");
        }

        $victor = User::query()
            ->withoutGlobalScope('organization')
            ->where('org_id', $org->id)
            ->where('f_name', 'Victor')->where('l_name', 'Reyes')
            ->firstOrFail();
        $this->assertGreaterThan(0, $victor->tags()->count());
    }

    public function test_seeder_is_idempotent(): void
    {
        $this->seed(DevScenarioSeeder::class);
        $this->seed(DevScenarioSeeder::class);

        $org = $this->bgOrg();

        $this->assertSame(
            5,
            TrainingClass::query()
                ->withoutGlobalScope('organization')
                ->where('org_id', $org->id)
                ->count(),
        );
        $this->assertSame(
            1,
            User::query()
                ->withoutGlobalScope('organization')
                ->where('org_id', $org->id)
                ->where('f_name', 'Victor')->where('l_name', 'Reyes')
                ->count(),
        );
        $this->assertSame(
            6,
            Requirement::query()
                ->withoutGlobalScope('organization')
                ->where('org_id', $org->id)
                ->count(),
        );
    }
}
