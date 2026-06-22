<?php

namespace Tests\Feature\Tenancy;

use App\Models\AssignmentSource;
use App\Models\Organization;
use App\Models\Requirement;
use App\Models\Tag;
use App\Models\Training;
use App\Models\TrainingAssignment;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Server-paged assignments grouped by user (GET /api/training-assignments/by-user).
 */
class AssignmentsByUserTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/training-assignments/by-user';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private function manager(Organization $org): User
    {
        return User::factory()->for($org, 'organization')->withRole('Manager')->create();
    }

    private function assign(Organization $org, User $user, string $trainingName): TrainingAssignment
    {
        $training = Training::factory()->for($org, 'organization')->create(['name' => $trainingName]);

        return TrainingAssignment::factory()->create([
            'org_id' => $org->id,
            'user_id' => $user->id,
            'training_id' => $training->id,
            'name' => $trainingName,
        ]);
    }

    public function test_requires_manager(): void
    {
        $org = Organization::factory()->create();
        $member = User::factory()->for($org, 'organization')->withRole('None')->create();

        $this->actingAs($member)->getJson(self::URL)->assertForbidden();
    }

    public function test_returns_users_with_their_assignments_and_counts(): void
    {
        $org = Organization::factory()->create();
        $manager = $this->manager($org);
        $alice = User::factory()->for($org, 'organization')->create(['f_name' => 'Alice', 'l_name' => 'Adams']);
        $this->assign($org, $alice, 'Forklift');
        $this->assign($org, $alice, 'Ladders');

        $rows = $this->actingAs($manager)->getJson(self::URL)->assertOk()->json('data');
        $aliceRow = collect($rows)->firstWhere('user_id', $alice->id);

        $this->assertNotNull($aliceRow);
        $this->assertSame(2, $aliceRow['assignments_count']);
        $this->assertCount(2, $aliceRow['assignments']);
        $this->assertEqualsCanonicalizing(
            ['Forklift', 'Ladders'],
            collect($aliceRow['assignments'])->pluck('name')->all(),
        );
    }

    public function test_paginates_and_clamps_per_page(): void
    {
        $org = Organization::factory()->create();
        $manager = $this->manager($org);
        User::factory()->for($org, 'organization')->count(5)->create(); // 6 incl. manager

        $this->actingAs($manager)
            ->getJson(self::URL.'?per_page=2&page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 6)
            ->assertJsonPath('meta.last_page', 3);

        $this->actingAs($manager)->getJson(self::URL.'?per_page=9999')
            ->assertJsonPath('meta.per_page', 100);
    }

    public function test_user_search_matches_user_fields_only(): void
    {
        $org = Organization::factory()->create();
        $manager = $this->manager($org);
        $alice = User::factory()->for($org, 'organization')->create(['f_name' => 'Alice', 'l_name' => 'Adams', 'department' => 'Welding']);
        User::factory()->for($org, 'organization')->create(['f_name' => 'Bob', 'l_name' => 'Baker']);

        $ids = fn (string $q) => collect($this->actingAs($manager)->getJson(self::URL.'?user_q='.$q)->json('data'))->pluck('user_id');
        $this->assertTrue($ids('welding')->contains($alice->id));
        $this->assertCount(1, $ids('welding'));
    }

    public function test_generic_search_matches_training_names_only(): void
    {
        $org = Organization::factory()->create();
        $manager = $this->manager($org);
        $alice = User::factory()->for($org, 'organization')->create(['f_name' => 'Alice']);
        $bob = User::factory()->for($org, 'organization')->create(['f_name' => 'Bob']);
        $this->assign($org, $alice, 'Forklift Safety');
        $this->assign($org, $bob, 'Ladders');

        // Search "forklift" → only Alice (training match), even though no user
        // is named forklift (users are excluded from the generic search).
        $rows = $this->actingAs($manager)->getJson(self::URL.'?q=forklift')->json('data');
        $this->assertSame([$alice->id], collect($rows)->pluck('user_id')->all());
    }

    public function test_requirement_filter_or(): void
    {
        $org = Organization::factory()->create();
        $manager = $this->manager($org);
        $req = Requirement::factory()->for($org, 'organization')->create();
        $sourced = User::factory()->for($org, 'organization')->create();
        $direct = User::factory()->for($org, 'organization')->create();
        $ta = $this->assign($org, $sourced, 'Forklift');
        $this->assign($org, $direct, 'Ladders');
        AssignmentSource::create([
            'training_assignment_id' => $ta->id,
            'sourceable_type' => Requirement::class,
            'sourceable_id' => $req->id,
            'added_at' => now(),
        ]);

        $rows = $this->actingAs($manager)
            ->getJson(self::URL.'?requirements[]='.$req->id.'&req_mode=or')
            ->json('data');
        $this->assertSame([$sourced->id], collect($rows)->pluck('user_id')->all());
    }

    public function test_tag_filter_and_sort_by_assignment_count(): void
    {
        $org = Organization::factory()->create();
        $manager = $this->manager($org);
        $tag = Tag::factory()->for($org, 'organization')->create();
        $tagged = User::factory()->for($org, 'organization')->create();
        $tagged->tags()->attach($tag->id);
        User::factory()->for($org, 'organization')->create();

        // Tag filter (and).
        $rows = $this->actingAs($manager)
            ->getJson(self::URL.'?tags[]='.$tag->id.'&tags_mode=and')
            ->json('data');
        $this->assertSame([$tagged->id], collect($rows)->pluck('user_id')->all());

        // Sort by assignment count desc — most-assigned user first.
        $busy = User::factory()->for($org, 'organization')->create();
        $this->assign($org, $busy, 'A');
        $this->assign($org, $busy, 'B');
        $top = $this->actingAs($manager)
            ->getJson(self::URL.'?sort=assignments&dir=desc')
            ->json('data');
        $this->assertSame($busy->id, $top[0]['user_id']);
    }
}
