<?php

namespace Tests\Unit\Support;

use App\Support\DocMerge\DocumentMergeService;
use App\Support\DocMerge\TemplateTranslator;
use PHPUnit\Framework\TestCase;
use Tests\Support\BuildsDocxFixtures;
use ZipArchive;

/**
 * End-to-end translate + OpenTBS merge on a real (minimal) DOCX fixture —
 * proves the whole `${key}` -> merged-document path without LibreOffice.
 */
class DocumentMergeServiceTest extends TestCase
{
    use BuildsDocxFixtures;

    /** @var array<int, string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $f) {
            @unlink($f);
        }
        parent::tearDown();
    }

    private function temp(string $suffix): string
    {
        $path = tempnam(sys_get_temp_dir(), 'merge').$suffix;
        $this->tempFiles[] = $path;

        return $path;
    }

    public function test_merges_fields_blocks_headers_and_multiline_breaks(): void
    {
        $template = $this->makeDocxFixture(
            path: $this->temp('.docx'),
            documentXml: '<w:p><w:r><w:t>The ${agency:CAP} (${agency_short}) plan.</w:t></w:r></w:p>'
                .'<w:p><w:r><w:t>${eap_info}</w:t></w:r></w:p>'
                .'<w:p><w:r><w:t>Medical: ${med_provider_info}</w:t></w:r></w:p>'
                .'<w:p><w:r><w:t>Missing: ${never_set}</w:t></w:r></w:p>',
            headerXml: '<w:p><w:r><w:t>${agency} — ${doc_date:MDDATE}</w:t></w:r></w:p>',
        );

        // 1. translate ${key} -> TBS syntax (on a copy, like the real flow)
        $translator = new TemplateTranslator(
            listFieldKeys: ['eap_info'],
            multilineFieldKeys: ['med_provider_info'],
        );
        $translated = $translator->translateFile($template, $this->temp('.docx'));

        // 2. build blocks per the translator's minted names
        $rows = [['item' => 'Call 911'], ['item' => 'Notify supervisor']];
        $blocks = [];
        foreach ($translator->generatedBlockMap() as $unique => $fieldKey) {
            $blocks[$unique] = $fieldKey === 'eap_info' ? $rows : [];
        }

        // 3. merge
        $out = (new DocumentMergeService)->merge($translated, [
            'agency' => 'city of rio dell',
            'agency_short' => 'Rio Dell',
            'eap_info' => 'Call 911, Notify supervisor',
            'med_provider_info' => "Redwood Urgent Care\n123 Main St",
            'doc_date' => '2026-07-04',
            'never_set' => '--NEVER_SET--',
        ], $this->temp('.out.docx'), $blocks);

        $zip = new ZipArchive;
        $zip->open($out);
        $document = $zip->getFromName('word/document.xml');
        $header = $zip->getFromName('word/header1.xml');
        $zip->close();

        // CAP modifier upper-cased each word.
        $this->assertStringContainsString('The City Of Rio Dell (Rio Dell) plan.', $document);
        // The lonely list paragraph became one paragraph per row.
        $this->assertStringContainsString('Call 911', $document);
        $this->assertStringContainsString('Notify supervisor', $document);
        $this->assertStringNotContainsString('eap_info', $document);
        // Multiline newline became a real DOCX line break (changebreak
        // closes/reopens the text run around <w:br/> — proper OOXML).
        $this->assertStringContainsString('Redwood Urgent Care</w:t><w:br/><w:t>123 Main St', $document);
        // Unset field prints its visible placeholder.
        $this->assertStringContainsString('Missing: --NEVER_SET--', $document);
        // Headers merge too, incl. the ordinal-date onformat callback.
        $this->assertStringContainsString('city of rio dell — July 4th', $header);
        // No TBS syntax left anywhere.
        $this->assertStringNotContainsString('[m.', $document);
        $this->assertStringNotContainsString('[m.', $header);
    }

    public function test_block_rows_render_one_paragraph_each(): void
    {
        $template = $this->makeDocxFixture(
            path: $this->temp('.docx'),
            documentXml: '<w:p><w:r><w:t>${eap_info}</w:t></w:r></w:p>',
        );

        $translator = new TemplateTranslator(listFieldKeys: ['eap_info']);
        $translated = $translator->translateFile($template, $this->temp('.docx'));

        $blocks = [];
        foreach ($translator->generatedBlockMap() as $unique => $fieldKey) {
            $blocks[$unique] = [['item' => 'One'], ['item' => 'Two'], ['item' => 'Three']];
        }

        $out = (new DocumentMergeService)->merge($translated, ['eap_info' => 'One, Two, Three'], $this->temp('.out.docx'), $blocks);

        $zip = new ZipArchive;
        $zip->open($out);
        $document = $zip->getFromName('word/document.xml');
        $zip->close();

        $this->assertSame(3, substr_count($document, '<w:p>'));
    }
}
