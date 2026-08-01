<?php

namespace Tests\Feature\Cards;

use App\Models\CardFont;
use App\Models\Organization;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The org's uploaded font library (custom-certs C6c) — the files that stop
 * LibreOffice substituting a family the design asked for.
 */
class CardFontsApiTest extends TestCase
{
    use RefreshDatabase;

    private const REAL_TTF = '/usr/share/fonts/truetype/liberation/LiberationSerif-Regular.ttf';

    private Organization $org;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        if (! is_file(self::REAL_TTF)) {
            $this->markTestSkipped('The image is missing its Liberation fonts.');
        }

        $this->seed(RoleSeeder::class);
        Storage::fake('linode');

        $this->org = Organization::factory()->create();
        $this->admin = User::factory()->for($this->org, 'organization')->withRole('Admin')->create();
    }

    /** A real font file, optionally renamed — the name INSIDE it is unchanged. */
    private function fontUpload(string $as = 'serif.ttf'): UploadedFile
    {
        return new UploadedFile(self::REAL_TTF, $as, 'font/ttf', null, true);
    }

    public function test_an_admin_uploads_a_font_and_the_family_is_read_from_the_file(): void
    {
        // Never the filename: "brushscript.ttf" satisfying a template that
        // asked for something else is a card misprinted in a lookalike.
        $this->actingAs($this->admin)
            ->post('/api/card-fonts', ['file' => $this->fontUpload('anything-at-all.ttf')])
            ->assertCreated()
            ->assertJsonPath('family', 'Liberation Serif')
            ->assertJsonPath('format', 'ttf')
            ->assertJsonPath('original_filename', 'anything-at-all.ttf');

        $font = CardFont::query()->withoutGlobalScope('organization')->sole();
        $this->assertSame($this->org->id, $font->org_id);
        $this->assertSame('liberation serif', $font->family_key);
        Storage::disk('linode')->assertExists($font->path);
    }

    public function test_a_file_that_is_not_a_font_is_refused_and_nothing_is_stored(): void
    {
        $notAFont = UploadedFile::fake()->createWithContent('trojan.ttf', 'MZ definitely not a font');

        $this->actingAs($this->admin)
            ->postJson('/api/card-fonts', ['file' => $notAFont])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['file']);

        $this->assertSame(0, CardFont::query()->withoutGlobalScope('organization')->count());
        // A rejected upload must leave nothing behind on the disk.
        $this->assertSame([], Storage::disk('linode')->allFiles());
    }

    public function test_the_same_family_cannot_be_uploaded_twice(): void
    {
        /*
         * Two files claiming one family would both be staged and LibreOffice
         * would pick whichever it liked — a card that prints differently on
         * different days. Replacing is the deliberate act of deleting first.
         */
        $this->actingAs($this->admin)
            ->post('/api/card-fonts', ['file' => $this->fontUpload()])
            ->assertCreated();

        $this->actingAs($this->admin)
            ->postJson('/api/card-fonts', ['file' => $this->fontUpload('again.ttf')])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['file']);

        $this->assertSame(1, CardFont::query()->withoutGlobalScope('organization')->count());
    }

    public function test_another_org_may_hold_the_same_family(): void
    {
        // Licensing is per org; one org owning Gotham says nothing about
        // whether another may.
        $other = Organization::factory()->create();
        $theirAdmin = User::factory()->for($other, 'organization')->withRole('Admin')->create();

        $this->actingAs($this->admin)
            ->post('/api/card-fonts', ['file' => $this->fontUpload()])
            ->assertCreated();

        $this->actingAs($theirAdmin)
            ->post('/api/card-fonts', ['file' => $this->fontUpload()])
            ->assertCreated();

        $this->assertSame(2, CardFont::query()->withoutGlobalScope('organization')->count());
    }

    public function test_the_index_lists_only_this_orgs_fonts(): void
    {
        CardFont::factory()->for($this->org, 'organization')->create(['family' => 'Ours']);
        CardFont::factory()->for(Organization::factory()->create(), 'organization')->create(['family' => 'Theirs']);

        $this->actingAs($this->admin)
            ->getJson('/api/card-fonts')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.family', 'Ours');
    }

    public function test_a_manager_may_see_the_library_but_not_add_to_it(): void
    {
        // Managers print, and need to know why a warning cleared; defining is
        // Admin+, like stocks and designs.
        $manager = User::factory()->for($this->org, 'organization')->withRole('Manager')->create();

        $this->actingAs($manager)->getJson('/api/card-fonts')->assertOk();

        $this->actingAs($manager)
            ->postJson('/api/card-fonts', ['file' => $this->fontUpload()])
            ->assertForbidden();
    }

    public function test_deleting_a_font_removes_the_row_and_the_file(): void
    {
        $this->actingAs($this->admin)
            ->post('/api/card-fonts', ['file' => $this->fontUpload()])
            ->assertCreated();

        $font = CardFont::query()->withoutGlobalScope('organization')->sole();

        $this->actingAs($this->admin)
            ->deleteJson("/api/card-fonts/{$font->id}")
            ->assertOk();

        $this->assertModelMissing($font);
        Storage::disk('linode')->assertMissing($font->path);
    }

    public function test_another_orgs_font_cannot_be_deleted(): void
    {
        $theirs = CardFont::factory()
            ->for(Organization::factory()->create(), 'organization')
            ->create();

        $this->actingAs($this->admin)
            ->deleteJson("/api/card-fonts/{$theirs->id}")
            ->assertNotFound();

        $this->assertModelExists($theirs);
    }

    public function test_an_oversized_font_is_refused(): void
    {
        $huge = UploadedFile::fake()->create('huge.ttf', 6 * 1024);

        $this->actingAs($this->admin)
            ->postJson('/api/card-fonts', ['file' => $huge])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['file']);
    }
}
