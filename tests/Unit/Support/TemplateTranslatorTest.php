<?php

namespace Tests\Unit\Support;

use App\Support\DocMerge\TemplateTranslator;
use PHPUnit\Framework\TestCase;
use ZipArchive;

/**
 * String-level coverage of the ${key} -> TBS translation, including the
 * two load-bearing behaviors ported from the demo: split-placeholder
 * stitching and lonely-list-placeholder block promotion.
 */
class TemplateTranslatorTest extends TestCase
{
    private function translator(): TemplateTranslator
    {
        return new TemplateTranslator(
            listFieldKeys: ['eap_info', 'loto_affected_workgroups'],
            multilineFieldKeys: ['med_provider_info'],
        );
    }

    // ---- plain fields + modifiers -----------------------------------

    public function test_plain_placeholder(): void
    {
        $this->assertSame('[m.agency]', $this->translator()->translate('${agency}'));
    }

    public function test_modifier_map(): void
    {
        $t = $this->translator();

        $this->assertSame('[m.agency;ope=upperw]', $t->translate('${agency:CAP}'));
        $this->assertSame('[m.agency;ope=upper]', $t->translate('${agency:ALLCAP}'));
        $this->assertSame("[m.doc_date;frm='mmmm d, yyyy']", $t->translate('${doc_date:STDATE}'));
        $this->assertSame('[m.doc_date;onformat=tbs_ordinal_date]', $t->translate('${doc_date:MDDATE}'));
    }

    public function test_unknown_modifier_degrades_to_plain_field(): void
    {
        $this->assertSame('[m.agency]', $this->translator()->translate('${agency:BOGUS}'));
    }

    public function test_multiline_fields_get_changebreak(): void
    {
        $this->assertSame(
            '[m.med_provider_info;ope=changebreak]',
            $this->translator()->translate('${med_provider_info}'),
        );
    }

    public function test_multiline_modifier_and_changebreak_combine(): void
    {
        $this->assertSame(
            '[m.med_provider_info;ope=upper;ope=changebreak]',
            $this->translator()->translate('${med_provider_info:ALLCAP}'),
        );
    }

    // ---- split-placeholder stitching -----------------------------------

    public function test_stitches_placeholder_split_by_a_closing_tag(): void
    {
        // Editor split: the closer's opener lives before the placeholder,
        // so the orphan closer is relocated after it.
        $xml = '<text:span a="1">Rev ${doc_date_m</text:span>y}';

        $this->assertSame(
            '<text:span a="1">Rev [m.doc_date_my]</text:span>',
            $this->translator()->translate($xml),
        );
    }

    public function test_stitches_placeholder_split_by_a_full_span(): void
    {
        $xml = '${emplo<text:span text:style-name="T9">yee_term</text:span>}';

        $this->assertSame('[m.employee_term]', $this->translator()->translate($xml));
    }

    public function test_stitches_placeholder_with_orphan_opener_inside(): void
    {
        // Opener inside the braces, its closer after: relocated before.
        $xml = '${top_<text:span b="2">manager}</text:span>';

        $this->assertSame(
            '<text:span b="2">[m.top_manager]</text:span>',
            $this->translator()->translate($xml),
        );
    }

    // ---- list-block promotion ---------------------------------------------

    public function test_promotes_lonely_list_field_in_odt_list_item(): void
    {
        $xml = '<text:list-item><text:p text:style-name="P1">${eap_info}</text:p></text:list-item>';

        $t = $this->translator();
        $out = $t->translate($xml);

        $this->assertStringContainsString('[eap_info_1.item;block=text:list-item]', $out);
        $this->assertSame(['eap_info_1' => 'eap_info'], $t->generatedBlockMap());
    }

    public function test_promotes_lonely_list_field_in_docx_paragraph(): void
    {
        $xml = '<w:p><w:pPr/><w:r><w:t>${loto_affected_workgroups}</w:t></w:r></w:p>';

        $out = $this->translator()->translate($xml);

        $this->assertStringContainsString('[loto_affected_workgroups_1.item;block=w:p]', $out);
    }

    public function test_each_occurrence_gets_a_unique_block_name(): void
    {
        $xml = '<text:list-item><text:p>${eap_info}</text:p></text:list-item>'
            .'<text:list-item><text:p>${eap_info}</text:p></text:list-item>';

        $t = $this->translator();
        $out = $t->translate($xml);

        $this->assertStringContainsString('eap_info_1.item', $out);
        $this->assertStringContainsString('eap_info_2.item', $out);
        $this->assertSame(
            ['eap_info_1' => 'eap_info', 'eap_info_2' => 'eap_info'],
            $t->generatedBlockMap(),
        );
    }

    public function test_non_list_field_alone_in_a_bullet_stays_a_plain_field(): void
    {
        $xml = '<text:list-item><text:p>${agency}</text:p></text:list-item>';

        $out = $this->translator()->translate($xml);

        $this->assertStringContainsString('[m.agency]', $out);
        $this->assertStringNotContainsString('block=', $out);
    }

    public function test_inline_list_field_usage_stays_a_joined_field(): void
    {
        // Not alone in the paragraph -> renders as the comma-joined string.
        $xml = '<w:p><w:r><w:t>Applies to: ${eap_info} groups</w:t></w:r></w:p>';

        $out = $this->translator()->translate($xml);

        $this->assertStringContainsString('[m.eap_info]', $out);
        $this->assertStringNotContainsString('block=', $out);
    }

    // ---- archive-level operations ---------------------------------------------

    public function test_find_placeholders_scans_all_subfiles_strips_modifiers_and_stitches(): void
    {
        $path = $this->makeDocxFixture(
            documentXml: '<w:p><w:r><w:t>${agency:CAP} and ${top_ma</w:t></w:r><w:r><w:t>nager}</w:t></w:r></w:p>',
            headerXml: '<w:p><w:r><w:t>${doc_date}</w:t></w:r></w:p>',
        );

        $found = $this->translator()->findPlaceholders($path);
        sort($found);

        $this->assertSame(['agency', 'doc_date', 'top_manager'], $found);

        unlink($path);
    }

    public function test_translate_file_rewrites_every_subfile(): void
    {
        $path = $this->makeDocxFixture(
            documentXml: '<w:p><w:r><w:t>${agency}</w:t></w:r></w:p>',
            headerXml: '<w:p><w:r><w:t>${doc_date}</w:t></w:r></w:p>',
        );
        $out = $path.'.translated.docx';

        $this->translator()->translateFile($path, $out);

        $zip = new ZipArchive;
        $zip->open($out);
        $this->assertStringContainsString('[m.agency]', $zip->getFromName('word/document.xml'));
        $this->assertStringContainsString('[m.doc_date]', $zip->getFromName('word/header1.xml'));
        $zip->close();

        unlink($path);
        unlink($out);
    }

    /**
     * Minimal-but-valid DOCX: content types, rels, document + one header.
     */
    private function makeDocxFixture(string $documentXml, string $headerXml = ''): string
    {
        $path = tempnam(sys_get_temp_dir(), 'tpl').'.docx';

        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        $zip->addFromString('[Content_Types].xml', <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
  <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
  <Default Extension="xml" ContentType="application/xml"/>
  <Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>
  <Override PartName="/word/header1.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.header+xml"/>
</Types>
XML);
        $zip->addFromString('_rels/.rels', <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>
</Relationships>
XML);
        $zip->addFromString('word/_rels/document.xml.rels', <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/header" Target="header1.xml"/>
</Relationships>
XML);
        $zip->addFromString('word/document.xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
            .'<w:body>'.$documentXml.'<w:sectPr><w:headerReference w:type="default" r:id="rId2" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"/></w:sectPr></w:body>'
            .'</w:document>');
        $zip->addFromString('word/header1.xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<w:hdr xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'.$headerXml.'</w:hdr>');

        $zip->close();

        return $path;
    }
}
