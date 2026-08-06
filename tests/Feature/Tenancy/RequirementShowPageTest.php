<?php

namespace Tests\Feature\Tenancy;

use App\Models\Organization;
use App\Models\Requirement;
use App\Models\Tag;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Inertia detail page for a requirement (/requirements/{requirement}).
 * RequirementsApiTest covers the CRUD gating the page drives; this covers
 * the page payload itself, which had no test before tags were mounted on it.
 */
class RequirementShowPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_renders_the_show_page_with_the_requirement_data(): void
    {
        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();
        $requirement = Requirement::factory()->for($org, 'organization')->create([
            'name' => 'Confined Space Entrant',
            'description' => 'Annual refresher required.',
        ]);

        $this->actingAs($admin)
            ->get(route('requirements.show', $requirement))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('requirements/Show')
                ->where('requirement.id', $requirement->id)
                ->where('requirement.name', 'Confined Space Entrant')
                ->where('requirement.description', 'Annual refresher required.')
            );
    }

    public function test_page_hydrates_the_requirements_attached_tag_ids(): void
    {
        // TagsField takes its initial state as a prop — the tags store has no
        // per-morphable fetch, so without this the field renders empty on a
        // requirement that has tags.
        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();
        $requirement = Requirement::factory()->for($org, 'organization')->create();
        $attached = Tag::factory()->for($org, 'organization')->create();
        Tag::factory()->for($org, 'organization')->create();
        $requirement->tags()->attach($attached->id);

        $this->actingAs($admin)
            ->get(route('requirements.show', $requirement))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('tagIds', [$attached->id])
            );
    }

    public function test_cross_org_requirement_is_not_found(): void
    {
        $org = Organization::factory()->create();
        $otherOrg = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();
        $foreign = Requirement::factory()->for($otherOrg, 'organization')->create();

        $this->actingAs($admin)
            ->get(route('requirements.show', $foreign))
            ->assertNotFound();
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $org = Organization::factory()->create();
        $requirement = Requirement::factory()->for($org, 'organization')->create();

        $this->get(route('requirements.show', $requirement))->assertRedirect(route('login'));
    }
}
