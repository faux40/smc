<?php

namespace Tests\Feature\Tenancy;

use App\Models\Organization;
use App\Models\Tag;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Coverage for the lean /api/users picker endpoint. Returns active org
 * users for the manual Assignment / Completion form modals. Manager+
 * role gate (matches the Assignment / Completion create policies).
 */
class UsersPickerApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_manager_can_list_picker_users(): void
    {
        $org = Organization::factory()->create();
        // All three names are explicit: the manager's surname is otherwise a
        // random faker value, which intermittently sorts before 'Adams' and
        // breaks the ordering assertion below.
        $manager = User::factory()->for($org, 'organization')->withRole('Manager')
            ->create(['f_name' => 'Zoe', 'l_name' => 'Zimmer']);
        User::factory()->for($org, 'organization')->create(['f_name' => 'Alice', 'l_name' => 'Adams']);
        User::factory()->for($org, 'organization')->create(['f_name' => 'Bob', 'l_name' => 'Baker']);

        $rows = $this->actingAs($manager)
            ->getJson('/api/users')
            ->assertOk()
            ->json();

        // Expect manager + the two seeded users — three total, ordered by
        // l_name then f_name.
        $this->assertCount(3, $rows);
        $this->assertSame(
            ['Adams', 'Baker', 'Zimmer'],
            array_column($rows, 'l_name'),
            'List should be ordered by l_name then f_name.'
        );
    }

    public function test_picker_includes_each_users_tag_ids(): void
    {
        $org = Organization::factory()->create();
        $manager = User::factory()->for($org, 'organization')->withRole('Manager')->create();
        $student = User::factory()->for($org, 'organization')->create();
        $tag = Tag::factory()->for($org, 'organization')->create();
        $student->tags()->attach($tag->id);

        $rows = $this->actingAs($manager)->getJson('/api/users')->assertOk()->json();

        $row = collect($rows)->firstWhere('id', $student->id);
        $this->assertSame([$tag->id], $row['tag_ids']);
    }

    public function test_disabled_users_are_excluded(): void
    {
        $org = Organization::factory()->create();
        $manager = User::factory()->for($org, 'organization')->withRole('Manager')->create();
        $disabled = User::factory()->for($org, 'organization')->disabled()->create();

        $rows = $this->actingAs($manager)
            ->getJson('/api/users')
            ->assertOk()
            ->json();

        $ids = collect($rows)->pluck('id')->all();
        $this->assertContains($manager->id, $ids);
        $this->assertNotContains($disabled->id, $ids, 'Disabled users should not appear in the picker list.');
    }

    public function test_org_scoped(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $managerA = User::factory()->for($orgA, 'organization')->withRole('Manager')->create();
        User::factory()->for($orgB, 'organization')->create();
        User::factory()->for($orgB, 'organization')->create();

        $rows = $this->actingAs($managerA)
            ->getJson('/api/users')
            ->assertOk()
            ->json();

        // Manager only — no orgB users leak.
        $this->assertCount(1, $rows);
    }

    public function test_selfedit_cannot_list(): void
    {
        $org = Organization::factory()->create();
        $self = User::factory()->for($org, 'organization')->withRole('SelfEdit')->create();

        $this->actingAs($self)
            ->getJson('/api/users')
            ->assertForbidden();
    }

    public function test_guest_redirected(): void
    {
        $this->getJson('/api/users')->assertUnauthorized();
    }
}
