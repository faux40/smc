<?php

namespace Tests\Feature\Tenancy;

use App\Events\RequirementCreated;
use App\Events\RequirementDeleted;
use App\Events\RequirementUpdated;
use App\Models\Organization;
use App\Models\Requirement;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class RequirementsApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_anyone_in_org_can_list(): void
    {
        $org = Organization::factory()->create();
        $member = User::factory()->for($org, 'organization')->withRole('None')->create();
        Requirement::factory()->for($org, 'organization')->count(2)->create();

        $this->actingAs($member)
            ->getJson('/api/requirements')
            ->assertOk()
            ->assertJsonCount(2);
    }

    public function test_list_is_org_scoped(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $memberA = User::factory()->for($orgA, 'organization')->create();
        Requirement::factory()->for($orgA, 'organization')->create();
        Requirement::factory()->for($orgB, 'organization')->count(2)->create();

        $this->actingAs($memberA)
            ->getJson('/api/requirements')
            ->assertOk()
            ->assertJsonCount(1);
    }

    public function test_admin_can_create(): void
    {
        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();

        $this->actingAs($admin)
            ->postJson('/api/requirements', [
                'name' => 'Forklift Certification',
                'description' => 'OSHA 1910.178',
            ])
            ->assertCreated();

        $this->assertDatabaseHas('requirements', [
            'org_id' => $org->id,
            'name' => 'Forklift Certification',
        ]);
    }

    public function test_manager_cannot_create(): void
    {
        $org = Organization::factory()->create();
        $manager = User::factory()->for($org, 'organization')->withRole('Manager')->create();

        $this->actingAs($manager)
            ->postJson('/api/requirements', ['name' => 'X'])
            ->assertForbidden();
    }

    public function test_create_validates(): void
    {
        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();

        $this->actingAs($admin)
            ->postJson('/api/requirements', [])
            ->assertStatus(422);
    }

    public function test_admin_can_update(): void
    {
        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();
        $req = Requirement::factory()->for($org, 'organization')->create(['name' => 'Old']);

        $this->actingAs($admin)
            ->patchJson("/api/requirements/{$req->id}", [
                'name' => 'Renamed',
                'description' => 'updated',
            ])
            ->assertOk();

        $this->assertSame('Renamed', $req->fresh()->name);
    }

    public function test_cross_org_update_blocked(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $adminA = User::factory()->for($orgA, 'organization')->withRole('Admin')->create();
        $reqB = Requirement::factory()->for($orgB, 'organization')->create();

        $this->actingAs($adminA)
            ->patchJson("/api/requirements/{$reqB->id}", ['name' => 'hacked'])
            ->assertNotFound();
    }

    public function test_admin_can_delete(): void
    {
        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();
        $req = Requirement::factory()->for($org, 'organization')->create();

        $this->actingAs($admin)
            ->deleteJson("/api/requirements/{$req->id}")
            ->assertOk();

        $this->assertSoftDeleted('requirements', ['id' => $req->id]);
    }

    public function test_create_update_delete_broadcast(): void
    {
        Event::fake([RequirementCreated::class, RequirementUpdated::class, RequirementDeleted::class]);

        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();

        $created = $this->actingAs($admin)
            ->postJson('/api/requirements', ['name' => 'X'])
            ->json();
        $this->actingAs($admin)->patchJson("/api/requirements/{$created['id']}", ['name' => 'Y']);
        $this->actingAs($admin)->deleteJson("/api/requirements/{$created['id']}");

        Event::assertDispatched(RequirementCreated::class);
        Event::assertDispatched(RequirementUpdated::class);
        Event::assertDispatched(RequirementDeleted::class);
    }

    public function test_requirement_can_be_tagged_and_commented(): void
    {
        // Smoke that Requirement is wired as a morphable for tags + comments.
        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();
        $req = Requirement::factory()->for($org, 'organization')->create();
        $tagId = $this->actingAs($admin)
            ->postJson('/api/tags', ['name' => 'osha'])
            ->json('id');

        $this->actingAs($admin)
            ->postJson('/api/tags/attach', [
                'tag_id' => $tagId,
                'taggable_type' => Requirement::class,
                'taggable_id' => $req->id,
            ])
            ->assertOk();
        $this->actingAs($admin)
            ->postJson('/api/comments', [
                'commentable_type' => Requirement::class,
                'commentable_id' => $req->id,
                'body' => 'note',
            ])
            ->assertCreated();

        $this->assertCount(1, $req->fresh()->tags);
        $this->assertCount(1, $req->fresh()->comments);
    }
}
