<?php

namespace Tests\Support;

use ZipArchive;

/**
 * Builds minimal-but-valid PPTX / ODP archives for the card-template tests:
 * enough structure for ZipArchive scanning, slide counting, page-size
 * reading, font detection and OpenTBS-style `${key}` extraction.
 *
 * A card template is one card — slide 1 front, optional slide 2 back — so
 * the fixtures default to a 3.375 x 2.125in wallet card rather than a
 * screen-sized deck.
 */
trait BuildsPresentationFixtures
{
    /** EMU per inch, the unit PPTX stores slide dimensions in. */
    private const EMU_PER_INCH = 914400;

    /**
     * @param  array<int, string>  $slides  body XML per slide (one entry = single-sided)
     */
    protected function makePptxFixture(
        array $slides = ['<a:t>${user_name}</a:t>'],
        float $widthInches = 3.375,
        float $heightInches = 2.125,
        ?string $path = null,
    ): string {
        $path ??= tempnam(sys_get_temp_dir(), 'card').'.pptx';

        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        $cx = (int) round($widthInches * self::EMU_PER_INCH);
        $cy = (int) round($heightInches * self::EMU_PER_INCH);

        $slideIds = '';
        foreach (array_keys($slides) as $i) {
            $slideIds .= '<p:sldId id="'.(256 + $i).'" r:id="rId'.($i + 1).'"/>';
        }

        $zip->addFromString('[Content_Types].xml', <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
  <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
  <Default Extension="xml" ContentType="application/xml"/>
</Types>
XML);
        $zip->addFromString('_rels/.rels', <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="ppt/presentation.xml"/>
</Relationships>
XML);
        $zip->addFromString('ppt/presentation.xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<p:presentation xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main"'
            .' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<p:sldIdLst>'.$slideIds.'</p:sldIdLst>'
            .'<p:sldSz cx="'.$cx.'" cy="'.$cy.'"/>'
            .'</p:presentation>');

        foreach (array_values($slides) as $i => $body) {
            $zip->addFromString('ppt/slides/slide'.($i + 1).'.xml',
                '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<p:sld xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main"'
                .' xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main">'
                .'<p:cSld><p:spTree>'.$body.'</p:spTree></p:cSld>'
                .'</p:sld>');
        }

        $zip->close();

        return $path;
    }

    /**
     * @param  array<int, string>  $pages  body XML per draw:page
     */
    protected function makeOdpFixture(
        array $pages = ['<text:p>${user_name}</text:p>'],
        string $pageWidth = '3.375in',
        string $pageHeight = '2.125in',
        string $fontFaces = '',
        ?string $path = null,
    ): string {
        $path ??= tempnam(sys_get_temp_dir(), 'card').'.odp';

        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        $zip->addFromString('mimetype', 'application/vnd.oasis.opendocument.presentation');

        $drawPages = '';
        foreach (array_values($pages) as $i => $body) {
            $drawPages .= '<draw:page draw:name="page'.($i + 1).'">'.$body.'</draw:page>';
        }

        $zip->addFromString('content.xml',
            '<?xml version="1.0" encoding="UTF-8"?>'
            .'<office:document-content'
            .' xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0"'
            .' xmlns:style="urn:oasis:names:tc:opendocument:xmlns:style:1.0"'
            .' xmlns:svg="urn:oasis:names:tc:opendocument:xmlns:svg-compatible:1.0"'
            .' xmlns:draw="urn:oasis:names:tc:opendocument:xmlns:drawing:1.0"'
            .' xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0">'
            .'<office:font-face-decls>'.$fontFaces.'</office:font-face-decls>'
            .'<office:body><office:presentation>'.$drawPages.'</office:presentation></office:body>'
            .'</office:document-content>');

        $zip->addFromString('styles.xml',
            '<?xml version="1.0" encoding="UTF-8"?>'
            .'<office:document-styles'
            .' xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0"'
            .' xmlns:style="urn:oasis:names:tc:opendocument:xmlns:style:1.0"'
            .' xmlns:fo="urn:oasis:names:tc:opendocument:xmlns:xsl-fo-compatible:1.0">'
            .'<office:automatic-styles>'
            .'<style:page-layout style:name="PM1">'
            .'<style:page-layout-properties fo:page-width="'.$pageWidth.'" fo:page-height="'.$pageHeight.'"/>'
            .'</style:page-layout>'
            .'</office:automatic-styles>'
            .'</office:document-styles>');

        $zip->close();

        return $path;
    }

    /**
     * An ODP that LibreOffice will actually OPEN and render at the requested
     * card size — for tests that convert rather than merely inspect.
     *
     * makeOdpFixture() above is deliberately minimal because C2 only reads the
     * zip's XML; soffice refuses it ("source file could not be loaded"). Three
     * things make the difference, each learned the hard way:
     *   - `mimetype` stored uncompressed as an entry,
     *   - a META-INF/manifest.xml listing the parts,
     *   - ODF's required element order in styles.xml (office:styles, then
     *     automatic-styles, then master-styles) with the master page pointing
     *     at the page layout. Get the order wrong and it still converts, but
     *     silently at LibreOffice's default 16:9 slide size instead of the
     *     card's — which is far worse than an error.
     *
     * @param  array<int, string>  $pages  body XML per draw:page
     */
    protected function makeRenderableOdpFixture(
        array $pages = ['<draw:frame svg:x="0.2in" svg:y="0.2in" svg:width="2.5in" svg:height="0.5in"><draw:text-box><text:p>${full_name}</text:p></draw:text-box></draw:frame>'],
        string $pageWidth = '3.375in',
        string $pageHeight = '2.125in',
        ?string $path = null,
    ): string {
        $path ??= tempnam(sys_get_temp_dir(), 'rcard').'.odp';

        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        $zip->addFromString('mimetype', 'application/vnd.oasis.opendocument.presentation');
        $zip->setCompressionName('mimetype', ZipArchive::CM_STORE);

        $zip->addFromString('META-INF/manifest.xml',
            '<?xml version="1.0" encoding="UTF-8"?>'
            .'<manifest:manifest xmlns:manifest="urn:oasis:names:tc:opendocument:xmlns:manifest:1.0" manifest:version="1.2">'
            .'<manifest:file-entry manifest:full-path="/" manifest:media-type="application/vnd.oasis.opendocument.presentation"/>'
            .'<manifest:file-entry manifest:full-path="content.xml" manifest:media-type="text/xml"/>'
            .'<manifest:file-entry manifest:full-path="styles.xml" manifest:media-type="text/xml"/>'
            .'</manifest:manifest>');

        $drawPages = '';
        foreach (array_values($pages) as $i => $body) {
            $drawPages .= '<draw:page draw:name="page'.($i + 1).'" draw:master-page-name="Default">'.$body.'</draw:page>';
        }

        $zip->addFromString('content.xml',
            '<?xml version="1.0" encoding="UTF-8"?>'
            .'<office:document-content'
            .' xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0"'
            .' xmlns:style="urn:oasis:names:tc:opendocument:xmlns:style:1.0"'
            .' xmlns:svg="urn:oasis:names:tc:opendocument:xmlns:svg-compatible:1.0"'
            .' xmlns:draw="urn:oasis:names:tc:opendocument:xmlns:drawing:1.0"'
            .' xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0" office:version="1.2">'
            .'<office:body><office:presentation>'.$drawPages.'</office:presentation></office:body>'
            .'</office:document-content>');

        $zip->addFromString('styles.xml',
            '<?xml version="1.0" encoding="UTF-8"?>'
            .'<office:document-styles'
            .' xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0"'
            .' xmlns:style="urn:oasis:names:tc:opendocument:xmlns:style:1.0"'
            .' xmlns:draw="urn:oasis:names:tc:opendocument:xmlns:drawing:1.0"'
            .' xmlns:fo="urn:oasis:names:tc:opendocument:xmlns:xsl-fo-compatible:1.0" office:version="1.2">'
            .'<office:styles/>'
            .'<office:automatic-styles>'
            .'<style:page-layout style:name="PM1">'
            .'<style:page-layout-properties fo:page-width="'.$pageWidth.'" fo:page-height="'.$pageHeight.'"'
            .' fo:margin-top="0in" fo:margin-bottom="0in" fo:margin-left="0in" fo:margin-right="0in"/>'
            .'</style:page-layout>'
            .'</office:automatic-styles>'
            .'<office:master-styles>'
            .'<style:master-page style:name="Default" style:page-layout-name="PM1"/>'
            .'</office:master-styles>'
            .'</office:document-styles>');

        $zip->close();

        return $path;
    }
}
