<?php

namespace Tests\Feature\Tenancy;

use App\Events\CommentCreated;
use App\Events\CommentDeleted;
use App\Events\CommentUpdated;
use App\Models\Organization;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class CommentsApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private function indexUrl(User $target): string
    {
        return '/api/comments?'.http_build_query([
            'commentable_type' => User::class,
            'commentable_id' => $target->id,
        ]);
    }

    public function test_anyone_in_org_can_list_comments_on_a_morphable(): void
    {
        $org = Organization::factory()->create();
        $member = User::factory()->for($org, 'organization')->withRole('None')->create();
        $target = User::factory()->for($org, 'organization')->create();
        $target->comments()->create([
            'org_id' => $org->id,
            'author_id' => $member->id,
            'body' => 'hi',
        ]);

        $this->actingAs($member)
            ->getJson($this->indexUrl($target))
            ->assertOk()
            ->assertJsonCount(1);
    }

    public function test_list_is_org_scoped(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $memberA = User::factory()->for($orgA, 'organization')->create();
        $targetB = User::factory()->for($orgB, 'organization')->create();

        $this->actingAs($memberA)
            ->getJson($this->indexUrl($targetB))
            ->assertForbidden();
    }

    public function test_anyone_in_org_can_post_comment(): void
    {
        $org = Organization::factory()->create();
        $author = User::factory()->for($org, 'organization')->withRole('None')->create();
        $target = User::factory()->for($org, 'organization')->create();

        $this->actingAs($author)
            ->postJson('/api/comments', [
                'commentable_type' => User::class,
                'commentable_id' => $target->id,
                'body' => 'First comment.',
            ])
            ->assertCreated();

        $this->assertDatabaseHas('comments', [
            'org_id' => $org->id,
            'author_id' => $author->id,
            'body' => 'First comment.',
        ]);
    }

    public function test_post_rejects_cross_org_morphable(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $authorA = User::factory()->for($orgA, 'organization')->create();
        $targetB = User::factory()->for($orgB, 'organization')->create();

        $this->actingAs($authorA)
            ->postJson('/api/comments', [
                'commentable_type' => User::class,
                'commentable_id' => $targetB->id,
                'body' => 'sneaky',
            ])
            ->assertForbidden();
    }

    public function test_post_rejects_unknown_commentable_type(): void
    {
        $org = Organization::factory()->create();
        $author = User::factory()->for($org, 'organization')->create();

        $this->actingAs($author)
            ->postJson('/api/comments', [
                'commentable_type' => 'App\\Models\\Organization',
                'commentable_id' => $org->id,
                'body' => 'x',
            ])
            ->assertStatus(422);
    }

    public function test_author_can_edit_own_comment(): void
    {
        $org = Organization::factory()->create();
        $author = User::factory()->for($org, 'organization')->create();
        $target = User::factory()->for($org, 'organization')->create();
        $comment = $target->comments()->create([
            'org_id' => $org->id,
            'author_id' => $author->id,
            'body' => 'first',
        ]);

        $this->actingAs($author)
            ->patchJson("/api/comments/{$comment->id}", ['body' => 'edited'])
            ->assertOk();

        $this->assertSame('edited', $comment->fresh()->body);
    }

    public function test_non_author_cannot_edit_comment_even_as_admin(): void
    {
        $org = Organization::factory()->create();
        $author = User::factory()->for($org, 'organization')->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();
        $target = User::factory()->for($org, 'organization')->create();
        $comment = $target->comments()->create([
            'org_id' => $org->id,
            'author_id' => $author->id,
            'body' => 'mine',
        ]);

        $this->actingAs($admin)
            ->patchJson("/api/comments/{$comment->id}", ['body' => 'hacked'])
            ->assertForbidden();
    }

    public function test_author_can_delete_own_comment(): void
    {
        $org = Organization::factory()->create();
        $author = User::factory()->for($org, 'organization')->create();
        $target = User::factory()->for($org, 'organization')->create();
        $comment = $target->comments()->create([
            'org_id' => $org->id,
            'author_id' => $author->id,
            'body' => 'x',
        ]);

        $this->actingAs($author)
            ->deleteJson("/api/comments/{$comment->id}")
            ->assertOk();

        $this->assertSoftDeleted('comments', ['id' => $comment->id]);
    }

    public function test_admin_can_delete_any_comment(): void
    {
        $org = Organization::factory()->create();
        $author = User::factory()->for($org, 'organization')->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();
        $target = User::factory()->for($org, 'organization')->create();
        $comment = $target->comments()->create([
            'org_id' => $org->id,
            'author_id' => $author->id,
            'body' => 'x',
        ]);

        $this->actingAs($admin)
            ->deleteJson("/api/comments/{$comment->id}")
            ->assertOk();

        $this->assertSoftDeleted('comments', ['id' => $comment->id]);
    }

    public function test_non_author_non_admin_cannot_delete(): void
    {
        $org = Organization::factory()->create();
        $author = User::factory()->for($org, 'organization')->create();
        $other = User::factory()->for($org, 'organization')->withRole('None')->create();
        $target = User::factory()->for($org, 'organization')->create();
        $comment = $target->comments()->create([
            'org_id' => $org->id,
            'author_id' => $author->id,
            'body' => 'x',
        ]);

        $this->actingAs($other)
            ->deleteJson("/api/comments/{$comment->id}")
            ->assertForbidden();
    }

    public function test_cross_org_update_blocked(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $authorB = User::factory()->for($orgB, 'organization')->create();
        $targetB = User::factory()->for($orgB, 'organization')->create();
        $commentB = $targetB->comments()->create([
            'org_id' => $orgB->id,
            'author_id' => $authorB->id,
            'body' => 'x',
        ]);

        $userA = User::factory()->for($orgA, 'organization')->create();

        $this->actingAs($userA)
            ->patchJson("/api/comments/{$commentB->id}", ['body' => 'hacked'])
            ->assertNotFound();
    }

    public function test_list_excludes_soft_deleted(): void
    {
        $org = Organization::factory()->create();
        $author = User::factory()->for($org, 'organization')->create();
        $target = User::factory()->for($org, 'organization')->create();
        $kept = $target->comments()->create([
            'org_id' => $org->id,
            'author_id' => $author->id,
            'body' => 'keep',
        ]);
        $gone = $target->comments()->create([
            'org_id' => $org->id,
            'author_id' => $author->id,
            'body' => 'gone',
        ]);
        $gone->delete();

        $this->actingAs($author)
            ->getJson($this->indexUrl($target))
            ->assertJsonCount(1)
            ->assertJsonFragment(['id' => $kept->id]);
    }

    public function test_create_update_delete_broadcast(): void
    {
        Event::fake([CommentCreated::class, CommentUpdated::class, CommentDeleted::class]);

        $org = Organization::factory()->create();
        $author = User::factory()->for($org, 'organization')->create();
        $target = User::factory()->for($org, 'organization')->create();

        $created = $this->actingAs($author)
            ->postJson('/api/comments', [
                'commentable_type' => User::class,
                'commentable_id' => $target->id,
                'body' => 'hi',
            ])
            ->json();

        $this->actingAs($author)->patchJson("/api/comments/{$created['id']}", ['body' => 'edit']);
        $this->actingAs($author)->deleteJson("/api/comments/{$created['id']}");

        Event::assertDispatched(CommentCreated::class);
        Event::assertDispatched(CommentUpdated::class);
        Event::assertDispatched(CommentDeleted::class);
    }

    public function test_parent_id_column_exists_for_future_threading(): void
    {
        // v14 spec doesn't ship threaded comments, but the schema supports
        // adding threading as a UX layer with no migration. Smoke test that
        // parent_id is fillable + persists.
        $org = Organization::factory()->create();
        $author = User::factory()->for($org, 'organization')->create();
        $target = User::factory()->for($org, 'organization')->create();

        $parent = $target->comments()->create([
            'org_id' => $org->id,
            'author_id' => $author->id,
            'body' => 'top-level',
        ]);
        $reply = $target->comments()->create([
            'org_id' => $org->id,
            'author_id' => $author->id,
            'parent_id' => $parent->id,
            'body' => 'reply',
        ]);

        $this->assertSame($parent->id, $reply->fresh()->parent_id);
    }
}
