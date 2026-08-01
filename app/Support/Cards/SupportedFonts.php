<?php

namespace App\Support\Cards;

use App\Models\CardFont;

/**
 * Which of a design's declared font families will actually print
 * (custom-certs C6c) — the families installed in the image plus the ones
 * this org has uploaded.
 *
 * One resolver because the answer is given in three places that must agree:
 * the warning when a design is uploaded, the warning in the print dialog, and
 * what the job stages before conversion. A design warned about a font it will
 * in fact print is noise; a design told it was fine and then silently
 * substituted is a box of misprinted cards.
 */
class SupportedFonts
{
    /** @var array<string, true> normalised installed families */
    private array $installed;

    /** @var array<string, true> normalised uploaded families */
    private array $uploaded;

    /**
     * @param  array<int, string>  $installed  families the image ships (config)
     * @param  array<int, string>  $uploaded  families this org has uploaded
     */
    public function __construct(array $installed, array $uploaded = [])
    {
        $this->installed = self::index($installed);
        $this->uploaded = self::index($uploaded);
    }

    /** The resolver for one org: config families plus that org's uploads. */
    public static function forOrg(?string $orgId): self
    {
        return new self(
            (array) config('cards.supported_fonts', []),
            $orgId === null ? [] : CardFont::query()
                ->withoutGlobalScope('organization')
                ->where('org_id', $orgId)
                ->pluck('family')
                ->all(),
        );
    }

    /**
     * Declared families that would be substituted — the warning.
     *
     * Returned as the design spelled them: the designer has to find these in
     * their own slide, either to change them or to recognise which file to
     * upload.
     *
     * @param  array<int, string>  $declared
     * @return list<string>
     */
    public function missingFrom(array $declared): array
    {
        return $this->filter($declared, fn (string $key) => ! isset($this->installed[$key]) && ! isset($this->uploaded[$key]));
    }

    /**
     * Uploaded families this design actually asks for — what the run stages,
     * as normalised lookup keys (`card_fonts.family_key`).
     *
     * Only what the design needs: staging every font an org owns into every
     * run is wasted I/O, and it would let an unrelated licensed font ride
     * along into a PDF that gets emailed out.
     *
     * Keys rather than the design's spelling, unlike {@see missingFrom()}:
     * this result identifies rows to fetch, not text to show someone.
     *
     * @param  array<int, string>  $declared
     * @return list<string>
     */
    public function neededFrom(array $declared): array
    {
        return array_map(
            fn (string $family) => FontFile::normalise($family),
            $this->filter($declared, fn (string $key) => isset($this->uploaded[$key])),
        );
    }

    /**
     * @param  array<int, string>  $declared
     * @param  callable(string): bool  $keep
     * @return list<string>
     */
    private function filter(array $declared, callable $keep): array
    {
        $out = [];
        $seen = [];

        foreach ($declared as $family) {
            $key = FontFile::normalise($family);

            // ODF writes fo:font-family="" in places; that is not a font.
            if ($key === '' || isset($seen[$key]) || ! $keep($key)) {
                continue;
            }

            $seen[$key] = true;
            $out[] = $family;
        }

        return $out;
    }

    /**
     * @param  array<int, string>  $families
     * @return array<string, true>
     */
    private static function index(array $families): array
    {
        $index = [];

        foreach ($families as $family) {
            $key = FontFile::normalise((string) $family);

            if ($key !== '') {
                $index[$key] = true;
            }
        }

        return $index;
    }
}
