<?php

namespace Tests\Feature\Tenancy;

use App\Models\Organization;
use App\Models\Tag;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class UsersIndexTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private function ownerOf(Organization $org): User
    {
        $owner = User::factory()->forOrganization($org)->create();
        $org->update(['owner_user_id' => $owner->id]);
        $owner->assignRole('Owner');

        return $owner;
    }

    /** Fetch the paginated list `data` rows for the given query. */
    private function listData(User $actor, array $query = []): array
    {
        return $this->actingAs($actor)
            ->getJson(route('users.list', $query))
            ->assertOk()
            ->json('data');
    }

    // ---- Inertia shell + authorization -------------------------------------

    public function test_owner_super_admin_admin_can_view_index(): void
    {
        $org = Organization::factory()->create();
        $owner = $this->ownerOf($org);
        $sa = User::factory()->forOrganization($org)->withRole('SuperAdmin')->create();
        $admin = User::factory()->forOrganization($org)->withRole('Admin')->create();

        foreach ([$owner, $sa, $admin] as $u) {
            $this->actingAs($u)->get(route('users.index'))->assertOk();
            $this->actingAs($u)->getJson(route('users.list'))->assertOk();
        }
    }

    public function test_manager_and_no_role_users_cannot_view_index_or_list(): void
    {
        $org = Organization::factory()->create();
        $this->ownerOf($org);
        $manager = User::factory()->forOrganization($org)->withRole('Manager')->create();
        $member = User::factory()->forOrganization($org)->create();

        foreach ([$manager, $member] as $u) {
            $this->actingAs($u)->get(route('users.index'))->assertForbidden();
            $this->actingAs($u)->getJson(route('users.list'))->assertForbidden();
        }
    }

    public function test_index_shell_passes_filters_and_can_create_without_the_user_list(): void
    {
        $org = Organization::factory()->create();
        $owner = $this->ownerOf($org);

        $this->actingAs($owner)
            ->get(route('users.index', ['q' => 'foo', 'role' => 'Manager']))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('users/Index')
                ->where('can_create', true)
                ->where('filters.q', 'foo')
                ->where('filters.role', 'Manager')
                ->where('filters.tags', [])
                ->where('filters.tags_mode', 'and')
                // The list streams in via the JSON endpoint — the shell never
                // ships the (potentially huge) user array.
                ->missing('users')
            );
    }

    // ---- List data: scoping / filters --------------------------------------

    public function test_list_is_org_scoped(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $ownerA = $this->ownerOf($orgA);
        $this->ownerOf($orgB);
        User::factory()->forOrganization($orgA)->count(2)->create();
        User::factory()->forOrganization($orgB)->count(3)->create();

        // Owner of A sees ownerA + 2 members = 3.
        $this->actingAs($ownerA)
            ->getJson(route('users.list'))
            ->assertOk()
            ->assertJsonPath('meta.total', 3)
            ->assertJsonCount(3, 'data');
    }

    public function test_list_excludes_soft_deleted_users(): void
    {
        $org = Organization::factory()->create();
        $owner = $this->ownerOf($org);
        User::factory()->forOrganization($org)->create();
        $gone = User::factory()->forOrganization($org)->create();
        $gone->delete();

        $this->assertCount(2, $this->listData($owner));
    }

    public function test_list_filters_by_name_or_email_search(): void
    {
        $org = Organization::factory()->create();
        $owner = $this->ownerOf($org);
        User::factory()->forOrganization($org)->create(['f_name' => 'Forklift', 'l_name' => 'Frank', 'email' => 'frank@example.com']);
        User::factory()->forOrganization($org)->create(['f_name' => 'Alice', 'l_name' => 'Andersen', 'email' => 'alice@example.com']);

        $this->assertCount(1, $this->listData($owner, ['q' => 'Forklift']));
        $this->assertCount(1, $this->listData($owner, ['q' => 'alice@']));
    }

    public function test_list_search_covers_profile_fields_case_insensitively(): void
    {
        $org = Organization::factory()->create();
        $owner = $this->ownerOf($org);
        User::factory()->forOrganization($org)->create([
            'f_name' => 'Dana', 'l_name' => 'Reed',
            'job_title' => 'Foreman', 'department' => 'Operations',
            'location' => 'Yard 3', 'employee_number' => 'EMP-1234',
        ]);
        User::factory()->forOrganization($org)->create([
            'f_name' => 'Sam', 'l_name' => 'Lee',
            'job_title' => 'Analyst', 'department' => 'Admin',
            'location' => 'HQ', 'employee_number' => 'EMP-9999',
        ]);

        // Only the Dana row matches each of these (owner + Sam have null/other
        // values). The factory leaves the profile fields null on the others.
        $expectOne = fn (string $q) => $this->assertCount(1, $this->listData($owner, ['q' => $q]), "q={$q}");

        $expectOne('Foreman');     // job_title
        $expectOne('Operations');  // department
        $expectOne('Yard 3');      // location
        $expectOne('EMP-1234');    // employee_number

        // Case-insensitive across all searched columns (Postgres LIKE is
        // case-sensitive, so these would miss without LOWER()).
        $expectOne('foreman');
        $expectOne('operATIONS');
        $expectOne('emp-1234');
        $expectOne('dana');        // f_name
        $expectOne('REED');        // l_name
    }

    public function test_list_role_filter_runs_in_the_db(): void
    {
        $org = Organization::factory()->create();
        $owner = $this->ownerOf($org);
        $manager = User::factory()->forOrganization($org)->withRole('Manager')->create();
        User::factory()->forOrganization($org)->withRole('Admin')->create();

        $rows = $this->listData($owner, ['role' => 'Manager']);
        $this->assertSame([$manager->id], collect($rows)->pluck('id')->all());
    }

    public function test_list_includes_supervisor_and_sortable_names(): void
    {
        $org = Organization::factory()->create();
        $owner = $this->ownerOf($org);
        $boss = User::factory()->forOrganization($org)
            ->create(['f_name' => 'Sam', 'm_name' => 'T', 'l_name' => 'Boss']);
        $report = User::factory()->forOrganization($org)->create([
            'f_name' => 'Ada', 'm_name' => 'Augusta', 'l_name' => 'Lovelace',
            'supervisor_id' => $boss->id,
        ]);

        $rows = collect($this->listData($owner))->keyBy('id');
        $this->assertSame('Sam T Boss', $rows[$report->id]['supervisor_name']);
        $this->assertSame('Lovelace, Ada Augusta', $rows[$report->id]['sort_name']);
        $this->assertSame('Boss, Sam T', $rows[$report->id]['supervisor_sort_name']);
    }

    public function test_list_status_filter_hides_disabled_by_default(): void
    {
        $org = Organization::factory()->create();
        $owner = $this->ownerOf($org);
        User::factory()->forOrganization($org)->count(2)->create();
        User::factory()->forOrganization($org)->disabled()->create();

        // Default = active only (owner + 2 members; disabled hidden).
        $this->assertCount(3, $this->listData($owner));
        // ?include_disabled=1 includes all (owner + 2 + 1 = 4).
        $this->assertCount(4, $this->listData($owner, ['include_disabled' => 1]));
    }

    public function test_list_includes_tag_ids_per_user(): void
    {
        $org = Organization::factory()->create();
        $owner = $this->ownerOf($org);
        $tagged = User::factory()->forOrganization($org)->create();
        $untagged = User::factory()->forOrganization($org)->create();

        $tagA = Tag::factory()->for($org, 'organization')->create();
        $tagB = Tag::factory()->for($org, 'organization')->create();
        $tagged->tags()->attach([$tagA->id, $tagB->id]);

        $rows = collect($this->listData($owner))->keyBy('id');
        $this->assertEqualsCanonicalizing([$tagA->id, $tagB->id], $rows[$tagged->id]['tag_ids']);
        $this->assertSame([], $rows[$untagged->id]['tag_ids']);
    }

    // ---- Tag filter modes --------------------------------------------------

    /**
     * @return array{Organization, User, User, User, User, Tag, Tag}
     */
    private function setupTagFilterFixture(): array
    {
        $org = Organization::factory()->create();
        $owner = $this->ownerOf($org);
        $both = User::factory()->forOrganization($org)->create(['l_name' => 'Both']);
        $onlyA = User::factory()->forOrganization($org)->create(['l_name' => 'OnlyA']);
        $neither = User::factory()->forOrganization($org)->create(['l_name' => 'Neither']);

        $tagA = Tag::factory()->for($org, 'organization')->create();
        $tagB = Tag::factory()->for($org, 'organization')->create();
        $both->tags()->attach([$tagA->id, $tagB->id]);
        $onlyA->tags()->attach([$tagA->id]);

        return [$org, $owner, $both, $onlyA, $neither, $tagA, $tagB];
    }

    public function test_tags_filter_and_requires_every_selected_tag(): void
    {
        [, $owner, $both, , , $tagA, $tagB] = $this->setupTagFilterFixture();

        $rows = $this->listData($owner, ['tags' => [$tagA->id, $tagB->id], 'tags_mode' => 'and']);
        $this->assertSame([$both->id], collect($rows)->pluck('id')->all());
    }

    public function test_tags_filter_or_matches_any_selected_tag(): void
    {
        [, $owner, $both, $onlyA, , $tagA, $tagB] = $this->setupTagFilterFixture();

        $rows = $this->listData($owner, ['tags' => [$tagA->id, $tagB->id], 'tags_mode' => 'or']);
        $this->assertEqualsCanonicalizing([$both->id, $onlyA->id], collect($rows)->pluck('id')->all());
    }

    public function test_tags_filter_not_excludes_selected_tags(): void
    {
        [, $owner, , , $neither, $tagA, $tagB] = $this->setupTagFilterFixture();
        // Owner is also untagged, so the "not" set is owner + neither.

        $rows = $this->listData($owner, ['tags' => [$tagA->id, $tagB->id], 'tags_mode' => 'not']);
        $this->assertEqualsCanonicalizing([$owner->id, $neither->id], collect($rows)->pluck('id')->all());
    }

    public function test_tags_filter_invalid_mode_defaults_to_and(): void
    {
        [, $owner, $both, , , $tagA, $tagB] = $this->setupTagFilterFixture();

        $rows = $this->listData($owner, ['tags' => [$tagA->id, $tagB->id], 'tags_mode' => 'whatever']);
        $this->assertSame([$both->id], collect($rows)->pluck('id')->all());
    }

    // ---- Sorting -----------------------------------------------------------

    public function test_list_sorts_by_name_ascending_and_descending(): void
    {
        $org = Organization::factory()->create();
        $owner = $this->ownerOf($org); // l_name set by factory; ignore in assertion
        User::factory()->forOrganization($org)->create(['f_name' => 'A', 'l_name' => 'Carter']);
        User::factory()->forOrganization($org)->create(['f_name' => 'B', 'l_name' => 'Adams']);
        User::factory()->forOrganization($org)->create(['f_name' => 'C', 'l_name' => 'Baker']);

        $asc = collect($this->listData($owner, ['sort' => 'name', 'dir' => 'asc']))
            ->pluck('l_name')->values()->all();
        $this->assertSame($asc, collect($asc)->sort()->values()->all());

        $desc = collect($this->listData($owner, ['sort' => 'name', 'dir' => 'desc']))
            ->pluck('l_name')->values()->all();
        $this->assertSame($desc, collect($asc)->reverse()->values()->all());
    }

    public function test_list_can_sort_by_role_via_join(): void
    {
        $org = Organization::factory()->create();
        $owner = $this->ownerOf($org);
        User::factory()->forOrganization($org)->withRole('Manager')->create();
        User::factory()->forOrganization($org)->withRole('Admin')->create();

        // Sort by role ascending — every row carries a role and the join must
        // not drop or duplicate any row.
        $rows = $this->listData($owner, ['sort' => 'role', 'dir' => 'asc']);
        $roles = collect($rows)->pluck('role')->all();

        $this->assertCount(3, $rows);
        $this->assertSame($roles, collect($roles)->sort()->values()->all());
    }

    public function test_list_can_sort_by_supervisor_via_self_join(): void
    {
        $org = Organization::factory()->create();
        $owner = $this->ownerOf($org);
        $bossZ = User::factory()->forOrganization($org)->create(['l_name' => 'Zane']);
        $bossA = User::factory()->forOrganization($org)->create(['l_name' => 'Abbot']);
        $r1 = User::factory()->forOrganization($org)->create(['supervisor_id' => $bossZ->id]);
        $r2 = User::factory()->forOrganization($org)->create(['supervisor_id' => $bossA->id]);

        // Ascending by supervisor surname → Abbot's report precedes Zane's.
        $ids = collect($this->listData($owner, ['sort' => 'supervisor', 'dir' => 'asc']))
            ->pluck('id')->all();
        $this->assertLessThan(
            array_search($r1->id, $ids, true),
            array_search($r2->id, $ids, true),
        );
    }

    // ---- Pagination --------------------------------------------------------

    public function test_list_paginates_with_meta_and_clamps_per_page(): void
    {
        $org = Organization::factory()->create();
        $owner = $this->ownerOf($org);
        User::factory()->forOrganization($org)->count(5)->create(); // 6 total incl. owner

        $this->actingAs($owner)
            ->getJson(route('users.list', ['per_page' => 2, 'page' => 2]))
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 6)
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.last_page', 3);

        // per_page is clamped to a sane ceiling (100).
        $this->actingAs($owner)
            ->getJson(route('users.list', ['per_page' => 9999]))
            ->assertOk()
            ->assertJsonPath('meta.per_page', 100);
    }
}
