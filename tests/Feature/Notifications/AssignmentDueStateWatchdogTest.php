<?php

namespace Tests\Feature\Notifications;

use App\Actions\RecalculateTrainingStatus;
use App\Models\AssignmentNotificationState;
use App\Models\AssignmentSource;
use App\Models\Completion;
use App\Models\Organization;
use App\Models\Requirement;
use App\Models\RqmtElement;
use App\Models\StdFrequency;
use App\Models\Training;
use App\Models\TrainingAssignment;
use App\Models\User;
use App\Notifications\AssignmentDueSoon;
use App\Notifications\AssignmentOverdue;
use App\Services\TrainingStatusService;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Phase 15.3 — `assignments:scan-due-states` daily watchdog, repointed to
 * the TA engine (J4).
 *
 * The watchdog fires AssignmentDueSoon / AssignmentOverdue only on the
 * *edge transition* into those states, tracked via
 * `assignment_notification_states.last_seen_status` (one row per TA).
 * These tests advance a frozen "now" across runs to drive a training
 * assignment through its status lifecycle and assert the watchdog fires
 * exactly once per transition.
 *
 * Date arithmetic anchor: a requirement-sourced TA on a 90-day element
 * frequency, completed 2026-01-01, so expires_at = 2026-04-01. The org's
 * amber window is pinned to 60 days:
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

        $this->org = Organization::factory()->create([
            'training_thresholds' => ['expiring_soon_days' => 60],
        ]);
        $this->user = User::factory()->for($this->org, 'organization')->create();
        $this->freq90 = StdFrequency::factory()->for($this->org, 'organization')->create([
            'name' => '90-day', 'repeat_days' => 90,
        ]);
        $this->training = Training::factory()->for($this->org, 'organization')->create([
            'repeating' => false, 'initial_only' => false, 'as_needed' => false, 'std_freq_id' => null,
        ]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    /**
     * Build a Requirement + one repeating 90-day element + a TA for the
     * test user sourced by that requirement, statuses flattened.
     */
    private function repeatingTa(): TrainingAssignment
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

        $ta = TrainingAssignment::factory()->create([
            'org_id' => $this->org->id,
            'user_id' => $this->user->id,
            'training_id' => $this->training->id,
        ]);
        AssignmentSource::create([
            'training_assignment_id' => $ta->id,
            'sourceable_type' => Requirement::class,
            'sourceable_id' => $req->id,
            'added_at' => now(),
        ]);
        app(RecalculateTrainingStatus::class)->handle($this->user->id, $this->training->id);

        return $ta->refresh();
    }

    /** As-needed-only TA: visible on the user, never scheduled. */
    private function asNeededTa(): TrainingAssignment
    {
        $req = Requirement::factory()->for($this->org, 'organization')->create();
        RqmtElement::factory()
            ->for($this->org, 'organization')
            ->for($req, 'requirement')
            ->create([
                'module_type' => Training::class,
                'module_id' => $this->training->id,
                'initial_only' => false,
                'repeating' => false,
                'as_needed' => true,
                'std_freq_id' => null,
            ]);

        $ta = TrainingAssignment::factory()->create([
            'org_id' => $this->org->id,
            'user_id' => $this->user->id,
            'training_id' => $this->training->id,
        ]);
        AssignmentSource::create([
            'training_assignment_id' => $ta->id,
            'sourceable_type' => Requirement::class,
            'sourceable_id' => $req->id,
            'added_at' => now(),
        ]);
        app(RecalculateTrainingStatus::class)->handle($this->user->id, $this->training->id);

        return $ta->refresh();
    }

    private function completeOn(string $date): Completion
    {
        // Module-identity credit; the CompletionObserver recalculates the TA.
        return Completion::factory()
            ->for($this->org, 'organization')
            ->for($this->user, 'user')
            ->create([
                'module_type' => Training::class,
                'module_id' => $this->training->id,
                'completion_date' => $date,
                'expire_date' => null,
            ]);
    }

    private function runWatchdogAt(string $now): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse($now.' 06:00:00'));
        $this->artisan('assignments:scan-due-states')->assertSuccessful();
    }

    private function stateFor(TrainingAssignment $ta): ?AssignmentNotificationState
    {
        return AssignmentNotificationState::where('training_assignment_id', $ta->id)->first();
    }

    public function test_current_to_due_soon_fires_once(): void
    {
        $ta = $this->repeatingTa();
        $this->completeOn('2026-01-01');

        Notification::fake();

        $this->runWatchdogAt('2026-01-15');
        Notification::assertNothingSent();
        $this->assertSame(
            TrainingStatusService::STATUS_CURRENT,
            $this->stateFor($ta)->last_seen_status,
        );

        $this->runWatchdogAt('2026-03-01');
        Notification::assertSentToTimes($this->user, AssignmentDueSoon::class, 1);
        $this->assertSame(
            TrainingStatusService::STATUS_DUE_SOON,
            $this->stateFor($ta)->last_seen_status,
        );
    }

    public function test_due_soon_stays_due_soon_is_silent(): void
    {
        $this->repeatingTa();
        $this->completeOn('2026-01-01');

        Notification::fake();

        $this->runWatchdogAt('2026-03-01');
        $this->runWatchdogAt('2026-03-10');

        Notification::assertSentToTimes($this->user, AssignmentDueSoon::class, 1);
    }

    public function test_current_to_overdue_skips_due_soon_notification(): void
    {
        $this->repeatingTa();
        $this->completeOn('2026-01-01');

        Notification::fake();

        $this->runWatchdogAt('2026-01-15');
        $this->runWatchdogAt('2026-05-01');

        Notification::assertSentToTimes($this->user, AssignmentOverdue::class, 1);
        Notification::assertNotSentTo($this->user, AssignmentDueSoon::class);
    }

    public function test_due_soon_to_overdue_fires_overdue(): void
    {
        $ta = $this->repeatingTa();
        $this->completeOn('2026-01-01');

        Notification::fake();

        $this->runWatchdogAt('2026-03-01');
        $this->runWatchdogAt('2026-05-01');

        Notification::assertSentToTimes($this->user, AssignmentDueSoon::class, 1);
        Notification::assertSentToTimes($this->user, AssignmentOverdue::class, 1);
        $this->assertSame(
            TrainingStatusService::STATUS_OVERDUE,
            $this->stateFor($ta)->last_seen_status,
        );
    }

    public function test_overdue_to_current_is_silent(): void
    {
        $ta = $this->repeatingTa();
        $this->completeOn('2026-01-01');

        Notification::fake();

        $this->runWatchdogAt('2026-05-01');
        Notification::assertSentToTimes($this->user, AssignmentOverdue::class, 1);

        // A fresh completion lands — expires_at jumps to 2026-07-30.
        $this->completeOn('2026-05-01');

        $this->runWatchdogAt('2026-05-02');
        // No new notification; overdue → current is a silent transition.
        Notification::assertSentToTimes($this->user, AssignmentOverdue::class, 1);
        Notification::assertNotSentTo($this->user, AssignmentDueSoon::class);
        $this->assertSame(
            TrainingStatusService::STATUS_CURRENT,
            $this->stateFor($ta)->last_seen_status,
        );
    }

    public function test_re_entry_into_due_soon_fires_again(): void
    {
        $ta = $this->repeatingTa();
        $this->completeOn('2026-01-01');

        Notification::fake();

        // current → due_soon (fire #1)
        $this->runWatchdogAt('2026-01-15');
        $this->runWatchdogAt('2026-03-01');

        // A completion lands → expires_at jumps to 2026-05-30 → current.
        $this->completeOn('2026-03-01');
        $this->runWatchdogAt('2026-03-02');
        $this->assertSame(
            TrainingStatusService::STATUS_CURRENT,
            $this->stateFor($ta)->last_seen_status,
        );

        // Time advances back into the window → due_soon again (fire #2).
        $this->runWatchdogAt('2026-04-15');

        Notification::assertSentToTimes($this->user, AssignmentDueSoon::class, 2);
    }

    public function test_not_started_is_tracked_but_silent(): void
    {
        // Assigned, never completed → not_started; no expiry exists, so the
        // watchdog tracks it without firing (the "assigned to you"
        // notification covers the nudge at assignment time).
        $ta = $this->repeatingTa();

        Notification::fake();

        $this->runWatchdogAt('2026-01-15');
        $this->runWatchdogAt('2026-05-01');

        Notification::assertNothingSent();
        $this->assertSame(
            TrainingStatusService::STATUS_NOT_STARTED,
            $this->stateFor($ta)->last_seen_status,
        );
    }

    public function test_as_needed_ta_never_fires_and_creates_no_state_row(): void
    {
        $ta = $this->asNeededTa();

        Notification::fake();

        $this->runWatchdogAt('2026-05-01');

        Notification::assertNothingSent();
        $this->assertNull($this->stateFor($ta));
    }

    public function test_state_row_auto_created_on_first_scan(): void
    {
        $ta = $this->repeatingTa();
        $this->completeOn('2026-01-01');

        $this->assertNull($this->stateFor($ta));

        Notification::fake();
        $this->runWatchdogAt('2026-01-15');

        $state = $this->stateFor($ta);
        $this->assertNotNull($state);
        $this->assertSame($this->org->id, $state->org_id);
        $this->assertSame(TrainingStatusService::STATUS_CURRENT, $state->last_seen_status);
    }

    public function test_same_day_rerun_is_idempotent(): void
    {
        $ta = $this->repeatingTa();
        $this->completeOn('2026-01-01');

        Notification::fake();

        $this->runWatchdogAt('2026-03-01');
        $this->runWatchdogAt('2026-03-01');

        Notification::assertSentToTimes($this->user, AssignmentDueSoon::class, 1);
        $this->assertSame(1, AssignmentNotificationState::where('training_assignment_id', $ta->id)->count());
    }

    public function test_state_table_respects_org_isolation(): void
    {
        // A second org with its own user + TA driven into overdue.
        $org2 = Organization::factory()->create([
            'training_thresholds' => ['expiring_soon_days' => 60],
        ]);
        $user2 = User::factory()->for($org2, 'organization')->create();
        $training2 = Training::factory()->for($org2, 'organization')->create([
            'repeating' => true,
            'std_freq_id' => StdFrequency::factory()->for($org2, 'organization')
                ->create(['repeat_days' => 90])->id,
        ]);
        $ta2 = TrainingAssignment::factory()->create([
            'org_id' => $org2->id,
            'user_id' => $user2->id,
            'training_id' => $training2->id,
        ]);
        AssignmentSource::create([
            'training_assignment_id' => $ta2->id,
            'sourceable_type' => null,
            'sourceable_id' => null,
            'added_at' => now(),
        ]);
        Completion::factory()->for($org2, 'organization')->for($user2, 'user')->create([
            'module_type' => Training::class,
            'module_id' => $training2->id,
            'completion_date' => '2026-01-01',
            'expire_date' => null,
        ]);

        $ta1 = $this->repeatingTa();
        $this->completeOn('2026-01-01');

        Notification::fake();
        $this->runWatchdogAt('2026-05-01');

        $state1 = $this->stateFor($ta1);
        $state2 = AssignmentNotificationState::where('training_assignment_id', $ta2->id)->first();

        $this->assertSame($this->org->id, $state1->org_id);
        $this->assertSame($org2->id, $state2->org_id);
        Notification::assertSentToTimes($this->user, AssignmentOverdue::class, 1);
        Notification::assertSentToTimes($user2, AssignmentOverdue::class, 1);
    }

    public function test_notification_payload_shape(): void
    {
        $ta = $this->repeatingTa();
        $this->completeOn('2026-01-01');

        Notification::fake();
        $this->runWatchdogAt('2026-03-01');

        Notification::assertSentTo($this->user, AssignmentDueSoon::class, function ($notification) use ($ta) {
            $payload = $notification->toArray($this->user);

            return $payload['kind'] === 'assignment_due_soon'
                && $payload['training_assignment_id'] === $ta->id
                && $payload['training_id'] === $ta->training_id
                && $payload['name'] === $ta->name
                && $payload['next_due_date'] === '2026-04-01'
                && $payload['days_until_due'] === 31
                && in_array('database', $notification->via($this->user), true)
                && in_array('broadcast', $notification->via($this->user), true);
        });
    }

    public function test_state_row_cascades_when_ta_is_deleted(): void
    {
        $ta = $this->repeatingTa();
        $this->completeOn('2026-01-01');

        Notification::fake();
        $this->runWatchdogAt('2026-01-15');
        $this->assertNotNull($this->stateFor($ta));

        $ta->delete();

        $this->assertNull($this->stateFor($ta));
    }
}
