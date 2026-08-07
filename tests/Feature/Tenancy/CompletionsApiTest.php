<?php

namespace Tests\Feature\Tenancy;

use App\Events\CompletionCreated;
use App\Events\CompletionDeleted;
use App\Events\CompletionUpdated;
use App\Models\ClassTraining;
use App\Models\Completion;
use App\Models\Organization;
use App\Models\Requirement;
use App\Models\RqmtElement;
use App\Models\StdFrequency;
use App\Models\Training;
use App\Models\TrainingClass;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class CompletionsApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    /**
     * @return array{org: Organization, admin: User, user: User, training: Training, element: RqmtElement}
     */
    private function scaffold(): array
    {
        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();
        $user = User::factory()->for($org, 'organization')->create();
        $req = Requirement::factory()->for($org, 'organization')->create();
        $training = Training::factory()->for($org, 'organization')->create();
        $element = RqmtElement::factory()
            ->for($org, 'organization')
            ->for($req, 'requirement')
            ->state(['module_type' => Training::class, 'module_id' => $training->id])
            ->create();

        return compact('org', 'admin', 'user', 'training', 'element');
    }

    public function test_admin_can_list_completions(): void
    {
        ['admin' => $admin, 'user' => $user, 'training' => $training, 'element' => $element, 'org' => $org] = $this->scaffold();
        $completion = Completion::factory()
            ->for($org, 'organization')
            ->for($user, 'user')
            ->state(['module_type' => Training::class, 'module_id' => $training->id])
            ->create();
        $completion->rqmtElements()->sync([$element->id]);

        $this->actingAs($admin)
            ->getJson('/api/completions')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.rqmt_element_ids.0', $element->id);
    }

    public function test_manager_can_list(): void
    {
        // Phase 13.2 widened CompletionPolicy: Manager can viewAny + create
        // (matching the AssignmentPolicy widening). Update + delete remain
        // Owner/SA/Admin.
        $org = Organization::factory()->create();
        $manager = User::factory()->for($org, 'organization')->withRole('Manager')->create();

        $this->actingAs($manager)
            ->getJson('/api/completions')
            ->assertOk();
    }

    public function test_selfedit_cannot_list(): void
    {
        // Sanity guard: the widening stops at Manager.
        $org = Organization::factory()->create();
        $self = User::factory()->for($org, 'organization')->withRole('SelfEdit')->create();

        $this->actingAs($self)
            ->getJson('/api/completions')
            ->assertForbidden();
    }

    public function test_list_filters_by_user_id(): void
    {
        ['admin' => $admin, 'training' => $training, 'element' => $element, 'org' => $org] = $this->scaffold();
        $userA = User::factory()->for($org, 'organization')->create();
        $userB = User::factory()->for($org, 'organization')->create();
        $cA = Completion::factory()
            ->for($org, 'organization')
            ->for($userA, 'user')
            ->state(['module_type' => Training::class, 'module_id' => $training->id])
            ->create();
        $cA->rqmtElements()->sync([$element->id]);
        $cB = Completion::factory()
            ->for($org, 'organization')
            ->for($userB, 'user')
            ->state(['module_type' => Training::class, 'module_id' => $training->id])
            ->create();
        $cB->rqmtElements()->sync([$element->id]);

        $this->actingAs($admin)
            ->getJson('/api/completions?user_id='.$userA->id)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.user_id', $userA->id);
    }

    public function test_list_does_not_leak_cross_org(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $adminA = User::factory()->for($orgA, 'organization')->withRole('Admin')->create();
        $userB = User::factory()->for($orgB, 'organization')->create();
        $trainingB = Training::factory()->for($orgB, 'organization')->create();
        Completion::factory()
            ->for($orgB, 'organization')
            ->for($userB, 'user')
            ->state(['module_type' => Training::class, 'module_id' => $trainingB->id])
            ->create();

        $this->actingAs($adminA)
            ->getJson('/api/completions')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    /** Make N completions for the scaffold user, dated N..1 days ago. */
    private function seedCompletions(array $s, int $n): void
    {
        for ($i = 1; $i <= $n; $i++) {
            Completion::factory()
                ->for($s['org'], 'organization')
                ->for($s['user'], 'user')
                ->state([
                    'module_type' => Training::class,
                    'module_id' => $s['training']->id,
                    'completion_date' => now()->subDays($i)->toDateString(),
                    'cert_id' => 'CERT-'.str_pad((string) $i, 3, '0', STR_PAD_LEFT),
                ])
                ->create();
        }
    }

    public function test_paginated_mode_returns_data_and_meta(): void
    {
        $s = $this->scaffold();
        $this->seedCompletions($s, 7);

        $res = $this->actingAs($s['admin'])
            ->getJson('/api/completions?page=1&per_page=3')
            ->assertOk();

        $res->assertJsonCount(3, 'data');
        $res->assertJsonPath('meta.current_page', 1);
        $res->assertJsonPath('meta.per_page', 3);
        $res->assertJsonPath('meta.total', 7);
        $res->assertJsonPath('meta.last_page', 3);
    }

    public function test_pagination_second_page_has_the_remainder(): void
    {
        $s = $this->scaffold();
        $this->seedCompletions($s, 7);

        $this->actingAs($s['admin'])
            ->getJson('/api/completions?page=3&per_page=3')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.current_page', 3);
    }

    public function test_index_always_returns_the_paged_envelope(): void
    {
        $s = $this->scaffold();
        $this->seedCompletions($s, 4);

        // The flat-array contract is gone — every list response is {data, meta}.
        $this->actingAs($s['admin'])
            ->getJson('/api/completions')
            ->assertOk()
            ->assertJsonCount(4, 'data')
            ->assertJsonPath('meta.total', 4);
    }

    public function test_paginated_q_filters_by_cert_id(): void
    {
        $s = $this->scaffold();
        $this->seedCompletions($s, 5); // CERT-001..CERT-005

        $res = $this->actingAs($s['admin'])
            ->getJson('/api/completions?page=1&q=CERT-002')
            ->assertOk();

        $res->assertJsonPath('meta.total', 1);
        $res->assertJsonPath('data.0.cert_id', 'CERT-002');
    }

    public function test_paginated_sort_dir_is_respected(): void
    {
        $s = $this->scaffold();
        $this->seedCompletions($s, 3); // dates: 1,2,3 days ago

        $asc = $this->actingAs($s['admin'])
            ->getJson('/api/completions?page=1&sort=completion_date&dir=asc')
            ->assertOk();
        $desc = $this->actingAs($s['admin'])
            ->getJson('/api/completions?page=1&sort=completion_date&dir=desc')
            ->assertOk();

        // asc → oldest first (3 days ago); desc → newest first (1 day ago).
        $this->assertSame(now()->subDays(3)->toDateString(), $asc->json('data.0.completion_date'));
        $this->assertSame(now()->subDays(1)->toDateString(), $desc->json('data.0.completion_date'));
    }

    public function test_admin_can_create_completion_with_one_element(): void
    {
        ['admin' => $admin, 'user' => $user, 'training' => $training, 'element' => $element] = $this->scaffold();

        $response = $this->actingAs($admin)
            ->postJson('/api/completions', [
                'user_id' => $user->id,
                'module_type' => Training::class,
                'module_id' => $training->id,
                'completion_date' => '2026-05-10',
                'rqmt_element_ids' => [$element->id],
            ])
            ->assertCreated();

        $id = $response->json('id');
        $this->assertDatabaseHas('completion_elements', [
            'completion_id' => $id,
            'rqmt_element_id' => $element->id,
        ]);
    }

    public function test_admin_can_create_completion_satisfying_multiple_elements(): void
    {
        ['admin' => $admin, 'user' => $user, 'training' => $training, 'element' => $elementA, 'org' => $org] = $this->scaffold();
        $req2 = Requirement::factory()->for($org, 'organization')->create();
        $elementB = RqmtElement::factory()
            ->for($org, 'organization')
            ->for($req2, 'requirement')
            ->state(['module_type' => Training::class, 'module_id' => $training->id])
            ->create();

        $response = $this->actingAs($admin)
            ->postJson('/api/completions', [
                'user_id' => $user->id,
                'module_type' => Training::class,
                'module_id' => $training->id,
                'completion_date' => '2026-05-10',
                'rqmt_element_ids' => [$elementA->id, $elementB->id],
            ])
            ->assertCreated();

        $id = $response->json('id');
        $this->assertDatabaseHas('completion_elements', ['completion_id' => $id, 'rqmt_element_id' => $elementA->id]);
        $this->assertDatabaseHas('completion_elements', ['completion_id' => $id, 'rqmt_element_id' => $elementB->id]);
    }

    public function test_manager_can_create_but_not_update_or_delete(): void
    {
        ['user' => $user, 'training' => $training, 'element' => $element, 'org' => $org] = $this->scaffold();
        $manager = User::factory()->for($org, 'organization')->withRole('Manager')->create();

        $created = $this->actingAs($manager)
            ->postJson('/api/completions', [
                'user_id' => $user->id,
                'module_type' => Training::class,
                'module_id' => $training->id,
                'completion_date' => '2026-05-10',
                'rqmt_element_ids' => [$element->id],
            ])
            ->assertCreated()
            ->json();

        // Update + delete still admin-only.
        $this->actingAs($manager)
            ->patchJson("/api/completions/{$created['id']}", [
                'completion_date' => '2026-05-11',
                'rqmt_element_ids' => [$element->id],
            ])
            ->assertForbidden();

        $this->actingAs($manager)
            ->deleteJson("/api/completions/{$created['id']}")
            ->assertForbidden();
    }

    public function test_selfedit_cannot_create(): void
    {
        ['user' => $user, 'training' => $training, 'element' => $element, 'org' => $org] = $this->scaffold();
        $self = User::factory()->for($org, 'organization')->withRole('SelfEdit')->create();

        $this->actingAs($self)
            ->postJson('/api/completions', [
                'user_id' => $user->id,
                'module_type' => Training::class,
                'module_id' => $training->id,
                'completion_date' => '2026-05-10',
                'rqmt_element_ids' => [$element->id],
            ])
            ->assertForbidden();
    }

    public function test_create_rejects_empty_element_list(): void
    {
        ['admin' => $admin, 'user' => $user, 'training' => $training] = $this->scaffold();

        $this->actingAs($admin)
            ->postJson('/api/completions', [
                'user_id' => $user->id,
                'module_type' => Training::class,
                'module_id' => $training->id,
                'completion_date' => '2026-05-10',
                'rqmt_element_ids' => [],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('rqmt_element_ids');
    }

    public function test_create_rejects_cross_org_user(): void
    {
        ['admin' => $admin, 'training' => $training, 'element' => $element] = $this->scaffold();
        $otherOrg = Organization::factory()->create();
        $otherUser = User::factory()->for($otherOrg, 'organization')->create();

        $this->actingAs($admin)
            ->postJson('/api/completions', [
                'user_id' => $otherUser->id,
                'module_type' => Training::class,
                'module_id' => $training->id,
                'completion_date' => '2026-05-10',
                'rqmt_element_ids' => [$element->id],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('user_id');
    }

    public function test_create_rejects_cross_org_module(): void
    {
        ['admin' => $admin, 'user' => $user, 'element' => $element] = $this->scaffold();
        $otherOrg = Organization::factory()->create();
        $otherTraining = Training::factory()->for($otherOrg, 'organization')->create();

        $this->actingAs($admin)
            ->postJson('/api/completions', [
                'user_id' => $user->id,
                'module_type' => Training::class,
                'module_id' => $otherTraining->id,
                'completion_date' => '2026-05-10',
                'rqmt_element_ids' => [$element->id],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('module_id');
    }

    public function test_create_rejects_element_with_mismatched_module(): void
    {
        ['admin' => $admin, 'user' => $user, 'org' => $org] = $this->scaffold();
        $req = Requirement::factory()->for($org, 'organization')->create();
        $trainingA = Training::factory()->for($org, 'organization')->create();
        $trainingB = Training::factory()->for($org, 'organization')->create();
        // Element points at trainingA…
        $elementForA = RqmtElement::factory()
            ->for($org, 'organization')
            ->for($req, 'requirement')
            ->state(['module_type' => Training::class, 'module_id' => $trainingA->id])
            ->create();

        // …but completion claims trainingB.
        $this->actingAs($admin)
            ->postJson('/api/completions', [
                'user_id' => $user->id,
                'module_type' => Training::class,
                'module_id' => $trainingB->id,
                'completion_date' => '2026-05-10',
                'rqmt_element_ids' => [$elementForA->id],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('rqmt_element_ids');
    }

    public function test_create_rejects_cross_org_element(): void
    {
        ['admin' => $admin, 'user' => $user, 'training' => $training] = $this->scaffold();
        $otherOrg = Organization::factory()->create();
        $otherReq = Requirement::factory()->for($otherOrg, 'organization')->create();
        $otherTraining = Training::factory()->for($otherOrg, 'organization')->create();
        $otherElement = RqmtElement::factory()
            ->for($otherOrg, 'organization')
            ->for($otherReq, 'requirement')
            ->state(['module_type' => Training::class, 'module_id' => $otherTraining->id])
            ->create();

        $this->actingAs($admin)
            ->postJson('/api/completions', [
                'user_id' => $user->id,
                'module_type' => Training::class,
                'module_id' => $training->id,
                'completion_date' => '2026-05-10',
                'rqmt_element_ids' => [$otherElement->id],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('rqmt_element_ids');
    }

    public function test_admin_can_update_completion_and_resync_elements(): void
    {
        ['admin' => $admin, 'user' => $user, 'training' => $training, 'element' => $elementA, 'org' => $org] = $this->scaffold();
        $req2 = Requirement::factory()->for($org, 'organization')->create();
        $elementB = RqmtElement::factory()
            ->for($org, 'organization')
            ->for($req2, 'requirement')
            ->state(['module_type' => Training::class, 'module_id' => $training->id])
            ->create();

        $completion = Completion::factory()
            ->for($org, 'organization')
            ->for($user, 'user')
            ->state(['module_type' => Training::class, 'module_id' => $training->id])
            ->create();
        $completion->rqmtElements()->sync([$elementA->id]);

        $this->actingAs($admin)
            ->patchJson("/api/completions/{$completion->id}", [
                'completion_date' => '2026-05-11',
                'notes' => 'updated',
                'rqmt_element_ids' => [$elementB->id],
            ])
            ->assertOk();

        $completion->refresh();
        $this->assertSame('updated', $completion->notes);
        $this->assertEqualsCanonicalizing(
            [$elementB->id],
            $completion->rqmtElements()->pluck('rqmt_elements.id')->all(),
        );
    }

    public function test_update_rejects_element_pointing_at_different_module(): void
    {
        // Even on update, every linked element must still match the
        // completion's pinned module identity.
        ['admin' => $admin, 'user' => $user, 'training' => $training, 'org' => $org, 'element' => $element] = $this->scaffold();
        $req2 = Requirement::factory()->for($org, 'organization')->create();
        $otherTraining = Training::factory()->for($org, 'organization')->create();
        $elementOtherModule = RqmtElement::factory()
            ->for($org, 'organization')
            ->for($req2, 'requirement')
            ->state(['module_type' => Training::class, 'module_id' => $otherTraining->id])
            ->create();

        $completion = Completion::factory()
            ->for($org, 'organization')
            ->for($user, 'user')
            ->state(['module_type' => Training::class, 'module_id' => $training->id])
            ->create();
        $completion->rqmtElements()->sync([$element->id]);

        $this->actingAs($admin)
            ->patchJson("/api/completions/{$completion->id}", [
                'completion_date' => '2026-05-11',
                'rqmt_element_ids' => [$elementOtherModule->id],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('rqmt_element_ids');
    }

    public function test_cross_org_update_blocked(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $adminA = User::factory()->for($orgA, 'organization')->withRole('Admin')->create();
        $userB = User::factory()->for($orgB, 'organization')->create();
        $reqB = Requirement::factory()->for($orgB, 'organization')->create();
        $trainingB = Training::factory()->for($orgB, 'organization')->create();
        $elementB = RqmtElement::factory()
            ->for($orgB, 'organization')
            ->for($reqB, 'requirement')
            ->state(['module_type' => Training::class, 'module_id' => $trainingB->id])
            ->create();
        $completionB = Completion::factory()
            ->for($orgB, 'organization')
            ->for($userB, 'user')
            ->state(['module_type' => Training::class, 'module_id' => $trainingB->id])
            ->create();
        $completionB->rqmtElements()->sync([$elementB->id]);

        $this->actingAs($adminA)
            ->patchJson("/api/completions/{$completionB->id}", [
                'completion_date' => '2026-05-11',
                'rqmt_element_ids' => [$elementB->id],
            ])
            ->assertNotFound();
    }

    public function test_admin_can_soft_delete(): void
    {
        ['admin' => $admin, 'user' => $user, 'training' => $training, 'element' => $element, 'org' => $org] = $this->scaffold();
        $completion = Completion::factory()
            ->for($org, 'organization')
            ->for($user, 'user')
            ->state(['module_type' => Training::class, 'module_id' => $training->id])
            ->create();
        $completion->rqmtElements()->sync([$element->id]);

        $this->actingAs($admin)
            ->deleteJson("/api/completions/{$completion->id}")
            ->assertOk();

        $this->assertSoftDeleted('completions', ['id' => $completion->id]);
    }

    public function test_create_update_delete_broadcast(): void
    {
        Event::fake([CompletionCreated::class, CompletionUpdated::class, CompletionDeleted::class]);

        ['admin' => $admin, 'user' => $user, 'training' => $training, 'element' => $element] = $this->scaffold();

        $created = $this->actingAs($admin)
            ->postJson('/api/completions', [
                'user_id' => $user->id,
                'module_type' => Training::class,
                'module_id' => $training->id,
                'completion_date' => '2026-05-10',
                'rqmt_element_ids' => [$element->id],
            ])
            ->json();
        $this->actingAs($admin)->patchJson("/api/completions/{$created['id']}", [
            'completion_date' => '2026-05-11',
            'rqmt_element_ids' => [$element->id],
        ]);
        $this->actingAs($admin)->deleteJson("/api/completions/{$created['id']}");

        Event::assertDispatched(CompletionCreated::class);
        Event::assertDispatched(CompletionUpdated::class);
        Event::assertDispatched(CompletionDeleted::class);
    }

    // ------------------------------------------------------------------
    // M1 — enriched serialization: training name, hours, source class,
    // effective credits (pivot ∪ module-identity).
    // ------------------------------------------------------------------

    public function test_index_rows_carry_training_name_hours_class_and_effective_credits(): void
    {
        ['admin' => $admin, 'user' => $user, 'training' => $training, 'element' => $elementA, 'org' => $org]
            = $this->scaffold();
        $training->update(['name' => 'CPR']);

        // A second requirement binds the same training — its element is
        // credited by module identity even though it's not pivot-linked.
        $reqB = Requirement::factory()->for($org, 'organization')->create();
        $elementB = RqmtElement::factory()
            ->for($org, 'organization')
            ->for($reqB, 'requirement')
            ->state(['module_type' => Training::class, 'module_id' => $training->id])
            ->create();

        $class = TrainingClass::factory()->for($org, 'organization')
            ->create(['name' => 'June Safety Day']);
        $ct = ClassTraining::factory()->for($class, 'trainingClass')
            ->create(['training_id' => $training->id, 'hours' => 4.5]);

        $completion = Completion::factory()
            ->for($org, 'organization')
            ->for($user, 'user')
            ->state([
                'module_type' => Training::class,
                'module_id' => $training->id,
                'class_training_id' => $ct->id,
                'hours' => 4.5,
            ])
            ->create();
        $completion->rqmtElements()->sync([$elementA->id]);

        $row = $this->actingAs($admin)
            ->getJson('/api/completions')
            ->assertOk()
            ->json('data.0');

        $this->assertSame('CPR', $row['training_name']);
        $this->assertEquals(4.5, $row['hours']);
        $this->assertSame($class->id, $row['class_id']);
        $this->assertSame('June Safety Day', $row['class_name']);
        // Pivot links stay as-entered (form prefill)…
        $this->assertSame([$elementA->id], $row['rqmt_element_ids']);
        // …while effective credits add every module-identity match.
        $this->assertEqualsCanonicalizing(
            [$elementA->id, $elementB->id],
            $row['effective_element_ids'],
        );
    }

    public function test_manual_completion_without_class_has_null_class_and_hours(): void
    {
        ['admin' => $admin, 'user' => $user, 'training' => $training, 'element' => $element, 'org' => $org]
            = $this->scaffold();

        $completion = Completion::factory()
            ->for($org, 'organization')
            ->for($user, 'user')
            ->state(['module_type' => Training::class, 'module_id' => $training->id])
            ->create();
        $completion->rqmtElements()->sync([$element->id]);

        $row = $this->actingAs($admin)
            ->getJson('/api/completions')
            ->assertOk()
            ->json('data.0');

        $this->assertNull($row['class_id']);
        $this->assertNull($row['class_name']);
        $this->assertNull($row['hours']);
    }

    public function test_store_accepts_optional_hours(): void
    {
        ['admin' => $admin, 'user' => $user, 'training' => $training, 'element' => $element] = $this->scaffold();

        $row = $this->actingAs($admin)
            ->postJson('/api/completions', [
                'user_id' => $user->id,
                'module_type' => Training::class,
                'module_id' => $training->id,
                'completion_date' => '2026-05-10',
                'hours' => 2.5,
                'rqmt_element_ids' => [$element->id],
            ])
            ->assertCreated()
            ->json();

        $this->assertEquals(2.5, $row['hours']);
        $this->assertDatabaseHas('completions', ['id' => $row['id'], 'hours' => 2.5]);
    }

    public function test_store_rejects_negative_hours(): void
    {
        ['admin' => $admin, 'user' => $user, 'training' => $training, 'element' => $element] = $this->scaffold();

        $this->actingAs($admin)
            ->postJson('/api/completions', [
                'user_id' => $user->id,
                'module_type' => Training::class,
                'module_id' => $training->id,
                'completion_date' => '2026-05-10',
                'hours' => -1,
                'rqmt_element_ids' => [$element->id],
            ])
            ->assertStatus(422);
    }

    // ------------------------------------------------------------------
    // F9 — expire_date defaults from the training's repeat frequency
    // (completion_date + repeat_days) when the client omits it, so a
    // forgotten field can't silently read as "Current" in the reports.
    // ------------------------------------------------------------------

    /**
     * A scaffold whose training actually repeats on a known frequency (the
     * base scaffold()'s training has repeating=true but no std_freq_id, so
     * it never computes an expiry — that's the "no frequency" case).
     *
     * @return array{org: Organization, admin: User, user: User, training: Training, element: RqmtElement}
     */
    private function scaffoldWithFrequency(int $repeatDays): array
    {
        $s = $this->scaffold();
        $freq = StdFrequency::factory()->for($s['org'], 'organization')->create(['repeat_days' => $repeatDays]);
        $s['training']->update(['repeating' => true, 'std_freq_id' => $freq->id]);

        return $s;
    }

    public function test_store_defaults_expire_date_from_training_frequency(): void
    {
        $s = $this->scaffoldWithFrequency(365);

        $this->actingAs($s['admin'])
            ->postJson('/api/completions', [
                'user_id' => $s['user']->id,
                'module_type' => Training::class,
                'module_id' => $s['training']->id,
                'completion_date' => '2026-06-01',
                'rqmt_element_ids' => [$s['element']->id],
            ])
            ->assertCreated();

        $this->assertSame(
            '2027-06-01',
            Completion::where('user_id', $s['user']->id)->firstOrFail()->expire_date->toDateString(),
        );
    }

    public function test_store_default_expiry_crosses_a_leap_day_correctly(): void
    {
        $s = $this->scaffoldWithFrequency(365);

        $this->actingAs($s['admin'])
            ->postJson('/api/completions', [
                'user_id' => $s['user']->id,
                'module_type' => Training::class,
                'module_id' => $s['training']->id,
                'completion_date' => '2024-01-01',
                'rqmt_element_ids' => [$s['element']->id],
            ])
            ->assertCreated();

        // 2024 is a leap year (366 days) — +365 days from Jan 1 lands one day
        // short of the next Jan 1, proving real day arithmetic (not a naive
        // "same date next year" shortcut) drives the default.
        $this->assertSame(
            '2024-12-31',
            Completion::where('user_id', $s['user']->id)->firstOrFail()->expire_date->toDateString(),
        );
    }

    public function test_store_respects_an_explicit_expire_date(): void
    {
        $s = $this->scaffoldWithFrequency(365);

        $this->actingAs($s['admin'])
            ->postJson('/api/completions', [
                'user_id' => $s['user']->id,
                'module_type' => Training::class,
                'module_id' => $s['training']->id,
                'completion_date' => '2026-06-01',
                'expire_date' => '2026-12-25',
                'rqmt_element_ids' => [$s['element']->id],
            ])
            ->assertCreated();

        $this->assertSame(
            '2026-12-25',
            Completion::where('user_id', $s['user']->id)->firstOrFail()->expire_date->toDateString(),
        );
    }

    public function test_store_leaves_expire_date_null_for_a_no_frequency_training(): void
    {
        // The base scaffold's training repeats but has no std_freq_id set —
        // genuinely no frequency data to compute from.
        ['admin' => $admin, 'user' => $user, 'training' => $training, 'element' => $element] = $this->scaffold();

        $this->actingAs($admin)
            ->postJson('/api/completions', [
                'user_id' => $user->id,
                'module_type' => Training::class,
                'module_id' => $training->id,
                'completion_date' => '2026-06-01',
                'rqmt_element_ids' => [$element->id],
            ])
            ->assertCreated();

        $this->assertNull(Completion::where('user_id', $user->id)->firstOrFail()->expire_date);
    }

    public function test_update_defaults_expire_date_when_cleared(): void
    {
        $s = $this->scaffoldWithFrequency(180);
        $completion = Completion::factory()
            ->for($s['org'], 'organization')
            ->for($s['user'], 'user')
            ->state([
                'module_type' => Training::class,
                'module_id' => $s['training']->id,
                'expire_date' => '2026-01-01',
            ])
            ->create();
        $completion->rqmtElements()->sync([$s['element']->id]);

        $this->actingAs($s['admin'])
            ->patchJson("/api/completions/{$completion->id}", [
                'completion_date' => '2026-06-01',
                // expire_date omitted entirely — should default, not stay null.
                'rqmt_element_ids' => [$s['element']->id],
            ])
            ->assertOk();

        $this->assertSame('2026-11-28', $completion->fresh()->expire_date->toDateString());
    }

    public function test_update_respects_an_explicit_expire_date(): void
    {
        $s = $this->scaffoldWithFrequency(180);
        $completion = Completion::factory()
            ->for($s['org'], 'organization')
            ->for($s['user'], 'user')
            ->state(['module_type' => Training::class, 'module_id' => $s['training']->id])
            ->create();
        $completion->rqmtElements()->sync([$s['element']->id]);

        $this->actingAs($s['admin'])
            ->patchJson("/api/completions/{$completion->id}", [
                'completion_date' => '2026-06-01',
                'expire_date' => '2026-08-15',
                'rqmt_element_ids' => [$s['element']->id],
            ])
            ->assertOk();

        $this->assertSame('2026-08-15', $completion->fresh()->expire_date->toDateString());
    }

    // ------------------------------------------------------------------
    // Q-follow — join-based sort + extended search
    // ------------------------------------------------------------------

    public function test_sort_by_user_orders_by_last_name(): void
    {
        $s = $this->scaffold();
        // Adams comes before Zuniga alphabetically.
        $adams = User::factory()->for($s['org'], 'organization')
            ->state(['f_name' => 'Zoe', 'l_name' => 'Adams'])
            ->create();
        $zuniga = User::factory()->for($s['org'], 'organization')
            ->state(['f_name' => 'Amy', 'l_name' => 'Zuniga'])
            ->create();

        $cAdams = Completion::factory()
            ->for($s['org'], 'organization')
            ->for($adams, 'user')
            ->state(['module_type' => Training::class, 'module_id' => $s['training']->id])
            ->create();
        $cAdams->rqmtElements()->sync([$s['element']->id]);

        $cZuniga = Completion::factory()
            ->for($s['org'], 'organization')
            ->for($zuniga, 'user')
            ->state(['module_type' => Training::class, 'module_id' => $s['training']->id])
            ->create();
        $cZuniga->rqmtElements()->sync([$s['element']->id]);

        $asc = $this->actingAs($s['admin'])
            ->getJson('/api/completions?sort=user&dir=asc')
            ->assertOk();
        $this->assertSame($adams->id, $asc->json('data.0.user_id'));
        $this->assertSame($zuniga->id, $asc->json('data.1.user_id'));

        $desc = $this->actingAs($s['admin'])
            ->getJson('/api/completions?sort=user&dir=desc')
            ->assertOk();
        $this->assertSame($zuniga->id, $desc->json('data.0.user_id'));
    }

    public function test_sort_by_training_name_orders_alphabetically(): void
    {
        $s = $this->scaffold();
        $alpha = Training::factory()->for($s['org'], 'organization')
            ->state(['name' => 'Advanced CPR'])
            ->create();
        $omega = Training::factory()->for($s['org'], 'organization')
            ->state(['name' => 'Zumba Safety'])
            ->create();

        foreach ([$alpha, $omega] as $t) {
            $el = RqmtElement::factory()
                ->for($s['org'], 'organization')
                ->for($s['element']->requirement, 'requirement')
                ->state(['module_type' => Training::class, 'module_id' => $t->id])
                ->create();
            $c = Completion::factory()
                ->for($s['org'], 'organization')
                ->for($s['user'], 'user')
                ->state(['module_type' => Training::class, 'module_id' => $t->id])
                ->create();
            $c->rqmtElements()->sync([$el->id]);
        }

        $asc = $this->actingAs($s['admin'])
            ->getJson('/api/completions?sort=training_name&dir=asc')
            ->assertOk();
        $this->assertSame('Advanced CPR', $asc->json('data.0.training_name'));
        $this->assertSame('Zumba Safety', $asc->json('data.1.training_name'));

        $desc = $this->actingAs($s['admin'])
            ->getJson('/api/completions?sort=training_name&dir=desc')
            ->assertOk();
        $this->assertSame('Zumba Safety', $desc->json('data.0.training_name'));
    }

    public function test_search_q_matches_user_name(): void
    {
        $s = $this->scaffold();
        $target = User::factory()->for($s['org'], 'organization')
            ->state(['f_name' => 'Quentin', 'l_name' => 'Xylophone'])
            ->create();
        $other = User::factory()->for($s['org'], 'organization')
            ->state(['f_name' => 'Plain', 'l_name' => 'Person'])
            ->create();

        foreach ([$target, $other] as $u) {
            $c = Completion::factory()
                ->for($s['org'], 'organization')
                ->for($u, 'user')
                ->state(['module_type' => Training::class, 'module_id' => $s['training']->id])
                ->create();
            $c->rqmtElements()->sync([$s['element']->id]);
        }

        $res = $this->actingAs($s['admin'])
            ->getJson('/api/completions?q=xylophone')
            ->assertOk();

        $this->assertSame(1, $res->json('meta.total'));
        $this->assertSame($target->id, $res->json('data.0.user_id'));
    }

    public function test_search_q_matches_training_name(): void
    {
        $s = $this->scaffold();
        $unique = Training::factory()->for($s['org'], 'organization')
            ->state(['name' => 'UniqueTrainingXYZ'])
            ->create();
        $el = RqmtElement::factory()
            ->for($s['org'], 'organization')
            ->for($s['element']->requirement, 'requirement')
            ->state(['module_type' => Training::class, 'module_id' => $unique->id])
            ->create();
        $c = Completion::factory()
            ->for($s['org'], 'organization')
            ->for($s['user'], 'user')
            ->state(['module_type' => Training::class, 'module_id' => $unique->id])
            ->create();
        $c->rqmtElements()->sync([$el->id]);

        // Also create a completion on the scaffold training — should not match.
        $other = Completion::factory()
            ->for($s['org'], 'organization')
            ->for($s['user'], 'user')
            ->state(['module_type' => Training::class, 'module_id' => $s['training']->id])
            ->create();
        $other->rqmtElements()->sync([$s['element']->id]);

        $res = $this->actingAs($s['admin'])
            ->getJson('/api/completions?q=uniquetrainingxyz')
            ->assertOk();

        $this->assertSame(1, $res->json('meta.total'));
        $this->assertSame($unique->id, $res->json('data.0.module_id'));
    }

    public function test_training_name_resolves_for_trashed_training(): void
    {
        ['admin' => $admin, 'user' => $user, 'training' => $training, 'element' => $element, 'org' => $org]
            = $this->scaffold();
        $training->update(['name' => 'Retired Course']);

        $completion = Completion::factory()
            ->for($org, 'organization')
            ->for($user, 'user')
            ->state(['module_type' => Training::class, 'module_id' => $training->id])
            ->create();
        $completion->rqmtElements()->sync([$element->id]);

        $training->delete();

        $row = $this->actingAs($admin)
            ->getJson('/api/completions')
            ->assertOk()
            ->json('data.0');

        $this->assertSame('Retired Course', $row['training_name']);
    }

    // ------------------------------------------------------------------
    // Driver portability — the trainings join
    //
    // This suite runs on sqlite, which is loosely typed and happily compares a
    // uuid column to a varchar one. Dev and production are Postgres, which
    // refuses `uuid = character varying` outright: `completions.module_id` is a
    // string column by design (so a future module can carry a non-UUID id)
    // while `trainings.id` is uuid. Every completions search returned 500, as
    // did sorting by training name.
    //
    // The two search tests above exercise that join and pass on sqlite, which
    // is exactly how this reached the browser unnoticed. Behaviour cannot catch
    // a driver difference the driver under test does not have, so these assert
    // the SQL shape instead. The uuid side is the one to cast — `module_id::uuid`
    // would throw the moment a non-UUID module exists, which is the case the
    // string column was chosen for in the first place.
    // ------------------------------------------------------------------

    private const CAST_JOIN = 'cast("trainings"."id" as text) = "completions"."module_id"';

    /** Every statement a request ran, concatenated. */
    private function sqlFor(string $url, User $admin): string
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->actingAs($admin)->getJson($url)->assertOk();

        $sql = collect(DB::getQueryLog())->pluck('query')->implode("\n");
        DB::disableQueryLog();

        return $sql;
    }

    public function test_search_casts_the_uuid_side_of_the_trainings_join(): void
    {
        $s = $this->scaffold();

        $this->assertStringContainsStringIgnoringCase(
            self::CAST_JOIN,
            $this->sqlFor('/api/completions?q=anything', $s['admin']),
            'The trainings join must cast the uuid side, or Postgres 500s on every search.',
        );
    }

    public function test_sort_by_training_name_casts_the_uuid_side_too(): void
    {
        // Same join, second trigger — it is added for the sort as well.
        $s = $this->scaffold();

        $this->assertStringContainsStringIgnoringCase(
            self::CAST_JOIN,
            $this->sqlFor('/api/completions?sort=training_name&dir=asc', $s['admin']),
        );
    }

    public function test_the_uncast_comparison_is_gone_entirely(): void
    {
        // Guards against a well-meaning "simplification" back to a bare column
        // comparison, which reads cleaner and breaks production.
        $s = $this->scaffold();

        $this->assertStringNotContainsString(
            '"trainings"."id" = "completions"."module_id"',
            $this->sqlFor('/api/completions?q=anything', $s['admin']),
        );
    }
}
