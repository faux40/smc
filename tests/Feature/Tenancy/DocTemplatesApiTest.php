<?php

namespace Tests\Feature\Tenancy;

use App\Events\DocTemplatesChanged;
use App\Models\DocTemplate;
use App\Models\MergeField;
use App\Models\Organization;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuildsDocxFixtures;
use Tests\TestCase;

class DocTemplatesApiTest extends TestCase
{
    use BuildsDocxFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        Storage::fake('linode');
    }

    /** A real (minimal) docx as an UploadedFile, ${agency} + ${union_rep} + computed ${doc_date}. */
    private function templateUpload(string $documentXml = '<w:p><w:r><w:t>${agency} ${union_rep} ${doc_date}</w:t></w:r></w:p>'): UploadedFile
    {
        $path = $this->makeDocxFixture(documentXml: $documentXml);

        return new UploadedFile($path, 'HazCom_Policy.docx', null, null, true);
    }

    // ---- index ------------------------------------------------------------

    public function test_manager_lists_system_plus_own_org_templates(): void
    {
        $org = Organization::factory()->create();
        $other = Organization::factory()->create();
        $manager = User::factory()->for($org, 'organization')->withRole('Manager')->create();
        DocTemplate::factory()->system()->create(['name' => 'HazCom Policy']);
        DocTemplate::factory()->for($org, 'organization')->create(['name' => 'Our Own']);
        DocTemplate::factory()->for($other, 'organization')->create(['name' => 'Foreign']);

        $rows = $this->actingAs($manager)
            ->getJson('/api/doc-templates')
            ->assertOk()
            ->assertJsonCount(2)
            ->json();

        $names = collect($rows)->pluck('name');
        $this->assertTrue($names->contains('HazCom Policy'));
        $this->assertTrue($names->contains('Our Own'));
        $this->assertFalse($names->contains('Foreign'));
    }

    public function test_below_manager_cannot_list(): void
    {
        $org = Organization::factory()->create();
        $member = User::factory()->for($org, 'organization')->withRole('SelfEdit')->create();

        $this->actingAs($member)->getJson('/api/doc-templates')->assertForbidden();
    }

    // ---- upload -----------------------------------------------------------

    public function test_admin_uploads_a_template_and_placeholders_are_extracted(): void
    {
        Event::fake([DocTemplatesChanged::class]);
        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();
        MergeField::factory()->system()->create(['key' => 'agency']);

        $response = $this->actingAs($admin)
            ->postJson('/api/doc-templates', [
                'file' => $this->templateUpload(),
                'name' => 'HazCom Policy',
                'description' => 'The HazCom master',
            ])
            ->assertCreated()
            ->json();

        $this->assertEqualsCanonicalizing(['agency', 'union_rep', 'doc_date'], $response['placeholders']);

        $template = DocTemplate::query()->find($response['id']);
        $this->assertSame($org->id, $template->org_id);
        $this->assertSame('docx', $template->extension);
        Storage::disk('linode')->assertExists($template->path);
        Event::assertDispatched(DocTemplatesChanged::class);
    }

    public function test_upload_auto_registers_unknown_keys_as_draft_org_fields(): void
    {
        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();
        MergeField::factory()->system()->create(['key' => 'agency']);

        $this->actingAs($admin)
            ->postJson('/api/doc-templates', [
                'file' => $this->templateUpload(),
                'name' => 'HazCom Policy',
            ])
            ->assertCreated();

        // union_rep was unknown -> draft org field; agency already exists;
        // doc_date is computed at generation time, never registered.
        $draft = MergeField::query()->where('org_id', $org->id)->where('key', 'union_rep')->first();
        $this->assertNotNull($draft);
        $this->assertTrue($draft->draft);
        $this->assertSame(1, MergeField::query()->where('key', 'agency')->count());
        $this->assertNull(MergeField::query()->where('key', 'doc_date')->first());
    }

    public function test_manager_cannot_upload(): void
    {
        $org = Organization::factory()->create();
        $manager = User::factory()->for($org, 'organization')->withRole('Manager')->create();

        $this->actingAs($manager)
            ->postJson('/api/doc-templates', [
                'file' => $this->templateUpload(),
                'name' => 'Sneaky',
            ])
            ->assertForbidden();
    }

    public function test_upload_rejects_non_template_files(): void
    {
        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();

        $this->actingAs($admin)
            ->postJson('/api/doc-templates', [
                'file' => UploadedFile::fake()->create('report.pdf', 100, 'application/pdf'),
                'name' => 'Nope',
            ])
            ->assertStatus(422);
    }

    // ---- replace (new version) ------------------------------------------------

    public function test_replace_chains_a_new_version_and_soft_deletes_the_old(): void
    {
        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();

        $v1Id = $this->actingAs($admin)
            ->postJson('/api/doc-templates', [
                'file' => $this->templateUpload(),
                'name' => 'HazCom Policy',
            ])->json('id');

        $v2 = $this->actingAs($admin)
            ->postJson("/api/doc-templates/{$v1Id}/replace", [
                'file' => $this->templateUpload('<w:p><w:r><w:t>${agency} only now</w:t></w:r></w:p>'),
            ])
            ->assertCreated()
            ->json();

        $this->assertSame(2, $v2['version']);
        $this->assertSame(['agency'], $v2['placeholders']);
        $this->assertSame($v1Id, DocTemplate::query()->find($v2['id'])->prev_version_id);
        $this->assertSoftDeleted('doc_templates', ['id' => $v1Id]);

        // The list shows only the current version.
        $rows = $this->actingAs($admin)->getJson('/api/doc-templates')->json();
        $this->assertCount(1, $rows);
        $this->assertSame($v2['id'], $rows[0]['id']);
    }

    public function test_system_template_cannot_be_replaced_from_org_ui(): void
    {
        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();
        $system = DocTemplate::factory()->system()->create();

        $this->actingAs($admin)
            ->postJson("/api/doc-templates/{$system->id}/replace", [
                'file' => $this->templateUpload(),
            ])
            ->assertForbidden();
    }

    // ---- update / delete ---------------------------------------------------------

    public function test_admin_can_rename_own_org_template(): void
    {
        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();
        $template = DocTemplate::factory()->for($org, 'organization')->create();

        $this->actingAs($admin)
            ->patchJson("/api/doc-templates/{$template->id}", [
                'name' => 'Renamed',
                'description' => 'Now with a description',
            ])
            ->assertOk();

        $this->assertSame('Renamed', $template->fresh()->name);
    }

    public function test_cross_org_update_is_404(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $adminA = User::factory()->for($orgA, 'organization')->withRole('Admin')->create();
        $templateB = DocTemplate::factory()->for($orgB, 'organization')->create();

        $this->actingAs($adminA)
            ->patchJson("/api/doc-templates/{$templateB->id}", ['name' => 'Hacked'])
            ->assertNotFound();
    }

    public function test_admin_can_delete_own_org_template(): void
    {
        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();
        $template = DocTemplate::factory()->for($org, 'organization')->create();

        $this->actingAs($admin)
            ->deleteJson("/api/doc-templates/{$template->id}")
            ->assertOk();

        $this->assertSoftDeleted('doc_templates', ['id' => $template->id]);
    }

    public function test_system_template_cannot_be_deleted_from_org_ui(): void
    {
        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();
        $system = DocTemplate::factory()->system()->create();

        $this->actingAs($admin)
            ->deleteJson("/api/doc-templates/{$system->id}")
            ->assertForbidden();
    }
}
