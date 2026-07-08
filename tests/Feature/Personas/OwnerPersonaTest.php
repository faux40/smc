<?php

namespace Tests\Feature\Personas;

use App\Models\Completion;
use App\Models\Training;
use App\Models\TrainingAssignment;
use App\Models\User;
use App\Notifications\ManagerComplianceDigest;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Group;

/**
 * Persona: the boss — Owner (with Admin/SuperAdmin parity where it
 * matters). Wants the org-wide compliance posture at a glance, manages
 * the people and the org settings, and trusts two guarantees: the Owner
 * role can't be touched, and the weekly digest tells the truth.
 */
#[Group('persona')]
#[Group('persona-owner')]
class OwnerPersonaTest extends PersonaTestCase
{
    public function test_sees_the_org_wide_compliance_summary_at_a_glance(): void
    {
        $owner = $this->actor('Owner');
        $this->seedMixedCompliance();

        $summary = $this->actingAs($owner)
            ->getJson('/api/dashboard/summary')
            ->assertOk()
            ->json();

        $this->assertSame(1, $summary['counts']['overdue']);
        $this->assertSame(1, $summary['counts']['current']);
        $this->assertSame(1, $summary['counts']['not_started']);
        $this->assertSame(3, $summary['total_assignments']);
        $this->assertSame(1, $summary['users_with_overdue']);
    }

    public function test_sees_everything_managers_see(): void
    {
        $owner = $this->actor('Owner');
        $this->seedMixedCompliance();

        $this->assertNotEmpty(
            $this->actingAs($owner)->getJson('/api/dashboard/needs-action')->assertOk()->json('data'),
        );
        $this->assertNotEmpty(
            $this->actingAs($owner)->getJson('/api/dashboard/users-compliance')->assertOk()->json('data'),
        );
        $this->actingAs($owner)->getJson('/api/dashboard/recent-completions')->assertOk();
        $this->actingAs($owner)->get('/users')->assertOk();
    }

    public function test_manages_users_create_promote_disable_enable(): void
    {
        $owner = $this->actor('Owner');

        // Hire someone (new users start as no-login, role None)…
        $this->actingAs($owner)
            ->post('/users', ['f_name' => 'Nina', 'l_name' => 'Newhire'])
            ->assertRedirect('/users');

        $nina = User::query()
            ->withoutGlobalScope('organization')
            ->where('org_id', $this->org->id)
            ->where('f_name', 'Nina')
            ->firstOrFail();
        $this->assertTrue($nina->hasRole('None'));

        // …promote them to Manager…
        $this->actingAs($owner)
            ->patch("/users/{$nina->id}", [
                'f_name' => 'Nina', 'l_name' => 'Newhire',
                'role' => 'Manager', 'status' => 'active',
            ])
            ->assertRedirect('/users');
        $this->assertTrue($nina->fresh()->hasRole('Manager'));

        // …and walk them through disable / enable.
        $this->actingAs($owner)->post("/users/{$nina->id}/disable")->assertRedirect();
        $this->assertSame('disabled', $nina->fresh()->status);
        $this->actingAs($owner)->post("/users/{$nina->id}/enable")->assertRedirect();
        $this->assertSame('active', $nina->fresh()->status);
    }

    public function test_tunes_org_settings_and_the_dashboard_respects_them(): void
    {
        $owner = $this->actor('Owner');
        $freq = $this->annualFrequency();
        $training = $this->repeatingTraining('Fall Protection', $freq);
        $worker = User::factory()->for($this->org, 'organization')->create();

        TrainingAssignment::create([
            'org_id' => $this->org->id, 'user_id' => $worker->id,
            'training_id' => $training->id, 'name' => $training->name,
        ]);

        // A completion 320 days ago on the annual (365-day) training expires in
        // 45 days — the resync recomputes dates from completion history, so this
        // has to be real rather than a hand-set expires_at. Outside the default
        // 30-day amber window, so the worker reads "current" for now.
        Completion::factory()->create([
            'org_id' => $this->org->id, 'user_id' => $worker->id,
            'module_type' => Training::class, 'module_id' => $training->id,
            'completion_date' => now()->subDays(320)->toDateString(),
        ]);

        $before = $this->actingAs($owner)->getJson('/api/dashboard/summary')->json('counts');
        $this->assertSame(0, $before['due_soon']);
        $this->assertSame(1, $before['current']);

        // …until the boss widens the expiring-soon threshold to 60 days. The
        // window change dispatches a resync job (sync queue in tests) that
        // re-materializes the org's statuses, so the dashboard reflects it
        // immediately — no waiting for the nightly watchdog.
        $this->actingAs($owner)
            ->patch('/settings/organization', [
                'name' => $this->org->name,
                'timezone' => 'UTC',
                'expiring_soon_days' => 60,
            ])
            ->assertRedirect('/settings/organization');

        $after = $this->actingAs($owner)->getJson('/api/dashboard/summary')->json('counts');
        $this->assertSame(1, $after['due_soon']);
        $this->assertSame(0, $after['current']);
    }

    public function test_owner_role_protections_hold(): void
    {
        $owner = $this->actor('Owner');
        $this->org->update(['owner_user_id' => $owner->id]);
        $admin = $this->actor('Admin');

        // An admin can't edit the Owner at all.
        $this->actingAs($admin)
            ->patch("/users/{$owner->id}", [
                'f_name' => $owner->f_name, 'l_name' => $owner->l_name,
                'email' => $owner->email, 'role' => 'SelfView', 'status' => 'active',
            ])
            ->assertForbidden();

        // The Owner can edit themselves — but the role field is locked even
        // for them (reassignment is the ownership-transfer flow's job).
        $this->actingAs($owner)
            ->patch("/users/{$owner->id}", [
                'f_name' => $owner->f_name, 'l_name' => $owner->l_name,
                'email' => $owner->email, 'role' => 'Admin', 'status' => 'active',
            ])
            ->assertSessionHasErrors('role');
        $this->assertTrue($owner->fresh()->hasRole('Owner'));

        // Nobody disables or deletes the Owner.
        $this->actingAs($admin)->post("/users/{$owner->id}/disable")->assertForbidden();
        $this->actingAs($admin)->delete("/users/{$owner->id}")->assertForbidden();

        // And the Owner can't self-delete out of the org's only ownership.
        $this->actingAs($owner)
            ->delete('/settings/profile', ['password' => 'password'])
            ->assertForbidden();
    }

    public function test_weekly_digest_reflects_the_orgs_actual_posture(): void
    {
        Notification::fake();

        $owner = $this->actor('Owner');
        $manager = $this->actor('Manager');
        $worker = $this->seedMixedCompliance();

        // Monday 08:00 org-local (UTC) — the digest's send window.
        CarbonImmutable::setTestNow(CarbonImmutable::parse('next monday 08:00', 'UTC'));

        $this->artisan('digests:send-manager-compliance')->assertSuccessful();

        Notification::assertSentTo(
            $owner,
            ManagerComplianceDigest::class,
            function (ManagerComplianceDigest $digest) use ($worker): bool {
                return $digest->summary['counts']['overdue'] === 1
                    && $digest->summary['users_with_overdue'] === 1
                    && collect($digest->topOverdue)->pluck('user_id')->contains($worker->id);
            },
        );
        Notification::assertSentTo($manager, ManagerComplianceDigest::class);

        CarbonImmutable::setTestNow();
    }

    public function test_super_admin_and_admin_share_the_authoring_powers(): void
    {
        $freq = $this->annualFrequency();

        foreach (['SuperAdmin', 'Admin'] as $role) {
            $this->actingAs($this->actor($role))
                ->postJson('/api/trainings', [
                    'name' => "{$role} Training",
                    'initial_only' => false, 'repeating' => true, 'as_needed' => false,
                    'std_freq_id' => $freq->id,
                ])
                ->assertCreated();
        }
    }

    /** One overdue (Wanda), one current, one never-started — returns Wanda. */
    private function seedMixedCompliance(): User
    {
        $freq = $this->annualFrequency();
        $training = $this->repeatingTraining('Fall Protection', $freq);

        $wanda = User::factory()->for($this->org, 'organization')->create(['f_name' => 'Wanda', 'l_name' => 'Worker']);
        $carl = User::factory()->for($this->org, 'organization')->create(['f_name' => 'Carl', 'l_name' => 'Current']);
        $nate = User::factory()->for($this->org, 'organization')->create(['f_name' => 'Nate', 'l_name' => 'New']);

        foreach ([
            [$wanda, now()->subDays(400), now()->subDays(35)],
            [$carl, now()->subDays(30), now()->addDays(335)],
            [$nate, null, null],
        ] as [$user, $completed, $expires]) {
            TrainingAssignment::create([
                'org_id' => $this->org->id,
                'user_id' => $user->id,
                'training_id' => $training->id,
                'name' => $training->name,
                'last_completed_at' => $completed?->toDateString(),
                'expires_at' => $expires?->toDateString(),
            ]);

            if ($completed !== null) {
                Completion::create([
                    'org_id' => $this->org->id,
                    'user_id' => $user->id,
                    'module_type' => Training::class,
                    'module_id' => $training->id,
                    'completion_date' => $completed->toDateString(),
                    'expire_date' => $expires->toDateString(),
                ]);
            }
        }

        return $wanda;
    }
}
