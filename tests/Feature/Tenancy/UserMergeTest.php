<?php

namespace Tests\Feature\Tenancy;

use App\Models\Attachment;
use App\Models\ClassEnrollment;
use App\Models\Comment;
use App\Models\Completion;
use App\Models\Organization;
use App\Models\Tag;
use App\Models\Training;
use App\Models\TrainingAssignment;
use App\Models\TrainingClass;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Combine-users (de-duplication) tool: POST /users/merge folds a duplicate
 * into a survivor, reassigning every owned/authored record, resolving
 * conflicting profile fields, stashing discards in notes, and soft-deleting
 * the duplicate.
 */
class UserMergeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private function org(): Organization
    {
        return Organization::factory()->create();
    }

    public function test_merge_reassigns_records_and_soft_deletes_the_duplicate(): void
    {
        $org = $this->org();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();
        $survivor = User::factory()->for($org, 'organization')->withRole('None')->create();
        $duplicate = User::factory()->for($org, 'organization')->withRole('None')->create([
            'email' => 'dupe@example.com',
        ]);

        $training = Training::factory()->for($org, 'organization')->create();
        $class = TrainingClass::factory()->for($org, 'organization')->create();
        $report = User::factory()->for($org, 'organization')->create(['supervisor_id' => $duplicate->id]);

        $completion = Completion::factory()->create([
            'org_id' => $org->id,
            'user_id' => $duplicate->id,
            'module_type' => Training::class,
            'module_id' => $training->id,
        ]);
        $ta = TrainingAssignment::factory()->create([
            'org_id' => $org->id,
            'user_id' => $duplicate->id,
            'training_id' => $training->id,
        ]);
        $enrollment = ClassEnrollment::create([
            'class_id' => $class->id,
            'user_id' => $duplicate->id,
            'status' => 'enrolled',
        ]);
        $comment = Comment::factory()->create([
            'org_id' => $org->id,
            'author_id' => $duplicate->id,
            'commentable_type' => Training::class,
            'commentable_id' => $training->id,
        ]);
        $attachment = Attachment::factory()->create([
            'org_id' => $org->id,
            'uploaded_by_user_id' => $duplicate->id,
            'attachable_type' => Training::class,
            'attachable_id' => $training->id,
        ]);
        $tag = Tag::factory()->create(['org_id' => $org->id]);
        $duplicate->tags()->attach($tag->id);

        $this->actingAs($admin)
            ->postJson(route('users.merge'), [
                'survivor_id' => $survivor->id,
                'duplicate_id' => $duplicate->id,
            ])
            ->assertOk()
            ->assertJsonPath('duplicate_id', $duplicate->id)
            ->assertJsonPath('survivor.id', $survivor->id);

        // Records moved to the survivor.
        $this->assertSame($survivor->id, $completion->refresh()->user_id);
        $this->assertSame($survivor->id, $ta->refresh()->user_id);
        $this->assertSame($survivor->id, $enrollment->refresh()->user_id);
        $this->assertSame($survivor->id, $comment->refresh()->author_id);
        $this->assertSame($survivor->id, $attachment->refresh()->uploaded_by_user_id);
        $this->assertSame($survivor->id, $report->refresh()->supervisor_id);
        $this->assertTrue($survivor->fresh()->tags()->where('tags.id', $tag->id)->exists());

        // Duplicate soft-deleted with its email cleared (frees the unique index).
        $this->assertSoftDeleted('users', ['id' => $duplicate->id]);
        $this->assertNull($duplicate->fresh()->email);
    }

    public function test_conflicting_field_choice_applies_and_stashes_discard_in_notes(): void
    {
        $org = $this->org();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();
        $survivor = User::factory()->for($org, 'organization')->withRole('None')->create([
            'job_title' => 'Operator',
        ]);
        $duplicate = User::factory()->for($org, 'organization')->withRole('Manager')->create([
            'job_title' => 'Lead Operator',
            'email' => 'dupe@example.com',
        ]);

        $this->actingAs($admin)
            ->postJson(route('users.merge'), [
                'survivor_id' => $survivor->id,
                'duplicate_id' => $duplicate->id,
                'fields' => ['job_title' => 'duplicate'],
            ])
            ->assertOk();

        $survivor->refresh();
        // Chosen value won; the survivor keeps their own role (one-role invariant).
        $this->assertSame('Lead Operator', $survivor->job_title);
        $this->assertTrue($survivor->hasRole('None'));

        // The discarded job title + the duplicate's role land in notes.
        $this->assertStringContainsString('Combined duplicate record', $survivor->notes);
        $this->assertStringContainsString('Operator', $survivor->notes);
        $this->assertStringContainsString('Manager', $survivor->notes);
    }

    public function test_survivor_can_adopt_the_duplicate_email_without_unique_collision(): void
    {
        $org = $this->org();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();
        $survivor = User::factory()->for($org, 'organization')->withRole('None')->create(['email' => null]);
        $duplicate = User::factory()->for($org, 'organization')->withRole('None')->create([
            'email' => 'keep@example.com',
        ]);

        $this->actingAs($admin)
            ->postJson(route('users.merge'), [
                'survivor_id' => $survivor->id,
                'duplicate_id' => $duplicate->id,
                'fields' => ['email' => 'duplicate'],
            ])
            ->assertOk();

        $this->assertSame('keep@example.com', $survivor->refresh()->email);
        $this->assertNull($duplicate->fresh()->email);
    }

    public function test_training_assignment_collision_merges_sources_into_one(): void
    {
        $org = $this->org();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();
        $survivor = User::factory()->for($org, 'organization')->withRole('None')->create();
        $duplicate = User::factory()->for($org, 'organization')->withRole('None')->create();
        $training = Training::factory()->for($org, 'organization')->create();

        // Both already have a direct assignment for the same training.
        $survTa = TrainingAssignment::factory()->create([
            'org_id' => $org->id, 'user_id' => $survivor->id, 'training_id' => $training->id,
        ]);
        $survTa->sources()->create(['sourceable_type' => null, 'sourceable_id' => null, 'added_at' => now()]);
        $dupTa = TrainingAssignment::factory()->create([
            'org_id' => $org->id, 'user_id' => $duplicate->id, 'training_id' => $training->id,
        ]);
        $dupTa->sources()->create(['sourceable_type' => null, 'sourceable_id' => null, 'added_at' => now()]);

        $this->actingAs($admin)
            ->postJson(route('users.merge'), [
                'survivor_id' => $survivor->id,
                'duplicate_id' => $duplicate->id,
            ])
            ->assertOk();

        // Survivor keeps exactly one assignment for the training; dup's is gone.
        $this->assertSame(
            1,
            TrainingAssignment::where('user_id', $survivor->id)->where('training_id', $training->id)->count(),
        );
        $this->assertDatabaseMissing('training_assignments', ['id' => $dupTa->id]);
    }

    public function test_preview_returns_field_diff_and_record_counts(): void
    {
        $org = $this->org();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();
        $survivor = User::factory()->for($org, 'organization')->withRole('None')->create(['job_title' => 'A']);
        $duplicate = User::factory()->for($org, 'organization')->withRole('None')->create(['job_title' => 'B']);
        $training = Training::factory()->for($org, 'organization')->create();
        Completion::factory()->create([
            'org_id' => $org->id, 'user_id' => $duplicate->id,
            'module_type' => Training::class, 'module_id' => $training->id,
        ]);

        $this->actingAs($admin)
            ->getJson(route('users.merge-preview', ['survivor' => $survivor->id, 'duplicate' => $duplicate->id]))
            ->assertOk()
            ->assertJsonPath('counts.completions', 1)
            ->assertJsonPath('survivor.id', $survivor->id)
            ->assertJsonFragment(['key' => 'job_title', 'label' => 'Job title', 'survivor' => 'A', 'duplicate' => 'B', 'differs' => true, 'default' => 'survivor']);
    }

    public function test_non_admin_cannot_merge(): void
    {
        $org = $this->org();
        $actor = User::factory()->for($org, 'organization')->withRole('Manager')->create();
        $survivor = User::factory()->for($org, 'organization')->withRole('None')->create();
        $duplicate = User::factory()->for($org, 'organization')->withRole('None')->create();

        $this->actingAs($actor)
            ->postJson(route('users.merge'), [
                'survivor_id' => $survivor->id,
                'duplicate_id' => $duplicate->id,
            ])
            ->assertForbidden();
    }

    public function test_cannot_merge_an_owner_as_the_duplicate(): void
    {
        $org = $this->org();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();
        $survivor = User::factory()->for($org, 'organization')->withRole('None')->create();
        $owner = User::factory()->for($org, 'organization')->withRole('Owner')->create();

        $this->actingAs($admin)
            ->postJson(route('users.merge'), [
                'survivor_id' => $survivor->id,
                'duplicate_id' => $owner->id,
            ])
            ->assertForbidden();
    }

    public function test_cannot_merge_a_user_into_itself(): void
    {
        $org = $this->org();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();
        $user = User::factory()->for($org, 'organization')->withRole('None')->create();

        $this->actingAs($admin)
            ->postJson(route('users.merge'), [
                'survivor_id' => $user->id,
                'duplicate_id' => $user->id,
            ])
            ->assertStatus(422);
    }

    public function test_cross_org_duplicate_is_rejected(): void
    {
        $org = $this->org();
        $other = $this->org();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();
        $survivor = User::factory()->for($org, 'organization')->withRole('None')->create();
        $foreign = User::factory()->for($other, 'organization')->withRole('None')->create();

        // A cross-org duplicate isn't visible (org scope) → forbidden.
        $this->actingAs($admin)
            ->postJson(route('users.merge'), [
                'survivor_id' => $survivor->id,
                'duplicate_id' => $foreign->id,
            ])
            ->assertForbidden();
    }
}
