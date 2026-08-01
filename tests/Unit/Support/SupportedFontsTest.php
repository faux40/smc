<?php

namespace Tests\Unit\Support;

use App\Support\Cards\SupportedFonts;
use PHPUnit\Framework\TestCase;

/**
 * Which declared families will actually print (custom-certs C6c).
 *
 * One resolver, because the answer is given in three places that must agree:
 * the warning at design upload, the warning in the print dialog, and what the
 * job stages before conversion. A design warned about a font it will in fact
 * print — or worse, silently substituted for one it was told was fine — is
 * the failure this whole pass exists to prevent.
 */
class SupportedFontsTest extends TestCase
{
    private function resolver(array $uploaded = []): SupportedFonts
    {
        // The installed list passed in rather than read from config: this is
        // arithmetic over two sets, and the container's font list is not the
        // thing under test.
        return new SupportedFonts(
            installed: ['Liberation Sans', 'Arial', 'Carlito'],
            uploaded: $uploaded,
        );
    }

    public function test_an_installed_family_needs_no_upload(): void
    {
        $this->assertSame([], $this->resolver()->missingFrom(['Arial']));
    }

    public function test_matching_ignores_case_and_stray_space(): void
    {
        // Designers type the family; the file and the config carry their own
        // capitalisation. A warning that survives a capital letter reads as
        // the feature being broken.
        $this->assertSame([], $this->resolver()->missingFrom(['  ARIAL  ', 'liberation sans']));
    }

    public function test_a_family_nobody_has_is_reported(): void
    {
        $this->assertSame(
            ['Brush Script MT'],
            $this->resolver()->missingFrom(['Arial', 'Brush Script MT']),
        );
    }

    public function test_an_uploaded_family_stops_being_missing(): void
    {
        // The point of C6c: uploading the file clears the warning.
        $this->assertSame(
            [],
            $this->resolver(['Brush Script MT'])->missingFrom(['Brush Script MT']),
        );
    }

    public function test_it_reports_the_name_the_design_used(): void
    {
        /*
         * The warning has to name what the designer will find in their slide,
         * not the canonical spelling — they have to go and change it, or
         * recognise it to upload the right file.
         */
        $this->assertSame(
            ['bRuSh ScRiPt'],
            $this->resolver()->missingFrom(['bRuSh ScRiPt']),
        );
    }

    public function test_a_family_declared_twice_is_reported_once(): void
    {
        $this->assertSame(
            ['Gotham'],
            $this->resolver()->missingFrom(['Gotham', 'gotham', 'GOTHAM']),
        );
    }

    public function test_empty_declarations_are_ignored(): void
    {
        // ODF writes fo:font-family="" in places; it is not a missing font.
        $this->assertSame([], $this->resolver()->missingFrom(['', '   ']));
    }

    public function test_it_knows_which_uploads_a_design_actually_needs(): void
    {
        /*
         * Staging every font an org owns into every run would be wasted I/O
         * and would let an unrelated licensed font ride along into a PDF that
         * gets emailed out. Only what the design asks for goes in.
         *
         * Lookup KEYS, not the design's spelling: unlike the warning — which
         * has to echo what the designer will find in their slide — this
         * result identifies rows to fetch, so it is normalised for exactly
         * that comparison.
         */
        $resolver = $this->resolver(['Brush Script MT', 'Gotham']);

        $this->assertSame(
            ['brush script mt'],
            $resolver->neededFrom(['Arial', 'bRuSh ScRiPt MT']),
        );
    }
}
