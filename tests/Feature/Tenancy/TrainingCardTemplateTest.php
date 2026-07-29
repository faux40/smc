<?php

namespace Tests\Feature\Tenancy;

use App\Models\CardTemplate;
use App\Models\Organization;
use App\Models\Training;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuildsPresentationFixtures;
use Tests\TestCase;

/**
 * A training carries the card template its classes print by default, so a
 * Manager closing out a First Aid class doesn't have to know which design is
 * the right one.
 */
class TrainingCardTemplateTest extends TestCase
{
    use BuildsPresentationFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        Storage::fake('linode');
    }

    private function payload(array $overrides = []): array
    {
        // as-needed keeps the frequency rules out of the way; this suite is
        // about the card template, not the scheduling shape.
        return array_merge([
            'name' => 'First Aid / CPR',
            'initial_only' => false,
            'repeating' => false,
            'as_needed' => true,
            'std_freq_id' => null,
        ], $overrides);
    }

    public function test_a_training_can_be_given_a_card_template(): void
    {
        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();
        $template = CardTemplate::factory()->for($org, 'organization')->create();

        $this->actingAs($admin)
            ->postJson('/api/trainings', $this->payload([
                'card_template_id' => $template->id,
            ]))
            ->assertCreated()
            ->assertJsonPath('card_template_id', $template->id);

        $this->assertDatabaseHas('trainings', [
            'name' => 'First Aid / CPR',
            'card_template_id' => $template->id,
        ]);
    }

    public function test_a_training_without_a_card_template_is_fine(): void
    {
        // Most trainings print the built-in SMC certificate and no card.
        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();

        $this->actingAs($admin)
            ->postJson('/api/trainings', $this->payload())
            ->assertCreated()
            ->assertJsonPath('card_template_id', null);
    }

    public function test_a_system_card_template_can_be_assigned(): void
    {
        // System templates are read-only, not unusable.
        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();
        $system = CardTemplate::factory()->system()->create();

        $this->actingAs($admin)
            ->postJson('/api/trainings', $this->payload(['card_template_id' => $system->id]))
            ->assertCreated()
            ->assertJsonPath('card_template_id', $system->id);
    }

    public function test_another_orgs_card_template_cannot_be_assigned(): void
    {
        $org = Organization::factory()->create();
        $other = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();
        $foreign = CardTemplate::factory()->for($other, 'organization')->create();

        $this->actingAs($admin)
            ->postJson('/api/trainings', $this->payload(['card_template_id' => $foreign->id]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('card_template_id');
    }

    /**
     * The training form is a full-form PATCH: every field it owns is sent on
     * every save, so an absent key means "cleared", exactly as it does for
     * nickname or cert_title. Pinned deliberately — the trap is a *caller*
     * that forgets the key (the show page did, and wiped assignments), not
     * this rule, which is what keeps "unassign the card" working.
     */
    public function test_update_treats_an_omitted_card_template_as_cleared(): void
    {
        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();
        $template = CardTemplate::factory()->for($org, 'organization')->create();
        $training = Training::factory()->for($org, 'organization')->create([
            'card_template_id' => $template->id,
        ]);

        $this->actingAs($admin)
            ->patchJson("/api/trainings/{$training->id}", $this->payload())
            ->assertOk()
            ->assertJsonPath('card_template_id', null);
    }

    public function test_replacing_a_template_moves_the_assignment_to_the_new_version(): void
    {
        // Replace = a new version of the same design. A training pointing at
        // the old row must follow it, or uploading a fix would silently
        // detach every training that used it.
        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();
        $template = CardTemplate::factory()->for($org, 'organization')->create();
        $training = Training::factory()->for($org, 'organization')->create([
            'card_template_id' => $template->id,
        ]);

        $file = new UploadedFile($this->makePptxFixture(), 'card.pptx', null, null, true);

        $newId = $this->actingAs($admin)
            ->post("/api/card-templates/{$template->id}/replace", ['file' => $file])
            ->assertOk()
            ->json('id');

        $this->assertSame($newId, $training->fresh()->card_template_id);
    }

    public function test_deleting_a_template_detaches_it_from_trainings(): void
    {
        // The row is soft-deleted, so the FK would still "resolve" to a
        // deleted design and print nothing. Detach explicitly instead.
        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();
        $template = CardTemplate::factory()->for($org, 'organization')->create();
        $training = Training::factory()->for($org, 'organization')->create([
            'card_template_id' => $template->id,
        ]);

        $this->actingAs($admin)
            ->deleteJson("/api/card-templates/{$template->id}")
            ->assertOk();

        $this->assertNull($training->fresh()->card_template_id);
    }

    public function test_deleting_a_template_leaves_other_orgs_trainings_alone(): void
    {
        // A system template is shared; detaching must not reach across orgs
        // beyond the one being served... and an org delete must not touch a
        // foreign org's rows at all.
        $org = Organization::factory()->create();
        $other = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();
        $mine = CardTemplate::factory()->for($org, 'organization')->create();
        $theirTemplate = CardTemplate::factory()->for($other, 'organization')->create();
        $theirTraining = Training::factory()->for($other, 'organization')->create([
            'card_template_id' => $theirTemplate->id,
        ]);

        $this->actingAs($admin)
            ->deleteJson("/api/card-templates/{$mine->id}")
            ->assertOk();

        $this->assertSame($theirTemplate->id, $theirTraining->fresh()->card_template_id);
    }
}
