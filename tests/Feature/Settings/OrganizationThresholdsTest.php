<?php

namespace Tests\Feature\Settings;

use App\Actions\RecalculateTrainingStatus;
use App\Jobs\ResyncOrgTrainingStatus;
use App\Models\Completion;
use App\Models\Organization;
use App\Models\StdFrequency;
use App\Models\Training;
use App\Models\TrainingAssignment;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Phase E0 — per-org training threshold settings.
 *
 * due_soon_days controls the dashboard "due soon" window.
 * expiring_soon_days controls when assignment pills turn amber.
 */
class OrganizationThresholdsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private function ownerOf(Organization $org): User
    {
        return User::factory()->for($org, 'organization')->withRole('Owner')->create();
    }

    // -- Organization model accessors -----------------------------------

    public function test_due_soon_days_returns_default_when_null(): void
    {
        $org = Organization::factory()->create(['training_thresholds' => null]);
        $this->assertSame(Organization::DEFAULT_DUE_SOON_DAYS, $org->dueSoonDays());
    }

    public function test_expiring_soon_days_returns_default_when_null(): void
    {
        $org = Organization::factory()->create(['training_thresholds' => null]);
        $this->assertSame(Organization::DEFAULT_EXPIRING_SOON_DAYS, $org->expiringSoonDays());
    }

    public function test_due_soon_days_reads_custom_value(): void
    {
        $org = Organization::factory()->create([
            'training_thresholds' => ['due_soon_days' => 45],
        ]);
        $this->assertSame(45, $org->dueSoonDays());
    }

    public function test_expiring_soon_days_reads_custom_value(): void
    {
        $org = Organization::factory()->create([
            'training_thresholds' => ['expiring_soon_days' => 7],
        ]);
        $this->assertSame(7, $org->expiringSoonDays());
    }

    // -- Settings edit page --------------------------------------------

    public function test_edit_page_includes_threshold_fields(): void
    {
        $org = Organization::factory()->create([
            'training_thresholds' => ['due_soon_days' => 45, 'expiring_soon_days' => 14],
        ]);
        $owner = $this->ownerOf($org);

        $this->actingAs($owner)
            ->get(route('organization.edit'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('settings/Organization')
                ->where('organization.due_soon_days', 45)
                ->where('organization.expiring_soon_days', 14)
            );
    }

    public function test_edit_page_returns_null_thresholds_when_unset(): void
    {
        $org = Organization::factory()->create(['training_thresholds' => null]);
        $owner = $this->ownerOf($org);

        $this->actingAs($owner)
            ->get(route('organization.edit'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('settings/Organization')
                ->where('organization.due_soon_days', null)
                ->where('organization.expiring_soon_days', null)
            );
    }

    // -- Update endpoint -----------------------------------------------

    public function test_update_persists_both_thresholds(): void
    {
        $org = Organization::factory()->create();
        $owner = $this->ownerOf($org);

        $this->actingAs($owner)
            ->patch(route('organization.update'), [
                'name' => $org->name,
                'timezone' => $org->timezone,
                'due_soon_days' => 45,
                'expiring_soon_days' => 14,
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('organization.edit'));

        $fresh = $org->fresh();
        $this->assertSame(45, $fresh->dueSoonDays());
        $this->assertSame(14, $fresh->expiringSoonDays());
    }

    public function test_update_allows_partial_thresholds(): void
    {
        $org = Organization::factory()->create();
        $owner = $this->ownerOf($org);

        $this->actingAs($owner)
            ->patch(route('organization.update'), [
                'name' => $org->name,
                'timezone' => $org->timezone,
                'due_soon_days' => 45,
            ])
            ->assertSessionHasNoErrors();

        $fresh = $org->fresh();
        $this->assertSame(45, $fresh->dueSoonDays());
        $this->assertSame(Organization::DEFAULT_EXPIRING_SOON_DAYS, $fresh->expiringSoonDays());
    }

    public function test_update_clears_thresholds_when_both_omitted(): void
    {
        $org = Organization::factory()->create([
            'training_thresholds' => ['due_soon_days' => 45, 'expiring_soon_days' => 14],
        ]);
        $owner = $this->ownerOf($org);

        $this->actingAs($owner)
            ->patch(route('organization.update'), [
                'name' => $org->name,
                'timezone' => $org->timezone,
            ])
            ->assertSessionHasNoErrors();

        $fresh = $org->fresh();
        $this->assertNull($fresh->training_thresholds);
        $this->assertSame(Organization::DEFAULT_DUE_SOON_DAYS, $fresh->dueSoonDays());
    }

    public function test_update_rejects_out_of_range_threshold(): void
    {
        $org = Organization::factory()->create();
        $owner = $this->ownerOf($org);

        $this->actingAs($owner)
            ->patch(route('organization.update'), [
                'name' => $org->name,
                'timezone' => $org->timezone,
                'due_soon_days' => 999,
            ])
            ->assertSessionHasErrors('due_soon_days');
    }

    // -- Amber-window change → resync job (F1 follow-up) ----------------

    public function test_changing_expiring_soon_window_dispatches_resync_job(): void
    {
        Queue::fake();

        $org = Organization::factory()->create(['training_thresholds' => ['expiring_soon_days' => 30]]);
        $owner = $this->ownerOf($org);

        $this->actingAs($owner)
            ->patch(route('organization.update'), [
                'name' => $org->name,
                'timezone' => $org->timezone,
                'expiring_soon_days' => 60,
            ])
            ->assertSessionHasNoErrors();

        Queue::assertPushed(
            ResyncOrgTrainingStatus::class,
            fn (ResyncOrgTrainingStatus $job) => $job->orgId === $org->id,
        );
    }

    public function test_non_window_settings_change_does_not_dispatch_resync_job(): void
    {
        Queue::fake();

        $org = Organization::factory()->create(['training_thresholds' => ['expiring_soon_days' => 30]]);
        $owner = $this->ownerOf($org);

        // Rename + retune due_soon (a different threshold) but leave the amber
        // window untouched — nothing to re-materialize.
        $this->actingAs($owner)
            ->patch(route('organization.update'), [
                'name' => 'Renamed Org',
                'timezone' => 'America/New_York',
                'due_soon_days' => 10,
                'expiring_soon_days' => 30,
            ])
            ->assertSessionHasNoErrors();

        Queue::assertNotPushed(ResyncOrgTrainingStatus::class);
    }

    public function test_resync_job_re_materializes_status_for_the_new_window(): void
    {
        $org = Organization::factory()->create(['training_thresholds' => ['expiring_soon_days' => 30]]);
        $freq = StdFrequency::factory()->for($org, 'organization')->create(['repeat_days' => 365]);
        $training = Training::factory()->for($org, 'organization')->create([
            'repeating' => true, 'initial_only' => false, 'as_needed' => false, 'std_freq_id' => $freq->id,
        ]);
        $user = User::factory()->for($org, 'organization')->create();

        $ta = TrainingAssignment::factory()->create([
            'org_id' => $org->id, 'user_id' => $user->id,
            'training_id' => $training->id, 'name' => $training->name,
        ]);

        // Completed 320 days ago on an annual training → expires in 45 days:
        // outside a 30-day amber window (current), inside a 60-day one
        // (due_soon). The completion observer materializes "current" now.
        Completion::factory()->create([
            'org_id' => $org->id, 'user_id' => $user->id,
            'module_type' => Training::class, 'module_id' => $training->id,
            'completion_date' => now()->subDays(320)->toDateString(),
        ]);
        $this->assertSame('current', $ta->fresh()->status);

        $org->update(['training_thresholds' => ['expiring_soon_days' => 60]]);

        (new ResyncOrgTrainingStatus($org->id))->handle(app(RecalculateTrainingStatus::class));

        $this->assertSame('due_soon', $ta->fresh()->status);
    }

    // -- F10 overdue reminder interval ---------------------------------

    public function test_overdue_reminder_interval_accessor_normalises_zero_and_null(): void
    {
        $this->assertNull(Organization::factory()->create([
            'overdue_reminder_interval_days' => null,
        ])->overdueReminderIntervalDays());

        $this->assertNull(Organization::factory()->create([
            'overdue_reminder_interval_days' => 0,
        ])->overdueReminderIntervalDays());

        $this->assertSame(14, Organization::factory()->create([
            'overdue_reminder_interval_days' => 14,
        ])->overdueReminderIntervalDays());
    }

    public function test_edit_page_exposes_overdue_reminder_interval(): void
    {
        $org = Organization::factory()->create(['overdue_reminder_interval_days' => 21]);
        $owner = $this->ownerOf($org);

        $this->actingAs($owner)
            ->get(route('organization.edit'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('organization.overdue_reminder_interval_days', 21)
            );
    }

    public function test_update_persists_overdue_reminder_interval(): void
    {
        $org = Organization::factory()->create();
        $owner = $this->ownerOf($org);

        $this->actingAs($owner)
            ->patch(route('organization.update'), [
                'name' => $org->name,
                'timezone' => $org->timezone,
                'overdue_reminder_interval_days' => 14,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(14, $org->fresh()->overdueReminderIntervalDays());
    }

    public function test_update_normalises_zero_interval_to_null(): void
    {
        $org = Organization::factory()->create(['overdue_reminder_interval_days' => 14]);
        $owner = $this->ownerOf($org);

        $this->actingAs($owner)
            ->patch(route('organization.update'), [
                'name' => $org->name,
                'timezone' => $org->timezone,
                'overdue_reminder_interval_days' => 0,
            ])
            ->assertSessionHasNoErrors();

        $this->assertNull($org->fresh()->overdue_reminder_interval_days);
    }

    public function test_update_rejects_out_of_range_interval(): void
    {
        $org = Organization::factory()->create();
        $owner = $this->ownerOf($org);

        $this->actingAs($owner)
            ->patch(route('organization.update'), [
                'name' => $org->name,
                'timezone' => $org->timezone,
                'overdue_reminder_interval_days' => 999,
            ])
            ->assertSessionHasErrors('overdue_reminder_interval_days');
    }

    // -- Inertia shared org prop ---------------------------------------

    public function test_inertia_shares_org_training_thresholds(): void
    {
        $org = Organization::factory()->create([
            'training_thresholds' => ['due_soon_days' => 45],
        ]);
        $owner = $this->ownerOf($org);

        $this->actingAs($owner)
            ->get(route('organization.edit'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('org')
                ->where('org.training_thresholds.due_soon_days', 45)
            );
    }
}
