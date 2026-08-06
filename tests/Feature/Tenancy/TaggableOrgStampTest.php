<?php

namespace Tests\Feature\Tenancy;

use App\Models\Organization;
use App\Models\Tag;
use App\Models\Training;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * `taggables` carries its own `org_id`.
 *
 * Reads of `$model->tags` lean on Tag's `organization` global scope, but that
 * scope is a deliberate no-op whenever `currentOrgId` is unbound — queue jobs,
 * console commands and seeders all run that way. So the pivot cannot rely on
 * the join for tenancy; it carries the org itself, stamped by HasTags so no
 * call site can forget it.
 */
class TaggableOrgStampTest extends TestCase
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

    public function test_relation_attach_stamps_org_id(): void
    {
        $org = Organization::factory()->create();
        $training = Training::factory()->for($org, 'organization')->create();
        $tag = Tag::factory()->for($org, 'organization')->create();

        $training->tags()->attach($tag->id);

        $this->assertSame(
            $org->id,
            DB::table('taggables')->where('tag_id', $tag->id)->value('org_id'),
        );
    }

    public function test_api_attach_stamps_org_id(): void
    {
        $org = Organization::factory()->create();
        $owner = $this->ownerOf($org);
        $target = User::factory()->for($org, 'organization')->create();
        $tag = Tag::factory()->for($org, 'organization')->create();

        $this->actingAs($owner)
            ->postJson('/api/tags/attach', [
                'tag_id' => $tag->id,
                'taggable_type' => User::class,
                'taggable_id' => $target->id,
            ])
            ->assertOk();

        $this->assertSame(
            $org->id,
            DB::table('taggables')->where('tag_id', $tag->id)->value('org_id'),
        );
    }

    public function test_eager_loading_tags_still_resolves(): void
    {
        // Regression guard: stamping must not be implemented with an
        // unconditional withPivotValue(). Eager loading builds the relation
        // from an *empty* model instance (Builder::getRelation calls
        // newInstance()), so a pivot constraint read off $this->org_id would
        // become `org_id is null` there and silently return nothing.
        $org = Organization::factory()->create();
        $tag = Tag::factory()->for($org, 'organization')->create();
        $a = Training::factory()->for($org, 'organization')->create();
        $b = Training::factory()->for($org, 'organization')->create();
        $a->tags()->attach($tag->id);
        $b->tags()->attach($tag->id);

        $loaded = Training::query()->with('tags')->whereIn('id', [$a->id, $b->id])->get();

        $this->assertCount(2, $loaded);
        foreach ($loaded as $training) {
            $this->assertCount(1, $training->tags, 'eager-loaded tags went missing');
        }
    }

    public function test_lazy_loaded_tags_still_resolve(): void
    {
        $org = Organization::factory()->create();
        $training = Training::factory()->for($org, 'organization')->create();
        $tag = Tag::factory()->for($org, 'organization')->create();
        $training->tags()->attach($tag->id);

        $this->assertCount(1, $training->fresh()->tags);
    }

    public function test_detach_still_removes_the_row(): void
    {
        $org = Organization::factory()->create();
        $training = Training::factory()->for($org, 'organization')->create();
        $tag = Tag::factory()->for($org, 'organization')->create();
        $training->tags()->attach($tag->id);

        $training->tags()->detach($tag->id);

        $this->assertDatabaseCount('taggables', 0);
    }

    public function test_org_id_is_not_nullable(): void
    {
        // The guarantee is only worth having if it cannot be bypassed: a raw
        // insert that skips the stamp must fail at the database, not quietly
        // create an unattributable row.
        $org = Organization::factory()->create();
        $training = Training::factory()->for($org, 'organization')->create();
        $tag = Tag::factory()->for($org, 'organization')->create();

        $this->expectException(QueryException::class);

        DB::table('taggables')->insert([
            'tag_id' => $tag->id,
            'taggable_type' => Training::class,
            'taggable_id' => $training->id,
        ]);
    }
}
