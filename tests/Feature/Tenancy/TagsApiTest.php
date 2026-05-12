<?php

namespace Tests\Feature\Tenancy;

use App\Events\TagAttached;
use App\Events\TagCreated;
use App\Events\TagDetached;
use App\Models\Organization;
use App\Models\Tag;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class TagsApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private function ownerOf(Organization $org): User
    {
        $owner = User::factory()->for($org, 'organization')->create();
        $org->update(['owner_user_id' => $owner->id]);
        $owner->assignRole('Owner');

        return $owner;
    }

    public function test_anyone_in_org_can_list_tags(): void
    {
        $org = Organization::factory()->create();
        $owner = $this->ownerOf($org);
        $member = User::factory()->for($org, 'organization')->withRole('None')->create();
        Tag::factory()->for($org, 'organization')->create(['name' => 'safety']);
        Tag::factory()->for($org, 'organization')->create(['name' => 'osha']);

        $this->actingAs($member)
            ->getJson('/api/tags')
            ->assertOk()
            ->assertJsonCount(2);
    }

    public function test_index_is_org_scoped(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $ownerA = $this->ownerOf($orgA);
        Tag::factory()->for($orgA, 'organization')->create();
        Tag::factory()->for($orgB, 'organization')->count(2)->create();

        $this->actingAs($ownerA)
            ->getJson('/api/tags')
            ->assertOk()
            ->assertJsonCount(1);
    }

    public function test_admin_can_create_tag(): void
    {
        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();

        $this->actingAs($admin)
            ->postJson('/api/tags', ['name' => 'forklift', 'color' => '#ff0000'])
            ->assertCreated();

        $this->assertDatabaseHas('tags', [
            'name' => 'forklift',
            'color' => '#ff0000',
            'org_id' => $org->id,
        ]);
    }

    public function test_manager_cannot_create_tag(): void
    {
        $org = Organization::factory()->create();
        $manager = User::factory()->for($org, 'organization')->withRole('Manager')->create();

        $this->actingAs($manager)
            ->postJson('/api/tags', ['name' => 'sneaky'])
            ->assertForbidden();
    }

    public function test_create_validates_color_format(): void
    {
        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();

        $this->actingAs($admin)
            ->postJson('/api/tags', ['name' => 'bad', 'color' => 'not-a-hex'])
            ->assertStatus(422);
    }

    public function test_admin_can_rename_tag(): void
    {
        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();
        $tag = Tag::factory()->for($org, 'organization')->create(['name' => 'old']);

        $this->actingAs($admin)
            ->patchJson("/api/tags/{$tag->id}", ['name' => 'renamed'])
            ->assertOk();

        $this->assertSame('renamed', $tag->fresh()->name);
    }

    public function test_cross_org_update_blocked(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $adminA = User::factory()->for($orgA, 'organization')->withRole('Admin')->create();
        $tagB = Tag::factory()->for($orgB, 'organization')->create();

        $this->actingAs($adminA)
            ->patchJson("/api/tags/{$tagB->id}", ['name' => 'hacked'])
            ->assertForbidden();
    }

    public function test_admin_can_delete_tag(): void
    {
        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();
        $tag = Tag::factory()->for($org, 'organization')->create();

        $this->actingAs($admin)
            ->deleteJson("/api/tags/{$tag->id}")
            ->assertOk();

        $this->assertSoftDeleted('tags', ['id' => $tag->id]);
    }

    public function test_delete_cascades_taggables(): void
    {
        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();
        $tag = Tag::factory()->for($org, 'organization')->create();
        $admin->tags()->attach($tag);
        $this->assertDatabaseCount('taggables', 1);

        $this->actingAs($admin)->deleteJson("/api/tags/{$tag->id}")->assertOk();

        $this->assertDatabaseCount('taggables', 0);
    }

    public function test_anyone_in_org_can_attach_tag(): void
    {
        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();
        $member = User::factory()->for($org, 'organization')->withRole('None')->create();
        $target = User::factory()->for($org, 'organization')->create();
        $tag = Tag::factory()->for($org, 'organization')->create();

        $this->actingAs($member)
            ->postJson('/api/tags/attach', [
                'tag_id' => $tag->id,
                'taggable_type' => User::class,
                'taggable_id' => $target->id,
            ])
            ->assertOk();

        $this->assertCount(1, $target->fresh()->tags);
    }

    public function test_attach_is_idempotent(): void
    {
        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();
        $target = User::factory()->for($org, 'organization')->create();
        $tag = Tag::factory()->for($org, 'organization')->create();

        $payload = [
            'tag_id' => $tag->id,
            'taggable_type' => User::class,
            'taggable_id' => $target->id,
        ];
        $this->actingAs($admin)->postJson('/api/tags/attach', $payload)->assertOk();
        $this->actingAs($admin)->postJson('/api/tags/attach', $payload)->assertOk();

        $this->assertCount(1, $target->fresh()->tags);
    }

    public function test_attach_rejects_cross_org_tag(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $adminA = User::factory()->for($orgA, 'organization')->withRole('Admin')->create();
        $tagB = Tag::factory()->for($orgB, 'organization')->create();
        $targetA = User::factory()->for($orgA, 'organization')->create();

        $this->actingAs($adminA)
            ->postJson('/api/tags/attach', [
                'tag_id' => $tagB->id,
                'taggable_type' => User::class,
                'taggable_id' => $targetA->id,
            ])
            ->assertForbidden();
    }

    public function test_attach_rejects_cross_org_morphable(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $adminA = User::factory()->for($orgA, 'organization')->withRole('Admin')->create();
        $tagA = Tag::factory()->for($orgA, 'organization')->create();
        $targetB = User::factory()->for($orgB, 'organization')->create();

        $this->actingAs($adminA)
            ->postJson('/api/tags/attach', [
                'tag_id' => $tagA->id,
                'taggable_type' => User::class,
                'taggable_id' => $targetB->id,
            ])
            ->assertForbidden();
    }

    public function test_attach_rejects_unknown_taggable_type(): void
    {
        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();
        $tag = Tag::factory()->for($org, 'organization')->create();

        $this->actingAs($admin)
            ->postJson('/api/tags/attach', [
                'tag_id' => $tag->id,
                'taggable_type' => 'App\\Models\\Organization',
                'taggable_id' => $org->id,
            ])
            ->assertStatus(422);
    }

    public function test_detach_removes_pivot_row(): void
    {
        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();
        $target = User::factory()->for($org, 'organization')->create();
        $tag = Tag::factory()->for($org, 'organization')->create();
        $target->tags()->attach($tag);
        $this->assertDatabaseCount('taggables', 1);

        $this->actingAs($admin)
            ->postJson('/api/tags/detach', [
                'tag_id' => $tag->id,
                'taggable_type' => User::class,
                'taggable_id' => $target->id,
            ])
            ->assertOk();

        $this->assertDatabaseCount('taggables', 0);
    }

    public function test_create_attach_detach_broadcast(): void
    {
        Event::fake([TagCreated::class, TagAttached::class, TagDetached::class]);

        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();
        $target = User::factory()->for($org, 'organization')->create();

        $created = $this->actingAs($admin)
            ->postJson('/api/tags', ['name' => 'safety'])
            ->json();

        $this->actingAs($admin)->postJson('/api/tags/attach', [
            'tag_id' => $created['id'],
            'taggable_type' => User::class,
            'taggable_id' => $target->id,
        ]);

        $this->actingAs($admin)->postJson('/api/tags/detach', [
            'tag_id' => $created['id'],
            'taggable_type' => User::class,
            'taggable_id' => $target->id,
        ]);

        Event::assertDispatched(TagCreated::class);
        Event::assertDispatched(TagAttached::class);
        Event::assertDispatched(TagDetached::class);
    }
}
