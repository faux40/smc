<?php

namespace Tests\Feature\Notifications;

use App\Models\AssignmentNotificationState;
use App\Models\Organization;
use App\Models\TrainingAssignment;
use App\Models\User;
use App\Notifications\AssignmentDueSoon;
use App\Notifications\AssignmentOverdue;
use App\Notifications\AssignmentOverdueSupervisor;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * F10 Part B — the manual "Remind" endpoint (single + bulk) and its
 * supervisor-escalation rules.
 */
class AssignmentRemindTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);

        $this->org = Organization::factory()->create();
        $this->manager = User::factory()->for($this->org, 'organization')->withRole('Manager')->create();
    }

    private function employeeWithSupervisor(): array
    {
        $supervisor = User::factory()->for($this->org, 'organization')->create();
        $employee = User::factory()->for($this->org, 'organization')->create(['supervisor_id' => $supervisor->id]);

        return [$employee, $supervisor];
    }

    private function ta(User $user, string $bucket): TrainingAssignment
    {
        $dates = match ($bucket) {
            'overdue' => ['last_completed_at' => now()->subDays(400), 'expires_at' => now()->subDays(10)],
            'due_soon' => ['last_completed_at' => now()->subDays(5), 'expires_at' => now()->addDays(10)],
            'current' => ['last_completed_at' => now()->subDays(5), 'expires_at' => now()->addDays(300)],
            'not_started' => ['last_completed_at' => null, 'expires_at' => null],
        };

        return TrainingAssignment::factory()->create([
            'org_id' => $this->org->id,
            'user_id' => $user->id,
            ...$dates,
        ]);
    }

    private function remind(TrainingAssignment $ta): TestResponse
    {
        return $this->actingAs($this->manager)
            ->postJson(route('assignments.remind', $ta));
    }

    public function test_remind_overdue_notifies_user_and_supervisor(): void
    {
        [$employee, $supervisor] = $this->employeeWithSupervisor();
        $ta = $this->ta($employee, 'overdue');

        Notification::fake();

        $this->remind($ta)
            ->assertOk()
            ->assertJson(['sent' => true, 'status' => 'overdue', 'supervisor_notified' => true]);

        Notification::assertSentToTimes($employee, AssignmentOverdue::class, 1);
        Notification::assertSentToTimes($supervisor, AssignmentOverdueSupervisor::class, 1);
    }

    public function test_remind_overdue_without_supervisor_skips_supervisor(): void
    {
        $employee = User::factory()->for($this->org, 'organization')->create(['supervisor_id' => null]);
        $ta = $this->ta($employee, 'overdue');

        Notification::fake();

        $this->remind($ta)
            ->assertOk()
            ->assertJson(['sent' => true, 'status' => 'overdue', 'supervisor_notified' => false]);

        Notification::assertSentToTimes($employee, AssignmentOverdue::class, 1);
    }

    public function test_remind_due_soon_notifies_user_only(): void
    {
        [$employee, $supervisor] = $this->employeeWithSupervisor();
        $ta = $this->ta($employee, 'due_soon');

        Notification::fake();

        $this->remind($ta)
            ->assertOk()
            ->assertJson(['sent' => true, 'status' => 'due_soon', 'supervisor_notified' => false]);

        Notification::assertSentToTimes($employee, AssignmentDueSoon::class, 1);
        Notification::assertNotSentTo($supervisor, AssignmentOverdueSupervisor::class);
        Notification::assertNotSentTo($employee, AssignmentOverdue::class);
    }

    public function test_remind_not_started_sends_generic_nudge(): void
    {
        [$employee] = $this->employeeWithSupervisor();
        $ta = $this->ta($employee, 'not_started');

        Notification::fake();

        $this->remind($ta)
            ->assertOk()
            ->assertJson(['sent' => true, 'status' => 'not_started', 'supervisor_notified' => false]);

        Notification::assertSentToTimes($employee, AssignmentDueSoon::class, 1);
    }

    public function test_remind_current_returns_422_and_sends_nothing(): void
    {
        [$employee] = $this->employeeWithSupervisor();
        $ta = $this->ta($employee, 'current');

        Notification::fake();

        $this->remind($ta)
            ->assertStatus(422)
            ->assertJson(['status' => 'current']);

        Notification::assertNothingSent();
    }

    public function test_remind_updates_last_notified_at(): void
    {
        [$employee] = $this->employeeWithSupervisor();
        $ta = $this->ta($employee, 'overdue');

        Notification::fake();
        $this->remind($ta)->assertOk();

        $state = AssignmentNotificationState::where('training_assignment_id', $ta->id)->first();
        $this->assertNotNull($state);
        $this->assertNotNull($state->last_notified_at);
    }

    public function test_remind_cross_org_ta_is_404(): void
    {
        $otherOrg = Organization::factory()->create();
        $otherUser = User::factory()->for($otherOrg, 'organization')->create();
        $ta = TrainingAssignment::factory()->create([
            'org_id' => $otherOrg->id,
            'user_id' => $otherUser->id,
            'last_completed_at' => now()->subDays(400),
            'expires_at' => now()->subDays(10),
        ]);

        Notification::fake();

        $this->actingAs($this->manager)
            ->postJson(route('assignments.remind', $ta))
            ->assertNotFound();

        Notification::assertNothingSent();
    }

    public function test_remind_requires_manager_role(): void
    {
        [$employee] = $this->employeeWithSupervisor();
        $ta = $this->ta($employee, 'overdue');
        $plain = User::factory()->for($this->org, 'organization')->create();

        Notification::fake();

        $this->actingAs($plain)
            ->postJson(route('assignments.remind', $ta))
            ->assertForbidden();

        Notification::assertNothingSent();
    }

    // -- Bulk ----------------------------------------------------------

    public function test_bulk_remind_returns_tallies(): void
    {
        [$emp1, $sup1] = $this->employeeWithSupervisor();
        [$emp2] = $this->employeeWithSupervisor();
        $emp3 = User::factory()->for($this->org, 'organization')->create(['supervisor_id' => null]);

        $overdueWithSup = $this->ta($emp1, 'overdue');   // reminded + supervisor
        $overdueNoSup = $this->ta($emp3, 'overdue');     // reminded, no supervisor
        $dueSoon = $this->ta($emp2, 'due_soon');         // reminded, no supervisor
        $current = $this->ta($emp1, 'current');          // skipped

        // A cross-org id that should be silently skipped.
        $otherOrg = Organization::factory()->create();
        $foreign = TrainingAssignment::factory()->create([
            'org_id' => $otherOrg->id,
            'user_id' => User::factory()->for($otherOrg, 'organization')->create()->id,
        ]);

        Notification::fake();

        $this->actingAs($this->manager)
            ->postJson(route('assignments.remind-bulk'), [
                'training_assignment_ids' => [
                    $overdueWithSup->id, $overdueNoSup->id, $dueSoon->id, $current->id, $foreign->id,
                ],
            ])
            ->assertOk()
            ->assertJson([
                'reminded_count' => 3,
                'skipped_count' => 2, // current + foreign
                'supervisors_notified_count' => 1,
            ]);

        Notification::assertSentToTimes($sup1, AssignmentOverdueSupervisor::class, 1);
    }

    public function test_bulk_remind_requires_manager_role(): void
    {
        [$employee] = $this->employeeWithSupervisor();
        $ta = $this->ta($employee, 'overdue');
        $plain = User::factory()->for($this->org, 'organization')->create();

        $this->actingAs($plain)
            ->postJson(route('assignments.remind-bulk'), [
                'training_assignment_ids' => [$ta->id],
            ])
            ->assertForbidden();
    }

    public function test_bulk_remind_validates_ids_present(): void
    {
        $this->actingAs($this->manager)
            ->postJson(route('assignments.remind-bulk'), ['training_assignment_ids' => []])
            ->assertStatus(422);
    }
}
