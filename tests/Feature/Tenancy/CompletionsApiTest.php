<?php

namespace Tests\Feature\Tenancy;

use App\Events\CompletionCreated;
use App\Events\CompletionDeleted;
use App\Events\CompletionUpdated;
use App\Models\Completion;
use App\Models\Organization;
use App\Models\Requirement;
use App\Models\RqmtElement;
use App\Models\Training;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
            ->assertJsonCount(1)
            ->assertJsonPath('0.rqmt_element_ids.0', $element->id);
    }

    public function test_manager_cannot_list(): void
    {
        $org = Organization::factory()->create();
        $manager = User::factory()->for($org, 'organization')->withRole('Manager')->create();

        $this->actingAs($manager)
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
            ->assertJsonCount(1)
            ->assertJsonPath('0.user_id', $userA->id);
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
            ->assertJsonCount(0);
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

    public function test_manager_cannot_create(): void
    {
        $org = Organization::factory()->create();
        $manager = User::factory()->for($org, 'organization')->withRole('Manager')->create();
        $user = User::factory()->for($org, 'organization')->create();
        $training = Training::factory()->for($org, 'organization')->create();

        $this->actingAs($manager)
            ->postJson('/api/completions', [
                'user_id' => $user->id,
                'module_type' => Training::class,
                'module_id' => $training->id,
                'completion_date' => '2026-05-10',
                'rqmt_element_ids' => [],
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
            ->assertForbidden();
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
}
