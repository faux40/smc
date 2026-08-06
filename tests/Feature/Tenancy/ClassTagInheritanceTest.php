<?php

namespace Tests\Feature\Tenancy;

use App\Models\Organization;
use App\Models\Tag;
use App\Models\Training;
use App\Models\TrainingClass;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Classes are taggable, and a class inherits the tags of every training it
 * covers — merged at attach time, following the cert-content snapshot that
 * ClassesController::snapshotTraining() already performs.
 *
 * The semantics under test, all deliberate:
 *  - union across topics, deduped (syncWithoutDetaching)
 *  - snapshot, not derived: later edits to the training don't reach the class
 *  - detaching a topic leaves the class's tags alone (no provenance is stored,
 *    so removing them would delete tags a person may have added by hand)
 */
class ClassTagInheritanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private function managerOf(Organization $org): User
    {
        return User::factory()->for($org, 'organization')->withRole('Admin')->create();
    }

    public function test_class_is_taggable_via_the_api(): void
    {
        $org = Organization::factory()->create();
        $admin = $this->managerOf($org);
        $class = TrainingClass::factory()->for($org, 'organization')->create();
        $tag = Tag::factory()->for($org, 'organization')->create();

        $this->actingAs($admin)
            ->postJson('/api/tags/attach', [
                'tag_id' => $tag->id,
                'taggable_type' => TrainingClass::class,
                'taggable_id' => $class->id,
            ])
            ->assertOk();

        $this->assertTrue($class->fresh()->tags->contains('id', $tag->id));
    }

    public function test_creating_a_class_inherits_its_trainings_tags(): void
    {
        $org = Organization::factory()->create();
        $admin = $this->managerOf($org);
        $tagA = Tag::factory()->for($org, 'organization')->create(['name' => 'first-aid']);
        $tagB = Tag::factory()->for($org, 'organization')->create(['name' => 'annual']);
        $training = Training::factory()->for($org, 'organization')->create();
        $training->tags()->attach([$tagA->id, $tagB->id]);

        $response = $this->actingAs($admin)
            ->postJson('/api/classes', [
                'name' => 'Spring session',
                'scheduled_date' => '2026-09-01',
                'training_ids' => [$training->id],
            ])
            ->assertCreated();

        $class = TrainingClass::findOrFail($response->json('id'));
        $this->assertEqualsCanonicalizing(
            [$tagA->id, $tagB->id],
            $class->tags->pluck('id')->all(),
        );
    }

    public function test_tags_from_multiple_topics_are_unioned_and_deduped(): void
    {
        $org = Organization::factory()->create();
        $admin = $this->managerOf($org);
        $shared = Tag::factory()->for($org, 'organization')->create(['name' => 'shared']);
        $onlyA = Tag::factory()->for($org, 'organization')->create(['name' => 'only-a']);
        $onlyB = Tag::factory()->for($org, 'organization')->create(['name' => 'only-b']);

        $a = Training::factory()->for($org, 'organization')->create();
        $b = Training::factory()->for($org, 'organization')->create();
        $a->tags()->attach([$shared->id, $onlyA->id]);
        $b->tags()->attach([$shared->id, $onlyB->id]);

        $response = $this->actingAs($admin)
            ->postJson('/api/classes', [
                'name' => 'Combined',
                'scheduled_date' => '2026-09-01',
                'training_ids' => [$a->id, $b->id],
            ])
            ->assertCreated();

        $class = TrainingClass::findOrFail($response->json('id'));
        $this->assertEqualsCanonicalizing(
            [$shared->id, $onlyA->id, $onlyB->id],
            $class->tags->pluck('id')->all(),
        );
    }

    public function test_attaching_a_topic_later_merges_that_trainings_tags(): void
    {
        $org = Organization::factory()->create();
        $admin = $this->managerOf($org);
        $existing = Tag::factory()->for($org, 'organization')->create(['name' => 'hand-added']);
        $inherited = Tag::factory()->for($org, 'organization')->create(['name' => 'forklift']);
        $class = TrainingClass::factory()->for($org, 'organization')->create();
        $class->tags()->attach($existing->id);
        $training = Training::factory()->for($org, 'organization')->create();
        $training->tags()->attach($inherited->id);

        $this->actingAs($admin)
            ->postJson("/api/classes/{$class->id}/trainings", ['training_id' => $training->id])
            ->assertOk();

        $this->assertEqualsCanonicalizing(
            [$existing->id, $inherited->id],
            $class->fresh()->tags->pluck('id')->all(),
            'a hand-added class tag must survive inheritance',
        );
    }

    public function test_inheritance_is_a_snapshot_not_a_live_derivation(): void
    {
        $org = Organization::factory()->create();
        $admin = $this->managerOf($org);
        $atAttach = Tag::factory()->for($org, 'organization')->create(['name' => 'at-attach']);
        $addedLater = Tag::factory()->for($org, 'organization')->create(['name' => 'added-later']);
        $training = Training::factory()->for($org, 'organization')->create();
        $training->tags()->attach($atAttach->id);

        $response = $this->actingAs($admin)
            ->postJson('/api/classes', [
                'name' => 'Snapshot',
                'scheduled_date' => '2026-09-01',
                'training_ids' => [$training->id],
            ])
            ->assertCreated();

        // Tag the training *after* the class exists.
        $training->tags()->attach($addedLater->id);

        $class = TrainingClass::findOrFail($response->json('id'));
        $this->assertSame([$atAttach->id], $class->tags->pluck('id')->all());
    }

    public function test_detaching_a_topic_leaves_the_classes_tags_alone(): void
    {
        // No provenance is stored, so a tag cannot be proven to be inherited
        // rather than deliberate — removing it would silently delete someone's
        // work. Mirrors prefillClassVenue, which never un-fills.
        $org = Organization::factory()->create();
        $admin = $this->managerOf($org);
        $tag = Tag::factory()->for($org, 'organization')->create();
        $training = Training::factory()->for($org, 'organization')->create();
        $training->tags()->attach($tag->id);

        $response = $this->actingAs($admin)
            ->postJson('/api/classes', [
                'name' => 'Detach me',
                'scheduled_date' => '2026-09-01',
                'training_ids' => [$training->id],
            ])
            ->assertCreated();

        $class = TrainingClass::findOrFail($response->json('id'));
        $topic = $class->classTrainings()->firstOrFail();

        $this->actingAs($admin)
            ->deleteJson("/api/classes/{$class->id}/trainings/{$topic->id}")
            ->assertOk();

        $this->assertSame([$tag->id], $class->fresh()->tags->pluck('id')->all());
    }

    public function test_a_training_with_no_tags_leaves_the_class_untouched(): void
    {
        $org = Organization::factory()->create();
        $admin = $this->managerOf($org);
        $training = Training::factory()->for($org, 'organization')->create();

        $response = $this->actingAs($admin)
            ->postJson('/api/classes', [
                'name' => 'Untagged',
                'scheduled_date' => '2026-09-01',
                'training_ids' => [$training->id],
            ])
            ->assertCreated();

        $class = TrainingClass::findOrFail($response->json('id'));
        $this->assertCount(0, $class->tags);
    }

    public function test_inherited_pivot_rows_carry_the_org(): void
    {
        // Inheritance writes taggables rows outside TagsController, so it must
        // satisfy the same NOT NULL org stamp.
        $org = Organization::factory()->create();
        $admin = $this->managerOf($org);
        $tag = Tag::factory()->for($org, 'organization')->create();
        $training = Training::factory()->for($org, 'organization')->create();
        $training->tags()->attach($tag->id);

        $response = $this->actingAs($admin)
            ->postJson('/api/classes', [
                'name' => 'Stamped',
                'scheduled_date' => '2026-09-01',
                'training_ids' => [$training->id],
            ])
            ->assertCreated();

        $this->assertDatabaseHas('taggables', [
            'taggable_type' => TrainingClass::class,
            'taggable_id' => $response->json('id'),
            'org_id' => $org->id,
        ]);
    }

    public function test_create_accepts_explicit_tag_ids_and_unions_them_with_inherited(): void
    {
        // Drives the duplicate-class flow: it rebuilds topics from the live
        // training library rather than copying the source's rows, so without
        // an explicit list the copy would silently lose tags added to the
        // source class by hand.
        $org = Organization::factory()->create();
        $admin = $this->managerOf($org);
        $inherited = Tag::factory()->for($org, 'organization')->create(['name' => 'inherited']);
        $explicit = Tag::factory()->for($org, 'organization')->create(['name' => 'hand-added']);
        $training = Training::factory()->for($org, 'organization')->create();
        $training->tags()->attach($inherited->id);

        $response = $this->actingAs($admin)
            ->postJson('/api/classes', [
                'name' => 'Copy of Spring session',
                'scheduled_date' => '2026-09-01',
                'training_ids' => [$training->id],
                'tag_ids' => [$explicit->id],
            ])
            ->assertCreated();

        $class = TrainingClass::findOrFail($response->json('id'));
        $this->assertEqualsCanonicalizing(
            [$inherited->id, $explicit->id],
            $class->tags->pluck('id')->all(),
        );
    }

    public function test_create_rejects_a_cross_org_tag_id(): void
    {
        $org = Organization::factory()->create();
        $otherOrg = Organization::factory()->create();
        $admin = $this->managerOf($org);
        $foreign = Tag::factory()->for($otherOrg, 'organization')->create();

        $this->actingAs($admin)
            ->postJson('/api/classes', [
                'name' => 'Leaky',
                'scheduled_date' => '2026-09-01',
                'tag_ids' => [$foreign->id],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('tag_ids.0');
    }

    public function test_class_detail_exposes_its_tag_ids(): void
    {
        $org = Organization::factory()->create();
        $admin = $this->managerOf($org);
        $class = TrainingClass::factory()->for($org, 'organization')->create();
        $tag = Tag::factory()->for($org, 'organization')->create();
        $class->tags()->attach($tag->id);

        $this->actingAs($admin)
            ->getJson("/api/classes/{$class->id}")
            ->assertOk()
            ->assertJsonPath('tag_ids', [$tag->id]);
    }
}
