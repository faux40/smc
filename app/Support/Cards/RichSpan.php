<?php

namespace App\Support\Cards;

/**
 * One stretch of card text that shares a single set of formatting flags —
 * the unit both the PPTX and ODP writers turn into a run.
 *
 * Flags rather than a style name because they combine: `**bold *and italic***`
 * produces a span carrying both, and a name like "bold" could not say that.
 */
final class RichSpan
{
    public function __construct(
        public readonly string $text,
        public readonly bool $bold = false,
        public readonly bool $italic = false,
    ) {}

    /** True when `$other` would render identically, so the two can be one run. */
    public function sameFormatting(self $other): bool
    {
        return $this->bold === $other->bold && $this->italic === $other->italic;
    }

    public function append(string $text): self
    {
        return new self($this->text.$text, $this->bold, $this->italic);
    }
}
