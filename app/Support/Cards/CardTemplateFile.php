<?php

namespace App\Support\Cards;

use App\Support\DocMerge\TemplateTranslator;
use ZipArchive;

/**
 * Reads an uploaded card template (PPTX or ODP) without trusting anything the
 * client said about it. Structural checks beat mime guessing: the file has to
 * be an archive containing the format's main part.
 *
 * A card template is ONE card — slide 1 the front, an optional slide 2 the
 * back — because SMC imposes the sheet itself. The slide dimensions therefore
 * ARE the card size, which is why nobody types it.
 */
class CardTemplateFile
{
    public const EXTENSIONS = ['pptx', 'odp'];

    /** EMU per point: PPTX stores dimensions in English Metric Units. */
    private const EMU_PER_POINT = 12700;

    private const ODP_MIMETYPE = 'application/vnd.oasis.opendocument.presentation';

    /** Points per unit for the lengths ODF page layouts use. */
    private const ODF_UNITS = [
        'in' => 72.0,
        'pt' => 1.0,
        'cm' => 72.0 / 2.54,
        'mm' => 72.0 / 25.4,
        'pc' => 12.0,
    ];

    /**
     * @throws InvalidCardTemplate
     */
    public static function inspect(string $path, string $extension): CardTemplateInfo
    {
        $extension = strtolower($extension);

        if (! in_array($extension, self::EXTENSIONS, true)) {
            throw new InvalidCardTemplate('Card templates must be .pptx or .odp files.');
        }

        $zip = new ZipArchive;

        if ($zip->open($path) !== true) {
            throw new InvalidCardTemplate('The file is not a valid presentation archive.');
        }

        try {
            $info = $extension === 'pptx'
                ? self::inspectPptx($zip)
                : self::inspectOdp($zip);
        } finally {
            $zip->close();
        }

        if ($info->slideCount < 1 || $info->slideCount > 2) {
            throw new InvalidCardTemplate(
                'A card template must have one or two slides — the front, and optionally the back. '
                ."This file has {$info->slideCount}.",
            );
        }

        // Placeholder extraction walks every XML member, so it is shared with
        // the document templates rather than reimplemented per format.
        $info->placeholders = (new TemplateTranslator)->findPlaceholders($path);

        return $info;
    }

    private static function inspectPptx(ZipArchive $zip): CardTemplateInfo
    {
        $presentation = $zip->getFromName('ppt/presentation.xml');

        if ($presentation === false) {
            throw new InvalidCardTemplate('The file is not a valid PowerPoint presentation.');
        }

        $slides = 0;
        $fonts = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);

            if (preg_match('#^ppt/slides/slide\d+\.xml$#', $name) !== 1) {
                continue;
            }

            $slides++;
            $fonts = [...$fonts, ...self::pptxTypefaces((string) $zip->getFromIndex($i))];
        }

        [$width, $height] = self::pptxSlideSize($presentation);

        return new CardTemplateInfo(
            slideCount: $slides,
            cardWidth: $width,
            cardHeight: $height,
            placeholders: [],
            fonts: array_values(array_unique($fonts)),
        );
    }

    private static function inspectOdp(ZipArchive $zip): CardTemplateInfo
    {
        $content = $zip->getFromName('content.xml');
        $mimetype = $zip->getFromName('mimetype');

        if ($content === false) {
            throw new InvalidCardTemplate('The file is not a valid OpenDocument file.');
        }

        // An ODT carries content.xml too — the mimetype entry is what makes
        // this a presentation rather than a text document.
        if (trim((string) $mimetype) !== self::ODP_MIMETYPE) {
            throw new InvalidCardTemplate('The file is not an OpenDocument presentation (.odp).');
        }

        $slides = preg_match_all('/<draw:page\b/', $content);

        $fonts = self::odfFontFamilies($content);
        $styles = $zip->getFromName('styles.xml');

        if ($styles !== false) {
            $fonts = [...$fonts, ...self::odfFontFamilies($styles)];
        }

        [$width, $height] = self::odpPageSize($styles === false ? '' : $styles);

        return new CardTemplateInfo(
            slideCount: (int) $slides,
            cardWidth: $width,
            cardHeight: $height,
            placeholders: [],
            fonts: array_values(array_unique($fonts)),
        );
    }

    /**
     * `<p:sldSz cx="…" cy="…"/>` in EMU.
     *
     * @return array{0: float, 1: float}
     */
    private static function pptxSlideSize(string $presentationXml): array
    {
        if (preg_match('/<p:sldSz\b[^>]*\bcx="(\d+)"[^>]*\bcy="(\d+)"/', $presentationXml, $m) !== 1) {
            throw new InvalidCardTemplate('The presentation does not declare a slide size.');
        }

        return [
            (float) $m[1] / self::EMU_PER_POINT,
            (float) $m[2] / self::EMU_PER_POINT,
        ];
    }

    /**
     * `fo:page-width` / `fo:page-height` off the page layout in styles.xml.
     *
     * @return array{0: float, 1: float}
     */
    private static function odpPageSize(string $stylesXml): array
    {
        $width = self::odfLength($stylesXml, 'fo:page-width');
        $height = self::odfLength($stylesXml, 'fo:page-height');

        if ($width === null || $height === null) {
            throw new InvalidCardTemplate('The presentation does not declare a page size.');
        }

        return [$width, $height];
    }

    /** An ODF length ("3.375in", "8.56cm", "153pt") in points. */
    private static function odfLength(string $xml, string $attribute): ?float
    {
        $pattern = '/'.preg_quote($attribute, '/').'="([0-9.]+)(in|pt|cm|mm|pc)"/';

        if (preg_match($pattern, $xml, $m) !== 1) {
            return null;
        }

        return (float) $m[1] * self::ODF_UNITS[$m[2]];
    }

    /**
     * Font families named by a PPTX slide. Theme references ("+mn-lt",
     * "+mj-lt") are not families — the theme resolves those — so they are
     * left out rather than reported as a missing font.
     *
     * @return array<int, string>
     */
    private static function pptxTypefaces(string $slideXml): array
    {
        preg_match_all('/typeface="([^"]+)"/', $slideXml, $matches);

        return array_values(array_filter(
            $matches[1] ?? [],
            fn (string $face) => $face !== '' && ! str_starts_with($face, '+'),
        ));
    }

    /**
     * Font families declared by an ODF part, from both the font-face
     * declarations and any inline `fo:font-family`.
     *
     * @return array<int, string>
     */
    private static function odfFontFamilies(string $xml): array
    {
        preg_match_all('/(?:svg:font-family|fo:font-family)="([^"]+)"/', $xml, $matches);

        return array_values(array_filter(array_map(
            // ODF quotes families containing spaces: svg:font-family="'Foo Bar'"
            fn (string $family) => trim($family, " '\""),
            $matches[1] ?? [],
        )));
    }
}
