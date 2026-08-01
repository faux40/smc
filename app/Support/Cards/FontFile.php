<?php

namespace App\Support\Cards;

/**
 * A font file's own account of itself (custom-certs C6c): the family name
 * LibreOffice will match a template's declaration against, read out of the
 * file's `name` table.
 *
 * Read from the file, never from its filename. A card template asks for
 * "Brush Script MT" BY NAME, and an upload only helps if the family inside
 * the file is that family — otherwise the card prints in a substituted font
 * that looks close enough to miss until it is on purchased stock.
 *
 * Parsed here rather than shelled out to `fc-scan` so it works the same in
 * tests, in the queue and on a machine without fontconfig, and so a bad
 * upload fails with a message rather than a non-zero exit code.
 */
class FontFile
{
    /**
     * TrueType outlines (`\0\1\0\0` or `true`), OpenType/CFF (`OTTO`).
     * Deliberately NOT woff/woff2: LibreOffice does not load them from a
     * font directory, so accepting one would produce an upload that changes
     * nothing.
     */
    private const SIGNATURES = [
        "\x00\x01\x00\x00" => 'ttf',
        'true' => 'ttf',
        'ttcf' => 'ttf',
        'OTTO' => 'otf',
    ];

    /** name IDs worth reading, best first: typographic family, then family. */
    private const FAMILY_NAME_IDS = [16, 1];

    private function __construct(
        public readonly string $family,
        public readonly string $format,
    ) {}

    /**
     * @throws InvalidFontFile when the file is not a usable TTF/OTF
     */
    public static function read(string $path): self
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new InvalidFontFile('That font file could not be read.');
        }

        $bytes = (string) file_get_contents($path);
        $format = self::SIGNATURES[substr($bytes, 0, 4)] ?? null;

        if ($format === null) {
            throw new InvalidFontFile(
                'That is not a TrueType or OpenType font. Upload a .ttf or .otf file.',
            );
        }

        $family = self::family($bytes);

        if ($family === null) {
            throw new InvalidFontFile(
                'That font file is damaged or incomplete — its name could not be read.',
            );
        }

        return new self($family, $format);
    }

    /**
     * Does this file satisfy a template's declaration of `$declared`?
     *
     * Case- and space-insensitive: templates carry the family as the designer
     * typed it, the file carries its own capitalisation, and a warning that
     * never clears over a capital letter would read as the feature not
     * working.
     */
    public function satisfies(string $declared): bool
    {
        return self::normalise($declared) === self::normalise($this->family);
    }

    public static function normalise(string $family): string
    {
        return mb_strtolower(trim($family));
    }

    /** The family name from the `name` table, or null if unreadable. */
    private static function family(string $bytes): ?string
    {
        $table = self::nameTable($bytes);

        if ($table === null) {
            return null;
        }

        [$offset, $length] = $table;

        if (strlen($bytes) < $offset + 6) {
            return null;
        }

        $count = self::uint16($bytes, $offset + 2);
        $stringsAt = $offset + self::uint16($bytes, $offset + 4);

        $found = [];

        for ($i = 0; $i < $count; $i++) {
            $record = $offset + 6 + $i * 12;

            if (strlen($bytes) < $record + 12) {
                return null;
            }

            $platform = self::uint16($bytes, $record);
            $nameId = self::uint16($bytes, $record + 6);
            $len = self::uint16($bytes, $record + 8);
            $at = $stringsAt + self::uint16($bytes, $record + 10);

            if (! in_array($nameId, self::FAMILY_NAME_IDS, true)) {
                continue;
            }

            if ($at + $len > $offset + $length || $at + $len > strlen($bytes)) {
                continue; // record points outside the table: damaged file
            }

            $value = substr($bytes, $at, $len);

            // Platform 3 (Windows) and 0 (Unicode) store UTF-16BE; platform 1
            // (Macintosh) is single-byte.
            if ($platform !== 1) {
                $value = mb_convert_encoding($value, 'UTF-8', 'UTF-16BE');
            }

            $value = trim($value);

            if ($value !== '') {
                // Keyed by name ID so the preference order below is available
                // regardless of the order records appear in the file.
                $found[$nameId] ??= $value;
            }
        }

        foreach (self::FAMILY_NAME_IDS as $nameId) {
            if (isset($found[$nameId])) {
                return $found[$nameId];
            }
        }

        return null;
    }

    /**
     * Offset and length of the `name` table.
     *
     * @return array{0: int, 1: int}|null
     */
    private static function nameTable(string $bytes): ?array
    {
        if (strlen($bytes) < 12) {
            return null;
        }

        $tables = self::uint16($bytes, 4);

        for ($i = 0; $i < $tables; $i++) {
            $entry = 12 + $i * 16;

            if (strlen($bytes) < $entry + 16) {
                return null;
            }

            if (substr($bytes, $entry, 4) !== 'name') {
                continue;
            }

            $offset = self::uint32($bytes, $entry + 8);
            $length = self::uint32($bytes, $entry + 12);

            return $offset + $length <= strlen($bytes) ? [$offset, $length] : null;
        }

        return null;
    }

    private static function uint16(string $bytes, int $at): int
    {
        return (int) unpack('n', substr($bytes, $at, 2))[1];
    }

    private static function uint32(string $bytes, int $at): int
    {
        return (int) unpack('N', substr($bytes, $at, 4))[1];
    }
}
