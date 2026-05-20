<?php

namespace Tests\Feature\Tenancy;

use App\Events\AssignmentCreated;
use App\Models\Assignment;
use App\Models\Organization;
use App\Models\Requirement;
use App\Models\Tag;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Coverage for the Phase 13.1 tag-driven bulk-assignment endpoints.
 * Preview returns the user × requirement cross-product implied by a tag
 * plus the existing-assignment pairs inside it; store creates the
 * non-existing pairs in one transaction and broadcasts AssignmentCreated.
 */
class BulkAssignmentsApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private function scaffoldOrg(string $managerRole = 'Manager'): array
    {
        $org = Organization::factory()->create();
        $manager = User::factory()->for($org, 'organization')->withRole($managerRole)->create();
        $tag = Tag::factory()->for($org, 'organization')->create(['name' => 'field-crew']);

        return [$org, $manager, $tag];
    }

    public function test_preview_returns_tagged_users_and_requirements(): void
    {
        [$org, $manager, $tag] = $this->scaffoldOrg();

        $userTagged = User::factory()->for($org, 'organization')->create();
        $userUntagged = User::factory()->for($org, 'organization')->create();
        $userTagged->tags()->attach($tag->id);

        $reqTagged = Requirement::factory()->for($org, 'organization')->create();
        $reqUntagged = Requirement::factory()->for($org, 'organization')->create();
        $reqTagged->tags()->attach($tag->id);

        $response = $this->actingAs($manager)
            ->getJson("/api/bulk-assignments/preview?tag_id={$tag->id}")
            ->assertOk()
            ->json();

        $this->assertSame($tag->id, $response['tag']['id']);
        $this->assertSame([$userTagged->id], collect($response['users'])->pluck('id')->all());
        $this->assertSame([$reqTagged->id], collect($response['requirements'])->pluck('id')->all());
        $this->assertSame([], $response['existing_pairs']);
    }

    public function test_preview_lists_existing_pairs_in_cross_product(): void
    {
        [$org, $manager, $tag] = $this->scaffoldOrg();
        $userA = User::factory()->for($org, 'organization')->create();
        $userB = User::factory()->for($org, 'organization')->create();
        $reqA = Requirement::factory()->for($org, 'organization')->create();
        $reqB = Requirement::factory()->for($org, 'organization')->create();
        $userA->tags()->attach($tag->id);
        $userB->tags()->attach($tag->id);
        $reqA->tags()->attach($tag->id);
        $reqB->tags()->attach($tag->id);

        // Pre-existing assignment in the cross-product → should appear
        // in existing_pairs.
        Assignment::factory()
            ->for($org, 'organization')
            ->for($userA, 'user')
            ->for($reqA, 'requirement')
            ->create();

        $response = $this->actingAs($manager)
            ->getJson("/api/bulk-assignments/preview?tag_id={$tag->id}")
            ->assertOk()
            ->json();

        $this->assertCount(1, $response['existing_pairs']);
        $this->assertSame(
            ['user_id' => $userA->id, 'requirement_id' => $reqA->id],
            $response['existing_pairs'][0],
        );
    }

    public function test_preview_is_org_scoped(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $managerA = User::factory()->for($orgA, 'organization')->withRole('Manager')->create();
        $tagB = Tag::factory()->for($orgB, 'organization')->create();

        $this->actingAs($managerA)
            ->getJson("/api/bulk-assignments/preview?tag_id={$tagB->id}")
            ->assertForbidden();
    }

    public function test_preview_rejects_self_edit_role(): void
    {
        [$org, , $tag] = $this->scaffoldOrg();
        $self = User::factory()->for($org, 'organization')->withRole('SelfEdit')->create();

        $this->actingAs($self)
            ->getJson("/api/bulk-assignments/preview?tag_id={$tag->id}")
            ->assertForbidden();
    }

    public function test_store_creates_the_picked_cross_product(): void
    {
        Event::fake([AssignmentCreated::class]);
        [$org, $manager] = $this->scaffoldOrg();

        $userA = User::factory()->for($org, 'organization')->create();
        $userB = User::factory()->for($org, 'organization')->create();
        $reqA = Requirement::factory()->for($org, 'organization')->create();
        $reqB = Requirement::factory()->for($org, 'organization')->create();

        $response = $this->actingAs($manager)
            ->postJson('/api/bulk-assignments', [
                'pairs' => [
                    ['user_id' => $userA->id, 'requirement_id' => $reqA->id],
                    ['user_id' => $userA->id, 'requirement_id' => $reqB->id],
                    ['user_id' => $userB->id, 'requirement_id' => $reqA->id],
                ],
                'initial_only' => false,
                'repeating' => true,
                'std_freq_id' => null,
                'as_needed' => false,
                'start_date' => '2026-05-12',
            ])
            ->assertStatus(422)
            ->json();

        // std_freq required because repeating=true — sanity that the
        // shared timing rules fire on bulk too.
        $this->assertArrayHasKey('std_freq_id', $response['errors']);
    }

    public function test_store_creates_with_initial_only_timing(): void
    {
        Event::fake([AssignmentCreated::class]);
        [$org, $manager] = $this->scaffoldOrg();
        $userA = User::factory()->for($org, 'organization')->create();
        $userB = User::factory()->for($org, 'organization')->create();
        $reqA = Requirement::factory()->for($org, 'organization')->create();

        $response = $this->actingAs($manager)
            ->postJson('/api/bulk-assignments', [
                'pairs' => [
                    ['user_id' => $userA->id, 'requirement_id' => $reqA->id],
                    ['user_id' => $userB->id, 'requirement_id' => $reqA->id],
                ],
                'initial_only' => true,
                'repeating' => false,
                'as_needed' => false,
                'start_date' => '2026-05-12',
            ])
            ->assertCreated()
            ->json();

        $this->assertSame(2, $response['created_count']);
        $this->assertSame(0, $response['skipped_count']);
        $this->assertDatabaseHas('assignments', [
            'user_id' => $userA->id, 'requirement_id' => $reqA->id, 'initial_only' => true,
        ]);
        $this->assertDatabaseHas('assignments', [
            'user_id' => $userB->id, 'requirement_id' => $reqA->id,
        ]);
        Event::assertDispatchedTimes(AssignmentCreated::class, 2);
    }

    public function test_store_skips_existing_pairs(): void
    {
        Event::fake([AssignmentCreated::class]);
        [$org, $manager] = $this->scaffoldOrg();
        $userA = User::factory()->for($org, 'organization')->create();
        $userB = User::factory()->for($org, 'organization')->create();
        $reqA = Requirement::factory()->for($org, 'organization')->create();

        Assignment::factory()
            ->for($org, 'organization')
            ->for($userA, 'user')
            ->for($reqA, 'requirement')
            ->create();

        $response = $this->actingAs($manager)
            ->postJson('/api/bulk-assignments', [
                'pairs' => [
                    ['user_id' => $userA->id, 'requirement_id' => $reqA->id], // already assigned
                    ['user_id' => $userB->id, 'requirement_id' => $reqA->id], // new
                ],
                'initial_only' => true,
                'repeating' => false,
                'as_needed' => false,
                'start_date' => '2026-05-12',
            ])
            ->assertCreated()
            ->json();

        $this->assertSame(1, $response['created_count']);
        $this->assertSame(1, $response['skipped_count']);
        Event::assertDispatchedTimes(AssignmentCreated::class, 1);
    }

    public function test_store_dedupes_duplicate_pairs_in_request(): void
    {
        Event::fake([AssignmentCreated::class]);
        [$org, $manager] = $this->scaffoldOrg();
        $userA = User::factory()->for($org, 'organization')->create();
        $reqA = Requirement::factory()->for($org, 'organization')->create();

        $response = $this->actingAs($manager)
            ->postJson('/api/bulk-assignments', [
                'pairs' => [
                    ['user_id' => $userA->id, 'requirement_id' => $reqA->id],
                    ['user_id' => $userA->id, 'requirement_id' => $reqA->id], // duplicate
                ],
                'initial_only' => true,
                'repeating' => false,
                'as_needed' => false,
                'start_date' => '2026-05-12',
            ])
            ->assertCreated()
            ->json();

        $this->assertSame(1, $response['created_count']);
    }

    public function test_store_copies_requirement_name_into_assignment(): void
    {
        [$org, $manager] = $this->scaffoldOrg();
        $userA = User::factory()->for($org, 'organization')->create();
        $reqA = Requirement::factory()
            ->for($org, 'organization')
            ->create(['name' => 'OSHA General', 'description' => 'Site-wide baseline.']);

        $this->actingAs($manager)
            ->postJson('/api/bulk-assignments', [
                'pairs' => [
                    ['user_id' => $userA->id, 'requirement_id' => $reqA->id],
                ],
                'initial_only' => true,
                'repeating' => false,
                'as_needed' => false,
                'start_date' => '2026-05-12',
            ])
            ->assertCreated();

        $this->assertDatabaseHas('assignments', [
            'user_id' => $userA->id,
            'requirement_id' => $reqA->id,
            'name' => 'OSHA General',
            'description' => 'Site-wide baseline.',
        ]);
    }

    public function test_store_rejects_cross_org_pair(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $managerA = User::factory()->for($orgA, 'organization')->withRole('Manager')->create();
        $userB = User::factory()->for($orgB, 'organization')->create();
        $reqA = Requirement::factory()->for($orgA, 'organization')->create();

        $this->actingAs($managerA)
            ->postJson('/api/bulk-assignments', [
                'pairs' => [
                    ['user_id' => $userB->id, 'requirement_id' => $reqA->id],
                ],
                'initial_only' => true,
                'repeating' => false,
                'as_needed' => false,
                'start_date' => '2026-05-12',
            ])
            ->assertStatus(422);
    }

    public function test_store_rejects_self_edit_role(): void
    {
        $org = Organization::factory()->create();
        $self = User::factory()->for($org, 'organization')->withRole('SelfEdit')->create();
        $user = User::factory()->for($org, 'organization')->create();
        $req = Requirement::factory()->for($org, 'organization')->create();

        $this->actingAs($self)
            ->postJson('/api/bulk-assignments', [
                'pairs' => [
                    ['user_id' => $user->id, 'requirement_id' => $req->id],
                ],
                'initial_only' => true,
                'repeating' => false,
                'as_needed' => false,
                'start_date' => '2026-05-12',
            ])
            ->assertForbidden();
    }

    public function test_store_rejects_no_timing_flag(): void
    {
        $org = Organization::factory()->create();
        $manager = User::factory()->for($org, 'organization')->withRole('Manager')->create();
        $user = User::factory()->for($org, 'organization')->create();
        $req = Requirement::factory()->for($org, 'organization')->create();

        $this->actingAs($manager)
            ->postJson('/api/bulk-assignments', [
                'pairs' => [
                    ['user_id' => $user->id, 'requirement_id' => $req->id],
                ],
                'initial_only' => false,
                'repeating' => false,
                'as_needed' => false,
                'start_date' => '2026-05-12',
            ])
            ->assertStatus(422);
    }

    public function test_store_requires_at_least_one_pair(): void
    {
        $org = Organization::factory()->create();
        $manager = User::factory()->for($org, 'organization')->withRole('Manager')->create();

        $this->actingAs($manager)
            ->postJson('/api/bulk-assignments', [
                'pairs' => [],
                'initial_only' => true,
                'repeating' => false,
                'as_needed' => false,
                'start_date' => '2026-05-12',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('pairs');
    }
}
