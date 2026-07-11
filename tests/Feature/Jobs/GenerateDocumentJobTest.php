<?php

namespace Tests\Feature\Jobs;

use App\Events\GeneratedDocumentsChanged;
use App\Jobs\GenerateDocument;
use App\Models\DocTemplate;
use App\Models\GeneratedDocument;
use App\Models\MergeField;
use App\Models\MergeValue;
use App\Models\Organization;
use App\Support\DocMerge\DocumentMergeService;
use App\Support\DocMerge\MergeDataBuilder;
use App\Support\DocMerge\PdfConverter;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\Support\BuildsDocxFixtures;
use Tests\TestCase;
use ZipArchive;

/**
 * Runs the real generation pipeline (template from the fake linode disk
 * -> translate -> OpenTBS merge -> store outputs) with only the soffice
 * conversion mocked.
 */
class GenerateDocumentJobTest extends TestCase
{
    use BuildsDocxFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        Storage::fake('linode');
    }

    /** @return array{0: GeneratedDocument, 1: Organization} */
    private function makeQueuedRun(): array
    {
        $org = Organization::factory()->create(['name' => 'Rio Dell']);

        $agency = MergeField::factory()->system()->create(['key' => 'agency']);
        MergeValue::factory()->for($org, 'organization')->for($agency, 'field')->create(['value' => 'City of Rio Dell']);
        $eap = MergeField::factory()->system()->create(['key' => 'eap_info', 'type' => 'list']);
        MergeValue::factory()->for($org, 'organization')->for($eap, 'field')
            ->create(['value' => ['Call 911', 'Notify supervisor']]);

        $fixture = $this->makeDocxFixture(
            documentXml: '<w:p><w:r><w:t>The ${agency} plan. Missing: ${top_manager}</w:t></w:r></w:p>'
                .'<w:p><w:r><w:t>${eap_info}</w:t></w:r></w:p>',
        );
        Storage::disk('linode')->put('doc-templates/test.docx', file_get_contents($fixture));
        unlink($fixture);

        $template = DocTemplate::factory()->system()->create([
            'extension' => 'docx',
            'path' => 'doc-templates/test.docx',
        ]);
        MergeField::factory()->system()->create(['key' => 'top_manager']);

        $doc = GeneratedDocument::factory()->for($org, 'organization')->for($template, 'template')->create();

        return [$doc, $org];
    }

    private function fakePdfConverter(): PdfConverter
    {
        $mock = Mockery::mock(PdfConverter::class);
        $mock->shouldReceive('toPdf')->andReturnUsing(function (string $input, string $outDir): string {
            $pdf = $outDir.'/'.pathinfo($input, PATHINFO_FILENAME).'.pdf';
            file_put_contents($pdf, '%PDF-fake');

            return $pdf;
        });

        return $mock;
    }

    private function runJob(GeneratedDocument $doc, ?PdfConverter $converter = null): void
    {
        (new GenerateDocument($doc->id))->handle(
            app(MergeDataBuilder::class),
            new DocumentMergeService,
            $converter ?? $this->fakePdfConverter(),
        );
    }

    public function test_generates_merged_document_and_pdf(): void
    {
        Event::fake([GeneratedDocumentsChanged::class]);
        [$doc] = $this->makeQueuedRun();

        $this->runJob($doc);

        $doc->refresh();
        $this->assertSame('done', $doc->status);
        Storage::disk('linode')->assertExists($doc->merged_path);
        Storage::disk('linode')->assertExists($doc->pdf_path);

        // Crack open the merged docx and verify the actual content.
        $tmp = tempnam(sys_get_temp_dir(), 'out').'.docx';
        file_put_contents($tmp, Storage::disk('linode')->get($doc->merged_path));
        $zip = new ZipArchive;
        $zip->open($tmp);
        $document = $zip->getFromName('word/document.xml');
        $zip->close();
        unlink($tmp);

        $this->assertStringContainsString('The City of Rio Dell plan.', $document);
        $this->assertStringContainsString('Missing: --TOP_MANAGER--', $document);
        // The lonely list bullet became one paragraph per stored item.
        $this->assertStringContainsString('Call 911', $document);
        $this->assertStringContainsString('Notify supervisor', $document);

        // Snapshot preserves what was merged.
        $this->assertSame('City of Rio Dell', $doc->merge_snapshot['fields']['agency']);

        Event::assertDispatched(GeneratedDocumentsChanged::class);
    }

    public function test_conversion_failure_marks_the_row_failed(): void
    {
        Event::fake([GeneratedDocumentsChanged::class]);
        [$doc] = $this->makeQueuedRun();

        $broken = Mockery::mock(PdfConverter::class);
        $broken->shouldReceive('toPdf')->andThrow(new \RuntimeException('soffice exploded'));

        $this->runJob($doc, $broken);

        $doc->refresh();
        $this->assertSame('failed', $doc->status);
        $this->assertStringContainsString('soffice exploded', $doc->error);
        $this->assertNull($doc->merged_path);
        Event::assertDispatched(GeneratedDocumentsChanged::class);
    }

    public function test_deleted_row_is_a_no_op(): void
    {
        [$doc] = $this->makeQueuedRun();
        $id = $doc->id;
        $doc->delete();

        (new GenerateDocument($id))->handle(
            app(MergeDataBuilder::class),
            new DocumentMergeService,
            $this->fakePdfConverter(),
        );

        $this->assertDatabaseMissing('generated_documents', ['id' => $id]);
    }
}
