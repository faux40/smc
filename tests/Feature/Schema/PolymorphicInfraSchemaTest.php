<?php

namespace Tests\Feature\Schema;

use App\Models\Attachment;
use App\Models\Comment;
use App\Models\Organization;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Phase 5.3 schema scaffold for the polymorphic infrastructure:
 * tags + taggables, comments, attachments, plus spatie/laravel-activitylog.
 * Smoke-tests the traits on a User as the first consumer.
 */
class PolymorphicInfraSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_tags_table_shape(): void
    {
        $this->assertTrue(Schema::hasColumns('tags', [
            'id', 'org_id', 'name', 'color', 'created_at', 'updated_at', 'deleted_at',
        ]));
    }

    public function test_taggables_pivot_shape(): void
    {
        $this->assertTrue(Schema::hasColumns('taggables', [
            'tag_id', 'taggable_type', 'taggable_id',
        ]));
    }

    public function test_comments_table_shape(): void
    {
        $this->assertTrue(Schema::hasColumns('comments', [
            'id', 'org_id', 'commentable_type', 'commentable_id',
            'author_id', 'body',
            'created_at', 'updated_at', 'deleted_at',
        ]));
    }

    public function test_attachments_table_shape(): void
    {
        $this->assertTrue(Schema::hasColumns('attachments', [
            'id', 'org_id', 'attachable_type', 'attachable_id',
            'filename', 'mime', 'size', 'disk', 'path',
            'uploaded_by_user_id',
            'created_at', 'updated_at', 'deleted_at',
        ]));
    }

    public function test_activity_log_tables_exist(): void
    {
        // spatie/laravel-activitylog publishes activity_log + log batches.
        $this->assertTrue(Schema::hasTable('activity_log'));
        $this->assertTrue(Schema::hasColumns('activity_log', [
            'id', 'log_name', 'description',
            'subject_type', 'subject_id', 'causer_type', 'causer_id',
            'properties', 'created_at', 'updated_at',
        ]));
    }

    public function test_has_tags_trait_attaches_to_user(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->for($org, 'organization')->create();
        $tag = Tag::factory()->for($org, 'organization')->create();

        $user->tags()->attach($tag);

        $this->assertSame(1, $user->fresh()->tags->count());
        $this->assertSame($tag->id, $user->fresh()->tags->first()->id);
    }

    public function test_has_comments_trait_attaches_to_user(): void
    {
        $org = Organization::factory()->create();
        $author = User::factory()->for($org, 'organization')->create();
        $target = User::factory()->for($org, 'organization')->create();

        $comment = $target->comments()->create([
            'org_id' => $org->id,
            'author_id' => $author->id,
            'body' => 'First comment.',
        ]);

        $this->assertSame(1, $target->fresh()->comments->count());
        $this->assertSame($author->id, $comment->author_id);
    }

    public function test_has_attachments_trait_attaches_to_user(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->for($org, 'organization')->create();
        $uploader = User::factory()->for($org, 'organization')->create();

        $att = $user->attachments()->create([
            'org_id' => $org->id,
            'filename' => 'cert.pdf',
            'mime' => 'application/pdf',
            'size' => 12_345,
            'disk' => 'linode',
            'path' => 'attachments/abc.pdf',
            'uploaded_by_user_id' => $uploader->id,
        ]);

        $this->assertSame(1, $user->fresh()->attachments->count());
        $this->assertSame('cert.pdf', $att->filename);
    }

    public function test_comment_preserves_author_after_user_soft_delete(): void
    {
        // Decision (5.3): comment author_id stays pointing at the soft-deleted
        // user, preserving org history. No CASCADE.
        $org = Organization::factory()->create();
        $author = User::factory()->for($org, 'organization')->create();
        $target = User::factory()->for($org, 'organization')->create();

        $comment = $target->comments()->create([
            'org_id' => $org->id,
            'author_id' => $author->id,
            'body' => 'Comment from someone who later leaves.',
        ]);

        $author->delete();

        $this->assertSame($author->id, $comment->fresh()->author_id);
    }
}
