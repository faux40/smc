<?php

namespace App\Support;

/**
 * Single source of truth for arranging a person's five name parts into the
 * display formats the app uses. Models delegate their name accessors here so
 * the formatting lives in exactly one place (and stays unit-testable in
 * isolation from any persistence layer).
 */
final class PersonName
{
    public function __construct(
        public readonly ?string $prefix = null,
        public readonly ?string $first = null,
        public readonly ?string $middle = null,
        public readonly ?string $last = null,
        public readonly ?string $suffix = null,
    ) {}

    /**
     * Natural reading order: "Dr. Ada Augusta Lovelace III".
     */
    public function full(): string
    {
        return self::squish([
            $this->prefix,
            $this->first,
            $this->middle,
            $this->last,
            $this->suffix,
        ]);
    }

    /**
     * Sortable / list order: "Lovelace, Ada Augusta". Prefix and suffix are
     * dropped so columns sort and scan cleanly. Degrades gracefully when a
     * part is missing — no leading or trailing comma.
     */
    public function sortable(): string
    {
        $lead = self::squish([$this->last]);
        $rest = self::squish([$this->first, $this->middle]);

        if ($lead !== '' && $rest !== '') {
            return "{$lead}, {$rest}";
        }

        return $lead !== '' ? $lead : $rest;
    }

    /**
     * Compact form: "Ada Lovelace" — first + last only.
     */
    public function short(): string
    {
        return self::squish([$this->first, $this->last]);
    }

    /**
     * Avatar initials from first + last, uppercased: "AL". Falls back to the
     * single available part, or '' when there is no name at all.
     */
    public function initials(): string
    {
        $letter = static fn (?string $part): string => mb_substr(trim((string) $part), 0, 1);

        return mb_strtoupper($letter($this->first).$letter($this->last));
    }

    /**
     * Join non-empty, trimmed parts with single spaces.
     *
     * @param  array<int, ?string>  $parts
     */
    private static function squish(array $parts): string
    {
        $clean = array_filter(
            array_map(fn (?string $p): string => trim((string) $p), $parts),
            fn (string $p): bool => $p !== '',
        );

        return preg_replace('/\s+/', ' ', implode(' ', $clean)) ?? '';
    }
}
