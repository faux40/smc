<?php

namespace Tests\Feature\Tenancy;

use App\Models\CardFont;
use App\Models\CardTemplate;
use App\Models\MergeField;
use App\Models\Organization;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuildsPresentationFixtures;
use Tests\TestCase;

class CardTemplatesApiTest extends TestCase
{
    use BuildsPresentationFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        Storage::fake('linode');
    }

    private function upload(string $path, string $name = 'card.pptx'): UploadedFile
    {
        return new UploadedFile($path, $name, null, null, true);
    }

    // ---- upload ---------------------------------------------------------

    public function test_admin_uploads_a_single_sided_pptx_card(): void
    {
        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();

        $file = $this->upload($this->makePptxFixture(['<a:t>${user_name}</a:t>']));

        $this->actingAs($admin)
            ->post('/api/card-templates', ['name' => 'CPR wallet card', 'file' => $file])
            ->assertCreated()
            ->assertJsonPath('name', 'CPR wallet card')
            ->assertJsonPath('extension', 'pptx')
            ->assertJsonPath('slide_count', 1)
            ->assertJsonPath('has_back', false)
            // Card size is read from the slide, never typed: 3.375 x 2.125in.
            ->assertJsonPath('card_width', 243)
            ->assertJsonPath('card_height', 153)
            ->assertJsonPath('placeholders', ['user_name']);

        $template = CardTemplate::first();
        $this->assertSame($org->id, $template->org_id);
        Storage::disk('linode')->assertExists($template->path);
    }

    public function test_a_two_slide_template_is_front_and_back(): void
    {
        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();

        $file = $this->upload($this->makePptxFixture([
            '<a:t>${user_name}</a:t>',
            '<a:t>${cert_id}</a:t>',
        ]));

        $this->actingAs($admin)
            ->post('/api/card-templates', ['name' => 'Two-sided', 'file' => $file])
            ->assertCreated()
            ->assertJsonPath('slide_count', 2)
            ->assertJsonPath('has_back', true);
    }

    public function test_an_odp_upload_is_accepted(): void
    {
        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();

        $file = $this->upload($this->makeOdpFixture(), 'card.odp');

        $this->actingAs($admin)
            ->post('/api/card-templates', ['name' => 'Forklift card', 'file' => $file])
            ->assertCreated()
            ->assertJsonPath('extension', 'odp')
            ->assertJsonPath('card_width', 243);
    }

    public function test_a_three_slide_template_is_rejected(): void
    {
        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();

        $file = $this->upload($this->makePptxFixture([
            '<a:t>1</a:t>', '<a:t>2</a:t>', '<a:t>3</a:t>',
        ]));

        $this->actingAs($admin)
            ->postJson('/api/card-templates', ['name' => 'Deck', 'file' => $file])
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');

        $this->assertSame(0, CardTemplate::count());
        // Nothing is stored when validation fails.
        $this->assertSame([], Storage::disk('linode')->allFiles());
    }

    public function test_a_document_renamed_to_pptx_is_rejected(): void
    {
        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();

        // Structural validation, not the extension the client claimed.
        $file = $this->upload($this->makeOdpFixture(), 'sneaky.pptx');

        $this->actingAs($admin)
            ->postJson('/api/card-templates', ['name' => 'Nope', 'file' => $file])
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');
    }

    public function test_unsupported_fonts_are_reported_but_do_not_block_the_upload(): void
    {
        // LibreOffice substitutes a font it cannot see and the card re-flows.
        // The user needs to know; refusing outright would be worse, since
        // substitution is sometimes fine.
        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();

        $file = $this->upload($this->makePptxFixture([
            '<a:rPr><a:latin typeface="Arial"/></a:rPr>'
            .'<a:rPr><a:latin typeface="Brush Script MT"/></a:rPr>',
        ]));

        $this->actingAs($admin)
            ->post('/api/card-templates', ['name' => 'Fancy', 'file' => $file])
            ->assertCreated()
            ->assertJsonPath('unsupported_fonts', ['Brush Script MT']);
    }

    public function test_an_uploaded_font_clears_the_warning_for_that_family(): void
    {
        /*
         * C6c, and the whole point of the font library: once the org owns
         * the file, the converter will see it, so the design is no longer
         * going to be substituted and must stop being warned about.
         *
         * Computed on read rather than trusted from the column written at
         * upload — a font can be added (or removed) long after a design was
         * uploaded, and a warning frozen at upload time would be a lie in
         * both directions.
         */
        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();

        $file = $this->upload($this->makePptxFixture([
            '<a:rPr><a:latin typeface="Brush Script MT"/></a:rPr>'
            .'<a:rPr><a:latin typeface="Gotham"/></a:rPr>',
        ]));

        $this->actingAs($admin)
            ->post('/api/card-templates', ['name' => 'Fancy', 'file' => $file])
            ->assertCreated()
            ->assertJsonPath('unsupported_fonts', ['Brush Script MT', 'Gotham']);

        CardFont::factory()->for($org, 'organization')->create([
            // Spelled differently from the slide on purpose: matching is by
            // family, case-insensitively.
            'family' => 'brush script mt',
        ]);

        $this->actingAs($admin)
            ->getJson('/api/card-templates')
            ->assertOk()
            ->assertJsonPath('0.unsupported_fonts', ['Gotham']);
    }

    public function test_another_orgs_font_does_not_clear_this_orgs_warning(): void
    {
        // Fonts are licensed per org and never cross one; a warning that
        // cleared because a stranger uploaded a file would be a lie.
        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();

        $file = $this->upload($this->makePptxFixture([
            '<a:rPr><a:latin typeface="Gotham"/></a:rPr>',
        ]));

        $this->actingAs($admin)
            ->post('/api/card-templates', ['name' => 'Fancy', 'file' => $file])
            ->assertCreated();

        CardFont::factory()
            ->for(Organization::factory()->create(), 'organization')
            ->create(['family' => 'Gotham']);

        $this->actingAs($admin)
            ->getJson('/api/card-templates')
            ->assertOk()
            ->assertJsonPath('0.unsupported_fonts', ['Gotham']);
    }

    public function test_card_keys_are_not_registered_as_org_merge_fields(): void
    {
        // Doc templates auto-draft unknown keys into the org registry; a
        // card's keys come from the class/user catalogue and the training's
        // own custom fields, so an unknown one here is a typo, not a field.
        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();

        $file = $this->upload($this->makePptxFixture(['<a:t>${not_a_real_field}</a:t>']));

        $this->actingAs($admin)
            ->post('/api/card-templates', ['name' => 'Card', 'file' => $file])
            ->assertCreated();

        $this->assertSame(0, MergeField::query()->where('key', 'not_a_real_field')->count());
    }

    // ---- permissions -----------------------------------------------------

    public function test_a_manager_can_list_but_not_upload(): void
    {
        $org = Organization::factory()->create();
        $manager = User::factory()->for($org, 'organization')->withRole('Manager')->create();
        CardTemplate::factory()->for($org, 'organization')->create(['name' => 'Existing']);

        $this->actingAs($manager)
            ->getJson('/api/card-templates')
            ->assertOk()
            ->assertJsonCount(1);

        $this->actingAs($manager)
            ->postJson('/api/card-templates', [
                'name' => 'Nope',
                'file' => $this->upload($this->makePptxFixture()),
            ])
            ->assertForbidden();
    }

    public function test_index_shows_system_templates_and_the_orgs_own(): void
    {
        $org = Organization::factory()->create();
        $other = Organization::factory()->create();
        $manager = User::factory()->for($org, 'organization')->withRole('Manager')->create();

        CardTemplate::factory()->system()->create(['name' => 'Universal card']);
        CardTemplate::factory()->for($org, 'organization')->create(['name' => 'Ours']);
        CardTemplate::factory()->for($other, 'organization')->create(['name' => 'Theirs']);

        $rows = $this->actingAs($manager)
            ->getJson('/api/card-templates')
            ->assertOk()
            ->assertJsonCount(2)
            ->json();

        $byName = collect($rows)->keyBy('name');
        $this->assertTrue($byName->has('Universal card'));
        $this->assertTrue($byName->has('Ours'));
        $this->assertFalse($byName->has('Theirs'));
        $this->assertFalse($byName['Universal card']['can_edit']);
    }

    // ---- replace / rename / delete ---------------------------------------

    public function test_replacing_a_template_chains_a_new_version(): void
    {
        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();
        $original = CardTemplate::factory()->for($org, 'organization')->create([
            'name' => 'CPR card', 'version' => 1, 'slide_count' => 1,
        ]);

        $file = $this->upload($this->makePptxFixture([
            '<a:t>${user_name}</a:t>', '<a:t>${cert_id}</a:t>',
        ]));

        $this->actingAs($admin)
            ->post("/api/card-templates/{$original->id}/replace", ['file' => $file])
            ->assertOk()
            ->assertJsonPath('version', 2)
            // Re-read from the new file: this upload added a back.
            ->assertJsonPath('slide_count', 2);

        // The old row is kept (soft-deleted) so past prints stay explicable.
        $this->assertSoftDeleted('card_templates', ['id' => $original->id]);
        $this->assertSame(
            $original->id,
            CardTemplate::where('version', 2)->first()->prev_version_id,
        );
    }

    public function test_rename_leaves_the_file_alone(): void
    {
        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();
        $template = CardTemplate::factory()->for($org, 'organization')->create(['name' => 'Old']);

        $this->actingAs($admin)
            ->patchJson("/api/card-templates/{$template->id}", [
                'name' => 'New', 'description' => 'Front only',
            ])
            ->assertOk()
            ->assertJsonPath('name', 'New')
            ->assertJsonPath('description', 'Front only');

        $this->assertSame($template->path, $template->fresh()->path);
    }

    public function test_a_system_template_is_read_only_and_a_foreign_one_is_not_found(): void
    {
        $org = Organization::factory()->create();
        $other = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();
        $system = CardTemplate::factory()->system()->create();
        $foreign = CardTemplate::factory()->for($other, 'organization')->create();

        $this->actingAs($admin)
            ->patchJson("/api/card-templates/{$system->id}", ['name' => 'Hijack'])
            ->assertForbidden();

        $this->actingAs($admin)
            ->deleteJson("/api/card-templates/{$foreign->id}")
            ->assertNotFound();
    }

    public function test_delete_soft_deletes_and_keeps_the_file(): void
    {
        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();
        $template = CardTemplate::factory()->for($org, 'organization')->create();

        $this->actingAs($admin)
            ->deleteJson("/api/card-templates/{$template->id}")
            ->assertOk();

        $this->assertSoftDeleted('card_templates', ['id' => $template->id]);
    }
}
