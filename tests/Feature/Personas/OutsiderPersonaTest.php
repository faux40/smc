<?php

namespace Tests\Feature\Personas;

use App\Models\AssignmentSource;
use App\Models\Completion;
use App\Models\Organization;
use App\Models\Requirement;
use App\Models\StdFrequency;
use App\Models\Tag;
use App\Models\Training;
use App\Models\TrainingAssignment;
use App\Models\TrainingClass;
use App\Models\User;
use PHPUnit\Framework\Attributes\Group;

/**
 * Persona: the outsider — a fully-privileged user of the WRONG org. The
 * security persona: every cross-org id 404s at the binding layer (16.4 —
 * existence is not even confirmed), every listing shows zero foreign
 * data, and writes that reference foreign ids fail validation. Keeps the
 * tenancy guarantees regression-tested from the outside.
 */
#[Group('persona')]
#[Group('persona-outsider')]
class OutsiderPersonaTest extends PersonaTestCase
{
    /** The victim org and its resources. */
    private Organization $victimOrg;

    private User $victimUser;

    private Training $victimTraining;

    private Requirement $victimRequirement;

    private TrainingClass $victimClass;

    private Completion $victimCompletion;

    private TrainingAssignment $victimTa;

    private Tag $victimTag;

    private StdFrequency $victimFreq;

    /** Owner-level actor in a different org — privilege doesn't help. */
    private User $outsider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->victimOrg = Organization::factory()->create(['name' => 'Victim Corp']);
        $this->victimUser = User::factory()->for($this->victimOrg, 'organization')->create([
            'f_name' => 'Vera', 'l_name' => 'Victim',
        ]);
        $this->victimFreq = $this->annualFrequency($this->victimOrg);
        $this->victimTraining = $this->repeatingTraining('Victim Secret Training', $this->victimFreq, $this->victimOrg);
        $this->victimRequirement = Requirement::factory()
            ->for($this->victimOrg, 'organization')
            ->create(['name' => 'Victim Secret Requirement']);
        $this->victimClass = TrainingClass::factory()
            ->for($this->victimOrg, 'organization')
            ->create(['name' => 'Victim Secret Class']);
        $this->victimCompletion = Completion::create([
            'org_id' => $this->victimOrg->id,
            'user_id' => $this->victimUser->id,
            'module_type' => Training::class,
            'module_id' => $this->victimTraining->id,
            'completion_date' => now()->subDays(400)->toDateString(),
            'expire_date' => now()->subDays(35)->toDateString(),
        ]);
        $this->victimTa = TrainingAssignment::create([
            'org_id' => $this->victimOrg->id,
            'user_id' => $this->victimUser->id,
            'training_id' => $this->victimTraining->id,
            'name' => $this->victimTraining->name,
            'last_completed_at' => now()->subDays(400)->toDateString(),
            'expires_at' => now()->subDays(35)->toDateString(),
        ]);
        AssignmentSource::create([
            'training_assignment_id' => $this->victimTa->id,
            'sourceable_type' => null, 'sourceable_id' => null, 'added_at' => now(),
        ]);
        $this->victimTag = Tag::create([
            'org_id' => $this->victimOrg->id,
            'name' => 'Victim Secret Tag', 'color' => '#fee', 'font_color' => '#911',
        ]);

        // $this->org (from PersonaTestCase) is the outsider's own org.
        $this->outsider = $this->actor('Owner');
    }

    public function test_every_cross_org_id_resolves_to_404_not_403(): void
    {
        $probes = [
            ['patchJson', "/api/trainings/{$this->victimTraining->id}", ['name' => 'X', 'initial_only' => false, 'repeating' => true, 'as_needed' => false]],
            ['deleteJson', "/api/trainings/{$this->victimTraining->id}", []],
            ['patchJson', "/api/requirements/{$this->victimRequirement->id}", ['name' => 'X']],
            ['getJson', "/api/requirements/{$this->victimRequirement->id}/elements", []],
            ['getJson', "/api/classes/{$this->victimClass->id}", []],
            ['postJson', "/api/classes/{$this->victimClass->id}/complete", ['completion_date' => now()->toDateString()]],
            ['patchJson', "/api/completions/{$this->victimCompletion->id}", ['completion_date' => now()->toDateString(), 'rqmt_element_ids' => ['x']]],
            ['deleteJson', "/api/training-assignments/{$this->victimTa->id}", []],
            ['patchJson', "/api/tags/{$this->victimTag->id}", ['name' => 'X', 'color' => '#fff', 'font_color' => '#000']],
            ['deleteJson', "/api/std-frequencies/{$this->victimFreq->id}", []],
            ['get', "/users/{$this->victimUser->id}", []],
            ['getJson', "/api/users/{$this->victimUser->id}/training-compliance", []],
            ['get', "/classes/{$this->victimClass->id}", []],
        ];

        foreach ($probes as [$method, $uri, $payload]) {
            $response = $payload === []
                ? $this->actingAs($this->outsider)->{$method}($uri)
                : $this->actingAs($this->outsider)->{$method}($uri, $payload);

            $this->assertSame(
                404,
                $response->status(),
                "Expected 404 for {$method} {$uri}, got {$response->status()} — cross-org ids must not even confirm existence.",
            );
        }
    }

    public function test_no_listing_ever_contains_foreign_data(): void
    {
        $secrets = ['Victim Secret Training', 'Victim Secret Requirement', 'Victim Secret Class', 'Victim Secret Tag', 'Vera'];

        $listings = [
            '/api/trainings',
            '/api/requirements',
            '/api/classes',
            '/api/tags',
            '/api/users',
            '/api/completions',
            '/api/training-assignments',
            '/api/std-frequencies',
            '/api/dashboard/needs-action',
            '/api/dashboard/users-compliance',
            '/api/dashboard/recent-completions',
        ];

        foreach ($listings as $endpoint) {
            $body = $this->actingAs($this->outsider)->getJson($endpoint)->assertOk()->getContent();

            foreach ($secrets as $secret) {
                $this->assertStringNotContainsString(
                    $secret,
                    $body,
                    "{$endpoint} leaked '{$secret}' across orgs.",
                );
            }
            $this->assertStringNotContainsString($this->victimUser->id, $body, "{$endpoint} leaked a foreign user id.");
        }
    }

    public function test_dashboard_counts_only_the_outsiders_own_org(): void
    {
        // The victim org has an overdue assignment; the outsider's org is empty.
        $summary = $this->actingAs($this->outsider)
            ->getJson('/api/dashboard/summary')
            ->assertOk()
            ->json();

        $this->assertSame(0, array_sum($summary['counts']));
        $this->assertSame(0, $summary['total_assignments']);
    }

    public function test_writes_referencing_foreign_ids_fail_validation(): void
    {
        $ownWorker = User::factory()->for($this->org, 'organization')->create();

        // Assign a foreign training / to a foreign user.
        $this->actingAs($this->outsider)
            ->postJson('/api/training-assignments', [
                'user_id' => $ownWorker->id, 'source_type' => 'direct',
                'training_id' => $this->victimTraining->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('training_id');

        $this->actingAs($this->outsider)
            ->postJson('/api/training-assignments', [
                'user_id' => $this->victimUser->id, 'source_type' => 'direct',
                'training_id' => $this->victimTraining->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('user_id');

        // Bulk-assign against a foreign requirement (O1's org-scoped rule).
        $this->actingAs($this->outsider)
            ->postJson('/api/bulk-training-assignments', [
                'user_ids' => [$ownWorker->id], 'source_type' => 'requirement',
                'requirement_id' => $this->victimRequirement->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('requirement_id');

        // Record a completion against a foreign module / for a foreign user.
        $this->actingAs($this->outsider)
            ->postJson('/api/completions', [
                'user_id' => $this->victimUser->id,
                'module_type' => Training::class,
                'module_id' => $this->victimTraining->id,
                'completion_date' => now()->toDateString(),
                'rqmt_element_ids' => ['whatever'],
            ])
            ->assertUnprocessable();

        // Enroll into a class with a foreign student? The class itself 404s
        // first — but a same-org class can't enroll a foreign user either.
        $ownClass = TrainingClass::factory()->for($this->org, 'organization')->create();
        $this->actingAs($this->outsider)
            ->postJson("/api/classes/{$ownClass->id}/enrollments", ['user_id' => $this->victimUser->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('user_id');

        // Nothing leaked into the victim org.
        $this->assertSame(1, TrainingAssignment::query()->withoutGlobalScope('organization')->where('org_id', $this->victimOrg->id)->count());
        $this->assertSame(1, Completion::query()->withoutGlobalScope('organization')->where('org_id', $this->victimOrg->id)->count());
    }

    public function test_unauthenticated_visitors_get_nothing_at_all(): void
    {
        $this->getJson('/api/trainings')->assertUnauthorized();
        $this->getJson('/api/dashboard/summary')->assertUnauthorized();
        $this->getJson("/api/users/{$this->victimUser->id}/training-compliance")->assertUnauthorized();
        $this->get("/users/{$this->victimUser->id}")->assertRedirect('/login');
    }
}
