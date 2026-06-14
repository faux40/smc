<?php

namespace Tests\Feature\Tenancy;

use App\Events\RequirementCreated;
use App\Events\RequirementDeleted;
use App\Events\RequirementUpdated;
use App\Models\Organization;
use App\Models\Requirement;
use App\Models\RqmtElement;
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

    // ------------------------------------------------------------------
    // Paged endpoint — server-side sort + filter for the admin table.
    // (The flat /api/requirements stays the full library for pickers.)
    // ------------------------------------------------------------------

    public function test_paged_returns_data_and_meta(): void
    {
        $org = Organization::factory()->create();
        $member = User::factory()->for($org, 'organization')->create();
        Requirement::factory()->for($org, 'organization')->count(7)->create();

        $res = $this->actingAs($member)
            ->getJson('/api/requirements/paged?page=1&per_page=3')
            ->assertOk();

        $res->assertJsonCount(3, 'data');
        $res->assertJsonPath('meta.total', 7);
        $res->assertJsonPath('meta.per_page', 3);
        $res->assertJsonPath('meta.last_page', 3);
        $res->assertJsonPath('meta.current_page', 1);
    }

    public function test_paged_is_org_scoped(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $memberA = User::factory()->for($orgA, 'organization')->create();
        Requirement::factory()->for($orgA, 'organization')->create();
        Requirement::factory()->for($orgB, 'organization')->count(2)->create();

        $this->actingAs($memberA)
            ->getJson('/api/requirements/paged')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.total', 1);
    }

    public function test_paged_q_filters_by_name_and_description(): void
    {
        $org = Organization::factory()->create();
        $member = User::factory()->for($org, 'organization')->create();
        Requirement::factory()->for($org, 'organization')
            ->create(['name' => 'Forklift Refresher', 'description' => 'OSHA 1910.178']);
        Requirement::factory()->for($org, 'organization')
            ->create(['name' => 'Fall Protection', 'description' => 'roof work']);
        Requirement::factory()->for($org, 'organization')
            ->create(['name' => 'First Aid', 'description' => 'includes forklift rescue']);

        // Matches the name of #1 and the description of #3.
        $res = $this->actingAs($member)
            ->getJson('/api/requirements/paged?q=forklift')
            ->assertOk();

        $res->assertJsonPath('meta.total', 2);
        $names = collect($res->json('data'))->pluck('name')->all();
        $this->assertContains('Forklift Refresher', $names);
        $this->assertContains('First Aid', $names);
        $this->assertNotContains('Fall Protection', $names);
    }

    public function test_paged_sorts_by_name(): void
    {
        $org = Organization::factory()->create();
        $member = User::factory()->for($org, 'organization')->create();
        Requirement::factory()->for($org, 'organization')->create(['name' => 'Zebra']);
        Requirement::factory()->for($org, 'organization')->create(['name' => 'Alpha']);

        $asc = $this->actingAs($member)
            ->getJson('/api/requirements/paged?sort=name&dir=asc')
            ->assertOk();
        $this->assertSame('Alpha', $asc->json('data.0.name'));

        $desc = $this->actingAs($member)
            ->getJson('/api/requirements/paged?sort=name&dir=desc')
            ->assertOk();
        $this->assertSame('Zebra', $desc->json('data.0.name'));
    }

    public function test_paged_sorts_by_elements_count(): void
    {
        $org = Organization::factory()->create();
        $member = User::factory()->for($org, 'organization')->create();
        $few = Requirement::factory()->for($org, 'organization')->create(['name' => 'Few']);
        $many = Requirement::factory()->for($org, 'organization')->create(['name' => 'Many']);
        RqmtElement::factory()->for($org, 'organization')->for($many, 'requirement')->count(3)->create();
        RqmtElement::factory()->for($org, 'organization')->for($few, 'requirement')->count(1)->create();

        $asc = $this->actingAs($member)
            ->getJson('/api/requirements/paged?sort=elements_count&dir=asc')
            ->assertOk();
        $this->assertSame('Few', $asc->json('data.0.name'));
        $this->assertSame('Many', $asc->json('data.1.name'));

        $desc = $this->actingAs($member)
            ->getJson('/api/requirements/paged?sort=elements_count&dir=desc')
            ->assertOk();
        $this->assertSame('Many', $desc->json('data.0.name'));
        $this->assertSame(3, $desc->json('data.0.elements_count'));
    }

    public function test_paged_defaults_to_name_ascending(): void
    {
        $org = Organization::factory()->create();
        $member = User::factory()->for($org, 'organization')->create();
        Requirement::factory()->for($org, 'organization')->create(['name' => 'Bravo']);
        Requirement::factory()->for($org, 'organization')->create(['name' => 'Alpha']);

        $res = $this->actingAs($member)
            ->getJson('/api/requirements/paged')
            ->assertOk();
        $this->assertSame('Alpha', $res->json('data.0.name'));
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

    public function test_create_rejects_duplicate_name_case_insensitive(): void
    {
        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();
        Requirement::factory()->for($org, 'organization')->create(['name' => 'Fall Protection']);

        $this->actingAs($admin)
            ->postJson('/api/requirements', ['name' => 'fall protection'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');
    }

    public function test_create_allows_same_name_in_other_org(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $adminB = User::factory()->for($orgB, 'organization')->withRole('Admin')->create();
        Requirement::factory()->for($orgA, 'organization')->create(['name' => 'Fall Protection']);

        // Same name is fine in a different org — uniqueness is per-org.
        $this->actingAs($adminB)
            ->postJson('/api/requirements', ['name' => 'Fall Protection'])
            ->assertCreated();
    }

    public function test_create_allows_reuse_of_soft_deleted_name(): void
    {
        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();
        $req = Requirement::factory()->for($org, 'organization')->create(['name' => 'Forklift']);
        $req->delete();

        $this->actingAs($admin)
            ->postJson('/api/requirements', ['name' => 'Forklift'])
            ->assertCreated();
    }

    public function test_update_rejects_duplicate_name(): void
    {
        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();
        Requirement::factory()->for($org, 'organization')->create(['name' => 'Hazmat']);
        $other = Requirement::factory()->for($org, 'organization')->create(['name' => 'Lockout']);

        $this->actingAs($admin)
            ->patchJson("/api/requirements/{$other->id}", ['name' => 'Hazmat'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');
    }

    public function test_update_allows_keeping_own_name(): void
    {
        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();
        $req = Requirement::factory()->for($org, 'organization')->create(['name' => 'First Aid']);

        // Re-saving the same name (e.g. only editing the description) must
        // not trip the uniqueness rule against the row itself.
        $this->actingAs($admin)
            ->patchJson("/api/requirements/{$req->id}", [
                'name' => 'First Aid',
                'description' => 'updated',
            ])
            ->assertOk();
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
