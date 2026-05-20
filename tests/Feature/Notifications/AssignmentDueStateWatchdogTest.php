<?php

namespace Tests\Feature\Notifications;

use App\Models\Assignment;
use App\Models\AssignmentNotificationState;
use App\Models\Completion;
use App\Models\Organization;
use App\Models\Requirement;
use App\Models\RqmtElement;
use App\Models\StdFrequency;
use App\Models\Training;
use App\Models\User;
use App\Notifications\AssignmentDueSoon;
use App\Notifications\AssignmentOverdue;
use App\Services\UserComplianceCalculator;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Phase 15.3 — `assignments:scan-due-states` daily watchdog.
 *
 * The watchdog fires AssignmentDueSoon / AssignmentOverdue only on the
 * *edge transition* into those states, tracked via
 * `assignment_notification_states.last_seen_status`. These tests advance
 * a frozen "now" across runs to drive an assignment through its status
 * lifecycle and assert the watchdog fires exactly once per transition.
 *
 * Date arithmetic anchor: a single repeating element on a 90-day
 * frequency, completed 2026-01-01, so next_due = 2026-04-01.
 *   now 2026-01-15 → 76 days out  → current
 *   now 2026-03-01 → 31 days out  → due_soon  (≤ 60-day window)
 *   now 2026-05-01 → past         → overdue
 */
class AssignmentDueStateWatchdogTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $user;

    private StdFrequency $freq90;

    private Training $training;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);

        $this->org = Organization::factory()->create();
        $this->user = User::factory()->for($this->org, 'organization')->create();
        $this->freq90 = StdFrequency::factory()->for($this->org, 'organization')->create([
            'name' => '90-day', 'repeat_days' => 90,
        ]);
        $this->training = Training::factory()->for($this->org, 'organization')->create();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    /**
     * Build a Requirement + one repeating rqmt_element + an Assignment
     * for the test user. start_date defaults well in the past.
     */
    private function repeatingAssignment(array $assignmentAttrs = []): Assignment
    {
        $req = Requirement::factory()->for($this->org, 'organization')->create();
        RqmtElement::factory()
            ->for($this->org, 'organization')
            ->for($req, 'requirement')
            ->create([
                'module_type' => Training::class,
                'module_id' => $this->training->id,
                'initial_only' => false,
                'repeating' => true,
                'as_needed' => false,
                'std_freq_id' => $this->freq90->id,
            ]);

        return Assignment::factory()
            ->for($this->org, 'organization')
            ->for($this->user, 'user')
            ->for($req, 'requirement')
            ->create(array_merge([
                'start_date' => '2025-06-01',
                'end_date' => null,
            ], $assignmentAttrs));
    }

    private function completeOn(Assignment $assignment, string $date): Completion
    {
        $element = $assignment->requirement->elements()->first();
        $c = Completion::factory()
            ->for($this->org, 'organization')
            ->for($this->user, 'user')
            ->create([
                'module_type' => Training::class,
                'module_id' => $this->training->id,
                'completion_date' => $date,
                'expire_date' => null,
            ]);
        $c->rqmtElements()->sync([$element->id]);

        return $c;
    }

    private function runWatchdogAt(string $now): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse($now.' 06:00:00'));
        $this->artisan('assignments:scan-due-states')->assertSuccessful();
    }

    private function stateFor(Assignment $assignment): ?AssignmentNotificationState
    {
        return AssignmentNotificationState::where('assignment_id', $assignment->id)->first();
    }

    public function test_current_to_due_soon_fires_once(): void
    {
        $assignment = $this->repeatingAssignment();
        $this->completeOn($assignment, '2026-01-01');

        Notification::fake();

        $this->runWatchdogAt('2026-01-15');
        Notification::assertNothingSent();
        $this->assertSame(
            UserComplianceCalculator::STATUS_CURRENT,
            $this->stateFor($assignment)->last_seen_status,
        );

        $this->runWatchdogAt('2026-03-01');
        Notification::assertSentToTimes($this->user, AssignmentDueSoon::class, 1);
        $this->assertSame(
            UserComplianceCalculator::STATUS_DUE_SOON,
            $this->stateFor($assignment)->last_seen_status,
        );
    }

    public function test_due_soon_stays_due_soon_is_silent(): void
    {
        $assignment = $this->repeatingAssignment();
        $this->completeOn($assignment, '2026-01-01');

        Notification::fake();

        $this->runWatchdogAt('2026-03-01');
        $this->runWatchdogAt('2026-03-10');

        Notification::assertSentToTimes($this->user, AssignmentDueSoon::class, 1);
    }

    public function test_current_to_overdue_skips_due_soon_notification(): void
    {
        $assignment = $this->repeatingAssignment();
        $this->completeOn($assignment, '2026-01-01');

        Notification::fake();

        $this->runWatchdogAt('2026-01-15');
        $this->runWatchdogAt('2026-05-01');

        Notification::assertSentToTimes($this->user, AssignmentOverdue::class, 1);
        Notification::assertNotSentTo($this->user, AssignmentDueSoon::class);
    }

    public function test_due_soon_to_overdue_fires_overdue(): void
    {
        $assignment = $this->repeatingAssignment();
        $this->completeOn($assignment, '2026-01-01');

        Notification::fake();

        $this->runWatchdogAt('2026-03-01');
        $this->runWatchdogAt('2026-05-01');

        Notification::assertSentToTimes($this->user, AssignmentDueSoon::class, 1);
        Notification::assertSentToTimes($this->user, AssignmentOverdue::class, 1);
        $this->assertSame(
            UserComplianceCalculator::STATUS_OVERDUE,
            $this->stateFor($assignment)->last_seen_status,
        );
    }

    public function test_overdue_to_current_is_silent(): void
    {
        $assignment = $this->repeatingAssignment();
        $this->completeOn($assignment, '2026-01-01');

        Notification::fake();

        $this->runWatchdogAt('2026-05-01');
        Notification::assertSentToTimes($this->user, AssignmentOverdue::class, 1);

        // A fresh completion lands — next_due jumps to 2026-07-30.
        $this->completeOn($assignment, '2026-05-01');

        $this->runWatchdogAt('2026-05-02');
        // No new notification; overdue → current is a silent transition.
        Notification::assertSentToTimes($this->user, AssignmentOverdue::class, 1);
        Notification::assertNotSentTo($this->user, AssignmentDueSoon::class);
        $this->assertSame(
            UserComplianceCalculator::STATUS_CURRENT,
            $this->stateFor($assignment)->last_seen_status,
        );
    }

    public function test_re_entry_into_due_soon_fires_again(): void
    {
        $assignment = $this->repeatingAssignment();
        $this->completeOn($assignment, '2026-01-01');

        Notification::fake();

        // current → due_soon (fire #1)
        $this->runWatchdogAt('2026-01-15');
        $this->runWatchdogAt('2026-03-01');

        // A completion lands → next_due jumps to 2026-05-30 → current.
        $this->completeOn($assignment, '2026-03-01');
        $this->runWatchdogAt('2026-03-02');
        $this->assertSame(
            UserComplianceCalculator::STATUS_CURRENT,
            $this->stateFor($assignment)->last_seen_status,
        );

        // Time advances back into the window → due_soon again (fire #2).
        $this->runWatchdogAt('2026-04-15');

        Notification::assertSentToTimes($this->user, AssignmentDueSoon::class, 2);
    }

    public function test_never_started_to_overdue_fires_overdue(): void
    {
        // start_date in the future, no completion → never_started.
        $assignment = $this->repeatingAssignment(['start_date' => '2026-03-01']);

        Notification::fake();

        $this->runWatchdogAt('2026-01-15');
        Notification::assertNothingSent();
        $this->assertSame(
            UserComplianceCalculator::STATUS_NEVER_STARTED,
            $this->stateFor($assignment)->last_seen_status,
        );

        // start_date now in the past, still no completion → overdue.
        $this->runWatchdogAt('2026-04-01');
        Notification::assertSentToTimes($this->user, AssignmentOverdue::class, 1);
    }

    public function test_inactive_assignment_never_fires_and_creates_no_state_row(): void
    {
        // end_date in the past → inactive regardless of completion state.
        $assignment = $this->repeatingAssignment(['end_date' => '2025-12-01']);

        Notification::fake();

        $this->runWatchdogAt('2026-05-01');

        Notification::assertNothingSent();
        $this->assertNull($this->stateFor($assignment));
    }

    public function test_state_row_auto_created_on_first_scan(): void
    {
        $assignment = $this->repeatingAssignment();
        $this->completeOn($assignment, '2026-01-01');

        $this->assertNull($this->stateFor($assignment));

        Notification::fake();
        $this->runWatchdogAt('2026-01-15');

        $state = $this->stateFor($assignment);
        $this->assertNotNull($state);
        $this->assertSame($this->org->id, $state->org_id);
        $this->assertSame(UserComplianceCalculator::STATUS_CURRENT, $state->last_seen_status);
    }

    public function test_same_day_rerun_is_idempotent(): void
    {
        $assignment = $this->repeatingAssignment();
        $this->completeOn($assignment, '2026-01-01');

        Notification::fake();

        $this->runWatchdogAt('2026-03-01');
        $this->runWatchdogAt('2026-03-01');

        Notification::assertSentToTimes($this->user, AssignmentDueSoon::class, 1);
        $this->assertSame(1, AssignmentNotificationState::where('assignment_id', $assignment->id)->count());
    }

    public function test_state_table_respects_org_isolation(): void
    {
        // A second org with its own user + assignment driven into overdue.
        $org2 = Organization::factory()->create();
        $user2 = User::factory()->for($org2, 'organization')->create();
        $freq2 = StdFrequency::factory()->for($org2, 'organization')->create(['repeat_days' => 90]);
        $training2 = Training::factory()->for($org2, 'organization')->create();
        $req2 = Requirement::factory()->for($org2, 'organization')->create();
        RqmtElement::factory()->for($org2, 'organization')->for($req2, 'requirement')->create([
            'module_type' => Training::class,
            'module_id' => $training2->id,
            'initial_only' => false,
            'repeating' => true,
            'as_needed' => false,
            'std_freq_id' => $freq2->id,
        ]);
        $assignment2 = Assignment::factory()
            ->for($org2, 'organization')->for($user2, 'user')->for($req2, 'requirement')
            ->create(['start_date' => '2025-06-01', 'end_date' => null]);

        $assignment1 = $this->repeatingAssignment();
        $this->completeOn($assignment1, '2026-01-01');

        Notification::fake();
        $this->runWatchdogAt('2026-05-01');

        $state1 = $this->stateFor($assignment1);
        $state2 = AssignmentNotificationState::where('assignment_id', $assignment2->id)->first();

        $this->assertSame($this->org->id, $state1->org_id);
        $this->assertSame($org2->id, $state2->org_id);
        Notification::assertSentToTimes($this->user, AssignmentOverdue::class, 1);
        Notification::assertSentToTimes($user2, AssignmentOverdue::class, 1);
    }

    public function test_notification_payload_shape(): void
    {
        $assignment = $this->repeatingAssignment();
        $this->completeOn($assignment, '2026-01-01');

        Notification::fake();
        $this->runWatchdogAt('2026-03-01');

        Notification::assertSentTo($this->user, AssignmentDueSoon::class, function ($notification) use ($assignment) {
            $payload = $notification->toArray($this->user);

            return $payload['kind'] === 'assignment_due_soon'
                && $payload['assignment_id'] === $assignment->id
                && $payload['requirement_id'] === $assignment->requirement_id
                && $payload['name'] === $assignment->name
                && $payload['next_due_date'] === '2026-04-01'
                && $payload['days_until_due'] === 31
                && in_array('database', $notification->via($this->user), true)
                && in_array('broadcast', $notification->via($this->user), true);
        });
    }
}
