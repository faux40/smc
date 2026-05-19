<?php

namespace Tests\Feature\Tenancy;

use App\Models\Organization;
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

    public function test_owner_super_admin_admin_can_view_index(): void
    {
        $org = Organization::factory()->create();
        $owner = $this->ownerOf($org);
        $sa = User::factory()->forOrganization($org)->withRole('SuperAdmin')->create();
        $admin = User::factory()->forOrganization($org)->withRole('Admin')->create();

        foreach ([$owner, $sa, $admin] as $u) {
            $this->actingAs($u)->get(route('users.index'))->assertOk();
        }
    }

    public function test_manager_and_no_role_users_cannot_view_index(): void
    {
        $org = Organization::factory()->create();
        $this->ownerOf($org);
        $manager = User::factory()->forOrganization($org)->withRole('Manager')->create();
        $member = User::factory()->forOrganization($org)->create();

        $this->actingAs($manager)->get(route('users.index'))->assertForbidden();
        $this->actingAs($member)->get(route('users.index'))->assertForbidden();
    }

    public function test_index_is_org_scoped(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $ownerA = $this->ownerOf($orgA);
        $this->ownerOf($orgB);
        User::factory()->forOrganization($orgA)->count(2)->create();
        User::factory()->forOrganization($orgB)->count(3)->create();

        // Owner of A counts: ownerA + 2 members = 3.
        $this->actingAs($ownerA)
            ->get(route('users.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('users/Index')
                ->has('users', 3)
            );
    }

    public function test_index_excludes_soft_deleted_users(): void
    {
        $org = Organization::factory()->create();
        $owner = $this->ownerOf($org);
        $kept = User::factory()->forOrganization($org)->create();
        $gone = User::factory()->forOrganization($org)->create();
        $gone->delete();

        $this->actingAs($owner)
            ->get(route('users.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page->has('users', 2));
    }

    public function test_index_filters_by_name_or_email_search(): void
    {
        $org = Organization::factory()->create();
        $owner = $this->ownerOf($org);
        User::factory()->forOrganization($org)->create(['f_name' => 'Forklift', 'l_name' => 'Frank', 'email' => 'frank@example.com']);
        User::factory()->forOrganization($org)->create(['f_name' => 'Alice', 'l_name' => 'Andersen', 'email' => 'alice@example.com']);

        $this->actingAs($owner)
            ->get(route('users.index', ['q' => 'Forklift']))
            ->assertInertia(fn (AssertableInertia $page) => $page->has('users', 1));

        $this->actingAs($owner)
            ->get(route('users.index', ['q' => 'alice@']))
            ->assertInertia(fn (AssertableInertia $page) => $page->has('users', 1));
    }

    public function test_index_status_filter_hides_disabled_by_default(): void
    {
        $org = Organization::factory()->create();
        $owner = $this->ownerOf($org);
        User::factory()->forOrganization($org)->count(2)->create();
        User::factory()->forOrganization($org)->disabled()->create();

        // Default = active only (3 total = owner + 2 members; disabled hidden).
        $this->actingAs($owner)
            ->get(route('users.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page->has('users', 3));

        // ?include_disabled=1 includes all (owner + 2 + 1 = 4).
        $this->actingAs($owner)
            ->get(route('users.index', ['include_disabled' => 1]))
            ->assertInertia(fn (AssertableInertia $page) => $page->has('users', 4));
    }

    public function test_index_passes_can_create_flag(): void
    {
        $org = Organization::factory()->create();
        $owner = $this->ownerOf($org);

        $this->actingAs($owner)
            ->get(route('users.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('can_create', true));
    }

    public function test_index_includes_tag_ids_per_user(): void
    {
        // TagsListCell on the row reads from the tags store; the host page
        // hydrates the store from this `tag_ids` field at first paint.
        $org = Organization::factory()->create();
        $owner = $this->ownerOf($org);
        $tagged = User::factory()->forOrganization($org)->create();
        $untagged = User::factory()->forOrganization($org)->create();

        $tagA = \App\Models\Tag::factory()->for($org, 'organization')->create();
        $tagB = \App\Models\Tag::factory()->for($org, 'organization')->create();
        $tagged->tags()->attach([$tagA->id, $tagB->id]);

        $this->actingAs($owner)
            ->get(route('users.index'))
            ->assertInertia(function (AssertableInertia $page) use ($tagged, $untagged, $tagA, $tagB) {
                $page->has('users', 3);

                $rows = collect($page->toArray()['props']['users'])->keyBy('id');
                $taggedIds = $rows[$tagged->id]['tag_ids'];
                $this->assertEqualsCanonicalizing([$tagA->id, $tagB->id], $taggedIds);
                $this->assertSame([], $rows[$untagged->id]['tag_ids']);
            });
    }

    /**
     * @return array{Organization, User, User, User, User, \App\Models\Tag, \App\Models\Tag}
     */
    private function setupTagFilterFixture(): array
    {
        $org = Organization::factory()->create();
        $owner = $this->ownerOf($org);
        $both = User::factory()->forOrganization($org)->create(['l_name' => 'Both']);
        $onlyA = User::factory()->forOrganization($org)->create(['l_name' => 'OnlyA']);
        $neither = User::factory()->forOrganization($org)->create(['l_name' => 'Neither']);

        $tagA = \App\Models\Tag::factory()->for($org, 'organization')->create();
        $tagB = \App\Models\Tag::factory()->for($org, 'organization')->create();
        $both->tags()->attach([$tagA->id, $tagB->id]);
        $onlyA->tags()->attach([$tagA->id]);

        return [$org, $owner, $both, $onlyA, $neither, $tagA, $tagB];
    }

    public function test_tags_filter_and_requires_every_selected_tag(): void
    {
        [, $owner, $both, , , $tagA, $tagB] = $this->setupTagFilterFixture();

        $this->actingAs($owner)
            ->get(route('users.index', [
                'tags' => [$tagA->id, $tagB->id],
                'tags_mode' => 'and',
            ]))
            ->assertInertia(function (AssertableInertia $page) use ($both) {
                $rows = collect($page->toArray()['props']['users']);
                $this->assertSame([$both->id], $rows->pluck('id')->all());
            });
    }

    public function test_tags_filter_or_matches_any_selected_tag(): void
    {
        [, $owner, $both, $onlyA, , $tagA, $tagB] = $this->setupTagFilterFixture();

        $this->actingAs($owner)
            ->get(route('users.index', [
                'tags' => [$tagA->id, $tagB->id],
                'tags_mode' => 'or',
            ]))
            ->assertInertia(function (AssertableInertia $page) use ($both, $onlyA) {
                $ids = collect($page->toArray()['props']['users'])->pluck('id')->all();
                $this->assertEqualsCanonicalizing([$both->id, $onlyA->id], $ids);
            });
    }

    public function test_tags_filter_not_excludes_selected_tags(): void
    {
        [, $owner, , , $neither, $tagA, $tagB] = $this->setupTagFilterFixture();
        // Owner is also untagged, so the "not" set is owner + neither.

        $this->actingAs($owner)
            ->get(route('users.index', [
                'tags' => [$tagA->id, $tagB->id],
                'tags_mode' => 'not',
            ]))
            ->assertInertia(function (AssertableInertia $page) use ($owner, $neither) {
                $ids = collect($page->toArray()['props']['users'])->pluck('id')->all();
                $this->assertEqualsCanonicalizing([$owner->id, $neither->id], $ids);
            });
    }

    public function test_tags_filter_invalid_mode_defaults_to_and(): void
    {
        [, $owner, $both, , , $tagA, $tagB] = $this->setupTagFilterFixture();

        $this->actingAs($owner)
            ->get(route('users.index', [
                'tags' => [$tagA->id, $tagB->id],
                'tags_mode' => 'whatever',
            ]))
            ->assertInertia(function (AssertableInertia $page) use ($both) {
                $rows = collect($page->toArray()['props']['users']);
                $this->assertSame([$both->id], $rows->pluck('id')->all());
                $page->where('filters.tags_mode', 'and');
            });
    }

    public function test_tags_filter_empty_returns_unfiltered(): void
    {
        [, $owner] = $this->setupTagFilterFixture();

        $this->actingAs($owner)
            ->get(route('users.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('users', 4)
                ->where('filters.tags', [])
                ->where('filters.tags_mode', 'and')
            );
    }
}
