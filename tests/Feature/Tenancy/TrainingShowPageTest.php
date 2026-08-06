<?php

namespace Tests\Feature\Tenancy;

use App\Models\CardTemplate;
use App\Models\Organization;
use App\Models\StdFrequency;
use App\Models\Tag;
use App\Models\Training;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Inertia detail page for a training (/trainings/{training}) — the editable
 * form + delete live here. TrainingsApiTest covers the update/destroy API
 * gating that the page drives.
 */
class TrainingShowPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_renders_the_show_page_with_the_training_data(): void
    {
        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();
        $freq = StdFrequency::create(['org_id' => $org->id, 'name' => 'Annual', 'repeat_days' => 365]);
        $training = Training::factory()->for($org, 'organization')->create([
            'name' => 'Fall Protection',
            'repeating' => true,
            'std_freq_id' => $freq->id,
            'cert_title' => 'FP Authorized',
        ]);

        $this->actingAs($admin)
            ->get(route('trainings.show', $training))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('trainings/Show')
                ->where('training.id', $training->id)
                ->where('training.name', 'Fall Protection')
                ->where('training.std_freq_id', $freq->id)
                ->where('training.cert_title', 'FP Authorized')
                ->where('training.std_freq_name', 'Annual')
            );
    }

    /**
     * The page hands the training straight to the same form component the
     * index modal uses, and that form PATCHes every field back. So a field
     * missing from these props is not a display bug — it round-trips as
     * "cleared" and silently detaches the card design (see the companion
     * API test below).
     */
    public function test_page_includes_the_assigned_card_template(): void
    {
        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();
        $template = CardTemplate::factory()->for($org, 'organization')->create();
        $training = Training::factory()->for($org, 'organization')->create([
            'card_template_id' => $template->id,
        ]);

        $this->actingAs($admin)
            ->get(route('trainings.show', $training))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('training.card_template_id', $template->id)
            );
    }

    public function test_page_hydrates_the_trainings_attached_tag_ids(): void
    {
        // TagsField mounts on this page and takes its initial state as a prop
        // rather than fetching — the tags store has no per-morphable fetch, so
        // without this the field renders empty on a training that has tags.
        // Class tag inheritance also reads training tags, so this payload is
        // the only source for it.
        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();
        $training = Training::factory()->for($org, 'organization')->create();
        $attached = Tag::factory()->for($org, 'organization')->create();
        Tag::factory()->for($org, 'organization')->create();
        $training->tags()->attach($attached->id);

        $this->actingAs($admin)
            ->get(route('trainings.show', $training))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('tagIds', [$attached->id])
            );
    }

    public function test_cross_org_training_is_not_found(): void
    {
        // Org-scoped route-model binding rejects a cross-org id outright (404)
        // before the closure's defense-in-depth 403 guard is reached.
        $org = Organization::factory()->create();
        $otherOrg = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();
        $foreign = Training::factory()->for($otherOrg, 'organization')->create();

        $this->actingAs($admin)
            ->get(route('trainings.show', $foreign))
            ->assertNotFound();
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $org = Organization::factory()->create();
        $training = Training::factory()->for($org, 'organization')->create();

        $this->get(route('trainings.show', $training))->assertRedirect(route('login'));
    }
}
