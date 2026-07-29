<?php

namespace App\Support\Cards;

/**
 * What an uploaded card template tells us about itself: how many sides it
 * has, how big the card is (points, read from the slide dimensions — the
 * user never types it), which `${keys}` it merges, and which font families
 * it asks for.
 */
class CardTemplateInfo
{
    /**
     * @param  array<int, string>  $placeholders  distinct `${key}` names
     * @param  array<int, string>  $fonts  distinct declared font families
     */
    public function __construct(
        public readonly int $slideCount,
        public readonly float $cardWidth,
        public readonly float $cardHeight,
        public array $placeholders,
        public array $fonts,
    ) {}

    /** Two slides = front and back; one = single-sided. */
    public function hasBack(): bool
    {
        return $this->slideCount === 2;
    }

    /**
     * Declared families the converter cannot honour — LibreOffice would
     * substitute these and re-flow the card. A warning, not a blocker.
     *
     * @return array<int, string>
     */
    public function unsupportedFonts(): array
    {
        $supported = array_map(
            'mb_strtolower',
            (array) config('cards.supported_fonts', []),
        );

        return array_values(array_filter(
            $this->fonts,
            fn (string $font) => ! in_array(mb_strtolower($font), $supported, true),
        ));
    }
}
