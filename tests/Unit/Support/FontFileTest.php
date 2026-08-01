<?php

namespace Tests\Unit\Support;

use App\Support\Cards\FontFile;
use App\Support\Cards\InvalidFontFile;
use PHPUnit\Framework\TestCase;

/**
 * Reading a font file's own family name (custom-certs C6c).
 *
 * The family is the whole point of the upload: a card template asks for
 * "Brush Script MT" by name, and the file only helps if the family INSIDE it
 * matches. Trusting the filename would let brushscript.ttf silently satisfy a
 * template asking for something else — the card would then print in a
 * substituted font that looks close enough to miss until it is on stock.
 */
class FontFileTest extends TestCase
{
    /** A real font from the container, so this reads actual tables. */
    private const REAL_TTF = '/usr/share/fonts/truetype/liberation/LiberationSerif-Regular.ttf';

    protected function setUp(): void
    {
        parent::setUp();

        if (! is_file(self::REAL_TTF)) {
            $this->markTestSkipped('The image is missing its Liberation fonts.');
        }
    }

    private function tempFile(string $bytes): string
    {
        $path = tempnam(sys_get_temp_dir(), 'font');
        file_put_contents($path, $bytes);

        return $path;
    }

    public function test_it_reads_the_family_out_of_a_real_ttf(): void
    {
        $this->assertSame(
            'Liberation Serif',
            FontFile::read(self::REAL_TTF)->family,
        );
    }

    public function test_it_reports_the_format_it_found(): void
    {
        $this->assertSame('ttf', FontFile::read(self::REAL_TTF)->format);
    }

    public function test_an_opentype_container_is_read_as_otf(): void
    {
        /*
         * The image ships no .otf, so this swaps a real TTF's signature for
         * OTTO. That is exactly the difference at this layer — an sfnt is an
         * sfnt, and the tables are read identically — so the test is honest
         * about what it covers: the container, not CFF outlines.
         */
        $bytes = (string) file_get_contents(self::REAL_TTF);
        $path = $this->tempFile('OTTO'.substr($bytes, 4));

        try {
            $font = FontFile::read($path);

            $this->assertSame('otf', $font->format);
            $this->assertSame('Liberation Serif', $font->family);
        } finally {
            @unlink($path);
        }
    }

    public function test_a_web_font_is_refused_rather_than_silently_useless(): void
    {
        // LibreOffice does not load woff from a font directory, so accepting
        // one would be an upload that changes nothing about the print.
        $path = $this->tempFile('wOFF'.str_repeat("\x00", 100));

        $this->expectException(InvalidFontFile::class);

        try {
            FontFile::read($path);
        } finally {
            @unlink($path);
        }
    }

    public function test_a_file_that_is_not_a_font_is_rejected(): void
    {
        // Magic bytes, not the extension: an uploaded .ttf is whatever the
        // uploader named it.
        $path = $this->tempFile('GIF89a definitely not a font');

        $this->expectException(InvalidFontFile::class);

        try {
            FontFile::read($path);
        } finally {
            @unlink($path);
        }
    }

    public function test_a_truncated_font_is_rejected_rather_than_half_read(): void
    {
        // Right magic, nothing behind it. A partial upload must not become a
        // font row pointing at an unusable file.
        $path = $this->tempFile("\x00\x01\x00\x00".str_repeat("\x00", 20));

        $this->expectException(InvalidFontFile::class);

        try {
            FontFile::read($path);
        } finally {
            @unlink($path);
        }
    }

    public function test_a_missing_file_is_rejected(): void
    {
        $this->expectException(InvalidFontFile::class);

        FontFile::read('/tmp/there-is-no-such-font.ttf');
    }

    public function test_the_family_matches_a_template_declaration_case_insensitively(): void
    {
        /*
         * Templates declare families as the designer typed them; the file
         * carries its own capitalisation. "LIBERATION SERIF" in a slide has
         * to be satisfied by this upload, or the warning never clears and the
         * feature looks broken.
         */
        $font = FontFile::read(self::REAL_TTF);

        $this->assertTrue($font->satisfies('liberation serif'));
        $this->assertTrue($font->satisfies('LIBERATION SERIF'));
        $this->assertTrue($font->satisfies('  Liberation Serif  '));
        $this->assertFalse($font->satisfies('Liberation Sans'));
    }
}
