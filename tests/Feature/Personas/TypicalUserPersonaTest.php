<?php

namespace Tests\Feature\Personas;

use App\Models\AssignmentSource;
use App\Models\Completion;
use App\Models\Requirement;
use App\Models\RqmtElement;
use App\Models\Training;
use App\Models\TrainingAssignment;
use App\Models\User;
use PHPUnit\Framework\Attributes\Group;

/**
 * Persona: the typical user (SelfView / SelfEdit), including the
 * brand-new hire with nothing assigned. They log in to answer "what do
 * *I* owe, and when?" — their trainings and statuses, their completion
 * history and certs, their own profile. They are not staff: nobody
 * else's compliance, no authoring, no org dashboard.
 */
#[Group('persona')]
#[Group('persona-user')]
class TypicalUserPersonaTest extends PersonaTestCase
{
    public function test_brand_new_hire_sees_their_page_with_nothing_assigned_yet(): void
    {
        $hire = $this->actor('SelfView');

        $this->actingAs($hire)
            ->get("/users/{$hire->id}")
            ->assertOk();

        $payload = $this->actingAs($hire)
            ->getJson("/api/users/{$hire->id}/training-compliance")
            ->assertOk()
            ->json();

        foreach ($payload['groups'] as $bucket => $rows) {
            $this->assertSame([], $rows, "New hire should have nothing in '{$bucket}'.");
        }
        $this->assertSame([], $payload['completions']);
    }

    public function test_sees_their_own_trainings_with_status_and_due_date(): void
    {
        $user = $this->actor('SelfView');
        $freq = $this->annualFrequency();
        $fallProtection = $this->repeatingTraining('Fall Protection', $freq);
        $forklift = $this->repeatingTraining('Forklift', $freq);

        // Current: completed recently, expires well past the due-soon window.
        $this->assignDirect($user, $fallProtection, [
            'last_completed_at' => now()->subDays(30)->toDateString(),
            'expires_at' => now()->addDays(335)->toDateString(),
        ]);
        // Not started yet.
        $this->assignDirect($user, $forklift);

        $groups = $this->actingAs($user)
            ->getJson("/api/users/{$user->id}/training-compliance")
            ->assertOk()
            ->json('groups');

        $current = collect($groups['current'])->firstWhere('training_name', 'Fall Protection');
        $this->assertNotNull($current, 'Fall Protection should appear under Current.');
        $this->assertSame(now()->addDays(335)->toDateString(), $current['expires_at']);
        $this->assertIsInt($current['days_until_due']);

        $this->assertNotNull(
            collect($groups['not_started'])->firstWhere('training_name', 'Forklift'),
            'Forklift should appear under Not started.',
        );
    }

    public function test_sees_their_own_completion_history_with_cert_details(): void
    {
        $user = $this->actor('SelfView');
        $freq = $this->annualFrequency();
        $training = $this->repeatingTraining('First Aid', $freq);

        Completion::create([
            'org_id' => $this->org->id,
            'user_id' => $user->id,
            'module_type' => Training::class,
            'module_id' => $training->id,
            'completion_date' => now()->subDays(10)->toDateString(),
            'expire_date' => now()->addDays(355)->toDateString(),
            'cert_ident' => 'RC-2026-0042',
        ]);

        $completions = $this->actingAs($user)
            ->getJson("/api/users/{$user->id}/training-compliance")
            ->assertOk()
            ->json('completions');

        $this->assertCount(1, $completions);
        $this->assertSame('First Aid', $completions[0]['training_name']);
        $this->assertSame('RC-2026-0042', $completions[0]['cert_ident']);
        $this->assertSame(now()->subDays(10)->toDateString(), $completions[0]['completion_date']);
    }

    public function test_cannot_see_anyone_elses_compliance_or_detail_page(): void
    {
        $user = $this->actor('SelfView');
        $coworker = $this->actor('SelfView');

        $this->actingAs($user)->get("/users/{$coworker->id}")->assertForbidden();
        $this->actingAs($user)
            ->getJson("/api/users/{$coworker->id}/training-compliance")
            ->assertForbidden();
    }

    public function test_cannot_browse_the_user_directory(): void
    {
        $user = $this->actor('SelfView');

        $this->actingAs($user)->get('/users')->assertForbidden();
        $this->actingAs($user)->getJson('/api/users')->assertForbidden();
    }

    public function test_cannot_author_trainings_requirements_or_assignments(): void
    {
        $user = $this->actor('SelfEdit');
        $peer = $this->actor('SelfView');
        $freq = $this->annualFrequency();
        $training = $this->repeatingTraining('Fall Protection', $freq);

        $this->actingAs($user)
            ->postJson('/api/trainings', [
                'name' => 'Rogue Training',
                'initial_only' => false, 'repeating' => true, 'as_needed' => false,
                'std_freq_id' => $freq->id,
            ])
            ->assertForbidden();

        $this->actingAs($user)
            ->postJson('/api/requirements', ['name' => 'Rogue Requirement'])
            ->assertForbidden();

        $this->actingAs($user)
            ->postJson('/api/training-assignments', [
                'user_id' => $peer->id, 'source_type' => 'direct', 'training_id' => $training->id,
            ])
            ->assertForbidden();

        $this->actingAs($user)
            ->postJson('/api/bulk-training-assignments', [
                'user_ids' => [$peer->id], 'source_type' => 'direct', 'training_id' => $training->id,
            ])
            ->assertForbidden();

        $this->actingAs($user)
            ->postJson('/api/completions', [
                'user_id' => $peer->id,
                'module_type' => Training::class,
                'module_id' => $training->id,
                'completion_date' => now()->toDateString(),
                'rqmt_element_ids' => ['anything'],
            ])
            ->assertForbidden();

        $this->actingAs($user)
            ->postJson('/api/classes', ['name' => 'Rogue Class', 'scheduled_date' => now()->toDateString()])
            ->assertForbidden();
    }

    public function test_cannot_see_the_org_dashboard_data(): void
    {
        $user = $this->actor('SelfView');

        foreach ([
            '/api/dashboard/summary',
            '/api/dashboard/needs-action',
            '/api/dashboard/users-compliance',
            '/api/dashboard/recent-completions',
        ] as $endpoint) {
            $this->actingAs($user)->getJson($endpoint)->assertForbidden();
        }
    }

    public function test_self_edit_user_can_update_their_own_profile(): void
    {
        $user = $this->actor('SelfEdit');

        $this->actingAs($user)
            ->patch('/settings/profile', [
                'f_name' => 'Renamed',
                'l_name' => $user->l_name,
                'email' => $user->email,
            ])
            ->assertRedirect('/settings/profile');

        $this->assertSame('Renamed', $user->fresh()->f_name);
    }

    public function test_cannot_edit_anyone_else(): void
    {
        $user = $this->actor('SelfEdit');
        $coworker = $this->actor('SelfView');

        $this->actingAs($user)
            ->patch("/users/{$coworker->id}", [
                'f_name' => 'Hacked', 'l_name' => 'User',
                'role' => 'Admin', 'status' => 'active',
            ])
            ->assertForbidden();
    }

    public function test_gets_notified_when_a_completion_is_recorded_for_them(): void
    {
        $user = $this->actor('SelfView');
        $admin = $this->actor('Admin');
        $freq = $this->annualFrequency();
        $training = $this->repeatingTraining('Hazmat', $freq);

        // The completion form links at least one requirement element.
        $requirement = Requirement::factory()->for($this->org, 'organization')->create();
        $element = RqmtElement::create([
            'org_id' => $this->org->id,
            'requirement_id' => $requirement->id,
            'module_type' => Training::class,
            'module_id' => $training->id,
            'name' => $training->name,
            'initial_only' => false,
            'repeating' => true,
            'std_freq_id' => $freq->id,
            'as_needed' => false,
        ]);

        $this->actingAs($admin)
            ->postJson('/api/completions', [
                'user_id' => $user->id,
                'module_type' => Training::class,
                'module_id' => $training->id,
                'completion_date' => now()->toDateString(),
                'rqmt_element_ids' => [$element->id],
            ])
            ->assertCreated();

        $inbox = $this->actingAs($user)
            ->getJson('/api/notifications')
            ->assertOk()
            ->json();

        $this->assertGreaterThanOrEqual(1, $inbox['unread_count']);
        $kinds = collect($inbox['items'])->pluck('data.kind');
        $this->assertContains('completion_recorded', $kinds);
    }

    private function assignDirect(User $user, Training $training, array $dates = []): TrainingAssignment
    {
        $ta = TrainingAssignment::create([
            'org_id' => $this->org->id,
            'user_id' => $user->id,
            'training_id' => $training->id,
            'name' => $training->name,
            'last_completed_at' => $dates['last_completed_at'] ?? null,
            'expires_at' => $dates['expires_at'] ?? null,
        ]);

        AssignmentSource::create([
            'training_assignment_id' => $ta->id,
            'sourceable_type' => null,
            'sourceable_id' => null,
            'added_at' => now(),
        ]);

        return $ta;
    }
}
