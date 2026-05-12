<?php

namespace Tests\Feature\Tenancy;

use App\Models\Assignment;
use App\Models\Completion;
use App\Models\Organization;
use App\Models\Requirement;
use App\Models\RqmtElement;
use App\Models\StdFrequency;
use App\Models\Training;
use App\Models\User;
use App\Services\UserComplianceCalculator;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Comprehensive coverage of UserComplianceCalculator. Per-element status
 * math is the risky path — this asserts every timing combination
 * end-to-end against a deterministic "now" so the test isn't flaky.
 *
 * The calculator is exercised in isolation (not via the HTTP layer);
 * the page-level smoke test in UserDetailPageTest covers the controller +
 * routing.
 */
class UserComplianceTest extends TestCase
{
    use RefreshDatabase;

    private CarbonImmutable $now;
    private Organization $org;
    private User $user;
    private StdFrequency $annual;
    private StdFrequency $monthly;
    private Training $training;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);

        // Anchor "now" so date math stays deterministic across test runs.
        $this->now = CarbonImmutable::create(2026, 5, 12, 12, 0, 0);

        $this->org = Organization::factory()->create();
        $this->user = User::factory()->for($this->org, 'organization')->create();
        $this->annual = StdFrequency::factory()->for($this->org, 'organization')->create([
            'name' => 'Annual', 'repeat_days' => 365,
        ]);
        $this->monthly = StdFrequency::factory()->for($this->org, 'organization')->create([
            'name' => 'Monthly', 'repeat_days' => 30,
        ]);
        $this->training = Training::factory()->for($this->org, 'organization')->create();
    }

    private function calc(int $dueSoonDays = 60): UserComplianceCalculator
    {
        return new UserComplianceCalculator($dueSoonDays);
    }

    /**
     * Helper: build a Requirement + one rqmt_element + an Assignment.
     */
    private function requirementWithElement(array $elementAttrs, array $assignmentAttrs = []): array
    {
        $req = Requirement::factory()->for($this->org, 'organization')->create();
        $element = RqmtElement::factory()
            ->for($this->org, 'organization')
            ->for($req, 'requirement')
            ->state(array_merge([
                'module_type' => Training::class,
                'module_id' => $this->training->id,
            ], $elementAttrs))
            ->create();
        $assignment = Assignment::factory()
            ->for($this->org, 'organization')
            ->for($this->user, 'user')
            ->for($req, 'requirement')
            ->create(array_merge([
                'start_date' => $this->now->subYear()->toDateString(),
                'end_date' => null,
            ], $assignmentAttrs));

        return [$req, $element, $assignment];
    }

    private function completion(RqmtElement $element, string $completionDate, ?string $expireDate = null): Completion
    {
        $c = Completion::factory()
            ->for($this->org, 'organization')
            ->for($this->user, 'user')
            ->state([
                'module_type' => Training::class,
                'module_id' => $this->training->id,
                'completion_date' => $completionDate,
                'expire_date' => $expireDate,
            ])
            ->create();
        $c->rqmtElements()->sync([$element->id]);

        return $c;
    }

    public function test_as_needed_element_is_always_current(): void
    {
        $this->requirementWithElement([
            'initial_only' => false,
            'repeating' => false,
            'as_needed' => true,
        ]);

        $result = $this->calc()->compute($this->user, $this->now);

        $this->assertCount(1, $result['groups']['current']);
        $this->assertSame('current', $result['groups']['current'][0]['status']);
        $this->assertNull($result['groups']['current'][0]['next_due_date']);
    }

    public function test_initial_only_uncompleted_is_overdue_after_start_date(): void
    {
        $this->requirementWithElement([
            'initial_only' => true,
            'repeating' => false,
            'as_needed' => false,
        ], [
            'start_date' => $this->now->subMonths(2)->toDateString(),
        ]);

        $result = $this->calc()->compute($this->user, $this->now);

        $this->assertCount(1, $result['groups']['overdue']);
    }

    public function test_initial_only_with_future_start_is_never_started(): void
    {
        $this->requirementWithElement([
            'initial_only' => true,
            'repeating' => false,
            'as_needed' => false,
        ], [
            'start_date' => $this->now->addDays(30)->toDateString(),
        ]);

        $result = $this->calc()->compute($this->user, $this->now);

        $this->assertCount(1, $result['groups']['never_started']);
    }

    public function test_initial_only_completed_once_is_current_forever(): void
    {
        [, $element] = $this->requirementWithElement([
            'initial_only' => true,
            'repeating' => false,
            'as_needed' => false,
        ]);
        // Completed years ago — initial-only doesn't expire.
        $this->completion($element, $this->now->subYears(5)->toDateString());

        $result = $this->calc()->compute($this->user, $this->now);

        $this->assertCount(1, $result['groups']['current']);
        $this->assertSame(
            $this->now->subYears(5)->toDateString(),
            $result['groups']['current'][0]['last_completion_date'],
        );
    }

    public function test_repeating_with_recent_completion_is_current(): void
    {
        [, $element] = $this->requirementWithElement([
            'initial_only' => false,
            'repeating' => true,
            'std_freq_id' => $this->annual->id,
            'as_needed' => false,
        ]);
        // Completed 1 month ago → next due in ~334 days → outside 60-day window.
        $this->completion($element, $this->now->subDays(30)->toDateString());

        $result = $this->calc()->compute($this->user, $this->now);

        $this->assertCount(1, $result['groups']['current']);
    }

    public function test_repeating_within_60_days_is_due_soon(): void
    {
        [, $element] = $this->requirementWithElement([
            'initial_only' => false,
            'repeating' => true,
            'std_freq_id' => $this->annual->id,
            'as_needed' => false,
        ]);
        // Completed 320 days ago → annual due in 45 days → inside window.
        $this->completion($element, $this->now->subDays(320)->toDateString());

        $result = $this->calc()->compute($this->user, $this->now);

        $this->assertCount(1, $result['groups']['due_soon']);
        $this->assertGreaterThanOrEqual(0, $result['groups']['due_soon'][0]['days_until_due']);
        $this->assertLessThanOrEqual(60, $result['groups']['due_soon'][0]['days_until_due']);
    }

    public function test_repeating_past_due_is_overdue(): void
    {
        [, $element] = $this->requirementWithElement([
            'initial_only' => false,
            'repeating' => true,
            'std_freq_id' => $this->annual->id,
            'as_needed' => false,
        ]);
        // Completed 400 days ago → annual was due 35 days ago.
        $this->completion($element, $this->now->subDays(400)->toDateString());

        $result = $this->calc()->compute($this->user, $this->now);

        $this->assertCount(1, $result['groups']['overdue']);
        $this->assertLessThan(0, $result['groups']['overdue'][0]['days_until_due']);
    }

    public function test_completion_expire_date_overrides_freq_window(): void
    {
        // Recent completion (would otherwise be Current) but with an
        // explicit expire_date in the past → overdue.
        [, $element] = $this->requirementWithElement([
            'initial_only' => false,
            'repeating' => true,
            'std_freq_id' => $this->annual->id,
            'as_needed' => false,
        ]);
        $this->completion(
            $element,
            $this->now->subDays(30)->toDateString(),
            $this->now->subDays(5)->toDateString(), // expired 5 days ago
        );

        $result = $this->calc()->compute($this->user, $this->now);

        $this->assertCount(1, $result['groups']['overdue']);
    }

    public function test_repeating_no_completion_past_start_is_overdue(): void
    {
        $this->requirementWithElement([
            'initial_only' => false,
            'repeating' => true,
            'std_freq_id' => $this->monthly->id,
            'as_needed' => false,
        ], [
            'start_date' => $this->now->subMonths(2)->toDateString(),
        ]);

        $result = $this->calc()->compute($this->user, $this->now);

        $this->assertCount(1, $result['groups']['overdue']);
    }

    public function test_inactive_when_end_date_passed(): void
    {
        $this->requirementWithElement([
            'initial_only' => true,
            'repeating' => false,
            'as_needed' => false,
        ], [
            'start_date' => $this->now->subYears(2)->toDateString(),
            'end_date' => $this->now->subMonths(1)->toDateString(),
        ]);

        $result = $this->calc()->compute($this->user, $this->now);

        $this->assertCount(1, $result['groups']['inactive']);
        // Inactive doesn't pollute overdue even though start was long ago.
        $this->assertCount(0, $result['groups']['overdue']);
    }

    public function test_rollup_is_worst_of_element_statuses(): void
    {
        // One requirement with TWO elements: one current, one overdue.
        // The assignment should roll up to overdue.
        $req = Requirement::factory()->for($this->org, 'organization')->create();
        $currentElement = RqmtElement::factory()
            ->for($this->org, 'organization')
            ->for($req, 'requirement')
            ->state([
                'module_type' => Training::class,
                'module_id' => $this->training->id,
                'as_needed' => true,
                'repeating' => false,
                'initial_only' => false,
            ])
            ->create();
        $overdueElement = RqmtElement::factory()
            ->for($this->org, 'organization')
            ->for($req, 'requirement')
            ->state([
                'module_type' => Training::class,
                'module_id' => $this->training->id,
                'initial_only' => true,
                'repeating' => false,
                'as_needed' => false,
            ])
            ->create();
        Assignment::factory()
            ->for($this->org, 'organization')
            ->for($this->user, 'user')
            ->for($req, 'requirement')
            ->create(['start_date' => $this->now->subMonths(2)->toDateString()]);

        // Touch the as_needed element (always-current) but leave the
        // initial_only element uncompleted (will be overdue).
        $_unusedElement = $currentElement;

        $result = $this->calc()->compute($this->user, $this->now);

        $this->assertCount(1, $result['groups']['overdue']);
        $this->assertCount(0, $result['groups']['current']);
    }

    public function test_completion_crediting_one_element_does_not_satisfy_a_second_element(): void
    {
        // Two requirements; one completion credits requirement A's element
        // only — requirement B's element stays overdue.
        $reqA = Requirement::factory()->for($this->org, 'organization')->create();
        $reqB = Requirement::factory()->for($this->org, 'organization')->create();
        $elementA = RqmtElement::factory()
            ->for($this->org, 'organization')
            ->for($reqA, 'requirement')
            ->state([
                'module_type' => Training::class,
                'module_id' => $this->training->id,
                'initial_only' => true,
                'repeating' => false,
                'as_needed' => false,
            ])
            ->create();
        RqmtElement::factory()
            ->for($this->org, 'organization')
            ->for($reqB, 'requirement')
            ->state([
                'module_type' => Training::class,
                'module_id' => $this->training->id,
                'initial_only' => true,
                'repeating' => false,
                'as_needed' => false,
            ])
            ->create();

        Assignment::factory()->for($this->org, 'organization')->for($this->user, 'user')->for($reqA, 'requirement')->create([
            'start_date' => $this->now->subMonths(2)->toDateString(),
        ]);
        Assignment::factory()->for($this->org, 'organization')->for($this->user, 'user')->for($reqB, 'requirement')->create([
            'start_date' => $this->now->subMonths(2)->toDateString(),
        ]);

        $this->completion($elementA, $this->now->subDays(10)->toDateString());

        $result = $this->calc()->compute($this->user, $this->now);

        // ReqA → current; ReqB → overdue.
        $this->assertCount(1, $result['groups']['current']);
        $this->assertCount(1, $result['groups']['overdue']);
    }

    public function test_completions_section_includes_every_user_completion(): void
    {
        // Two completions; one credits an element, one is unassigned-credit
        // (orphan in terms of current assignments). Both should appear
        // in the completions[] return per the "credit for unassigned"
        // path being preserved.
        [, $element] = $this->requirementWithElement([
            'initial_only' => true,
            'repeating' => false,
            'as_needed' => false,
        ]);
        $this->completion($element, $this->now->subDays(10)->toDateString());

        // Orphan completion — credits an element on a different
        // (unassigned) requirement.
        $otherReq = Requirement::factory()->for($this->org, 'organization')->create();
        $orphanElement = RqmtElement::factory()
            ->for($this->org, 'organization')
            ->for($otherReq, 'requirement')
            ->state([
                'module_type' => Training::class,
                'module_id' => $this->training->id,
                'initial_only' => true,
                'repeating' => false,
                'as_needed' => false,
            ])
            ->create();
        $this->completion($orphanElement, $this->now->subDays(20)->toDateString());

        $result = $this->calc()->compute($this->user, $this->now);

        $this->assertCount(2, $result['completions']);
    }

    public function test_custom_due_soon_window_is_respected(): void
    {
        // With a 14-day window the same scenario from the 60-day test
        // should classify as current.
        [, $element] = $this->requirementWithElement([
            'initial_only' => false,
            'repeating' => true,
            'std_freq_id' => $this->annual->id,
            'as_needed' => false,
        ]);
        // Due in ~45 days.
        $this->completion($element, $this->now->subDays(320)->toDateString());

        $result = $this->calc(14)->compute($this->user, $this->now);

        $this->assertCount(1, $result['groups']['current']);
        $this->assertCount(0, $result['groups']['due_soon']);
    }
}
