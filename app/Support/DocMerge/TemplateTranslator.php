<?php

namespace App\Support\DocMerge;

/**
 * Translates `${key}` / `${key:MODIFIER}` template placeholders into
 * TinyButStrong `[m.key;...]` fields inside DOCX/ODT XML, ported from
 * bg_hazards_demo's LegacyTemplateTranslator (battle-tested against the
 * real policy template library).
 *
 * The two load-bearing parts:
 *  - stitchSplitPlaceholders(): editors fragment `${top_manager}` across
 *    XML runs (`${top_ma</text:span>nager}`); orphan open/close tags are
 *    relocated outside the placeholder so the XML stays well-formed.
 *  - promoteLonelyListPlaceholdersToBlocks(): a list-type field standing
 *    alone in a bullet/paragraph becomes a TBS repeating block
 *    (`[key_1.item;block=text:list-item]`) so each row gets its own
 *    bullet; inline usage of the same key stays a joined [m.key] string.
 *
 * Differences from the demo:
 *  - list fields are dynamic (from the merge-field registry), block base
 *    = the field key, row key is always 'item';
 *  - multiline fields get `ope=changebreak` so stored newlines become
 *    proper <w:br/> / <text:line-break/> per format (replaces the demo's
 *    DOCX-only literal `<w:br/>` injection for med_provider_info).
 */
class TemplateTranslator
{
    private const MODIFIER_MAP = [
        'ALLCAP' => 'ope=upper',
        'CAP' => 'ope=upperw',
        'STDATE' => "frm='mmmm d, yyyy'",
        'FULLDATE' => "frm='dddd, mmmm d, yyyy'",
        'WEEKDAY' => "frm='dddd'",
        'MDDATE' => 'onformat=tbs_ordinal_date',
    ];

    /** Row key inside generated blocks: `[key_N.item;block=...]`. */
    public const BLOCK_ROW_KEY = 'item';

    /** @var array<string, true> */
    private array $listFields;

    /** @var array<string, true> */
    private array $multilineFields;

    /** unique block name -> logical field key, populated during translate. */
    private array $generatedBlockMap = [];

    /** field key -> running counter used to mint unique block names. */
    private array $blockCounters = [];

    /**
     * @param  array<int, string>  $listFieldKeys  registry fields of type 'list'
     * @param  array<int, string>  $multilineFieldKeys  registry fields of type 'multiline'
     */
    public function __construct(array $listFieldKeys = [], array $multilineFieldKeys = [])
    {
        $this->listFields = array_fill_keys($listFieldKeys, true);
        $this->multilineFields = array_fill_keys($multilineFieldKeys, true);
    }

    public function translate(string $input): string
    {
        $stitched = $this->stitchSplitPlaceholders($input);
        $promoted = $this->promoteLonelyListPlaceholdersToBlocks($stitched);

        return preg_replace_callback(
            '/\$\{([a-zA-Z_][a-zA-Z0-9_]*)(?::([A-Z]+))?\}/',
            fn ($m) => $this->buildReplacement($m[1], $m[2] ?? null),
            $promoted,
        );
    }

    /**
     * Map of unique generated block name -> field key
     * (e.g. "eap_info_1" => "eap_info"). Reset per translateFile().
     *
     * @return array<string, string>
     */
    public function generatedBlockMap(): array
    {
        return $this->generatedBlockMap;
    }

    /**
     * Distinct `${key}` names used across every XML subfile of a
     * DOCX/ODT archive (stitching applied first, so fragmented
     * placeholders are found too). Modifier suffixes are stripped:
     * `${agency:CAP}` reports as `agency`.
     *
     * @return array<int, string>
     */
    public function findPlaceholders(string $filePath): array
    {
        $names = [];
        $this->eachXmlSubfile($filePath, function (string $name, string $content) use (&$names): ?string {
            $stitched = $this->stitchSplitPlaceholders($content);
            if (preg_match_all('/\$\{([a-zA-Z_][a-zA-Z0-9_]*)(?::[A-Z]+)?\}/', $stitched, $matches)) {
                $names = array_merge($names, $matches[1]);
            }

            return null; // read-only pass
        });

        return array_values(array_unique($names));
    }

    /**
     * Copy the template to $outputPath and translate every XML subfile
     * in place. Returns $outputPath.
     */
    public function translateFile(string $inputPath, string $outputPath): string
    {
        $this->generatedBlockMap = [];
        $this->blockCounters = [];

        if (! copy($inputPath, $outputPath)) {
            throw new \RuntimeException("Failed to copy {$inputPath} to {$outputPath}");
        }

        $this->eachXmlSubfile($outputPath, function (string $name, string $content): ?string {
            $translated = $this->translate($content);

            return $translated === $content ? null : $translated;
        });

        return $outputPath;
    }

    /**
     * Iterate the .xml members of a zip archive; a non-null return from
     * the callback replaces that member's content.
     */
    private function eachXmlSubfile(string $path, callable $callback): void
    {
        (new ZipXmlEditor)->each($path, $callback);
    }

    // ---- list-block promotion --------------------------------------

    private function promoteLonelyListPlaceholdersToBlocks(string $xml): string
    {
        // ODT bullets live in <text:list-item>; clone at that level so
        // each row gets its own bullet.
        $xml = $this->promoteOdtListItem($xml);
        // DOCX paragraphs are themselves the list items.
        $xml = $this->promoteWithinElement($xml, 'w:p', 'w:t', 'w:p');
        // ODT paragraphs not inside a <text:list-item>.
        $xml = $this->promoteWithinElement($xml, 'text:p', null, 'text:p');

        return $xml;
    }

    private function promoteOdtListItem(string $xml): string
    {
        return preg_replace_callback(
            '/<text:list-item\b[^>]*>(.*?)<\/text:list-item>/s',
            function ($match) {
                $textContent = strip_tags($match[1]);
                if (! preg_match('/^\s*\$\{([a-zA-Z_][a-zA-Z0-9_]*)\}\s*$/', $textContent, $m)) {
                    return $match[0];
                }
                $fieldName = $m[1];
                if (! isset($this->listFields[$fieldName])) {
                    return $match[0];
                }

                return str_replace(
                    '${'.$fieldName.'}',
                    $this->mintBlockSpec($fieldName, 'text:list-item'),
                    $match[0],
                );
            },
            $xml,
        );
    }

    private function promoteWithinElement(string $xml, string $paragraphTag, ?string $textTag, string $blockTagForTbs): string
    {
        $tagPattern = preg_quote($paragraphTag, '/');

        return preg_replace_callback(
            "/<{$tagPattern}\\b[^>]*>(.*?)<\\/{$tagPattern}>/s",
            function ($match) use ($textTag, $blockTagForTbs) {
                $inner = $match[1];

                $textContent = $textTag === null
                    ? strip_tags($inner)
                    : $this->concatenateTagContent($inner, $textTag);

                if (! preg_match('/^\$\{([a-zA-Z_][a-zA-Z0-9_]*)\}$/', trim($textContent), $m)) {
                    return $match[0];
                }
                $fieldName = $m[1];
                if (! isset($this->listFields[$fieldName])) {
                    return $match[0];
                }

                $newInner = str_replace(
                    '${'.$fieldName.'}',
                    $this->mintBlockSpec($fieldName, $blockTagForTbs),
                    $inner,
                );

                return str_replace($inner, $newInner, $match[0]);
            },
            $xml,
        );
    }

    private function mintBlockSpec(string $fieldKey, string $blockTag): string
    {
        $this->blockCounters[$fieldKey] = ($this->blockCounters[$fieldKey] ?? 0) + 1;
        $unique = $fieldKey.'_'.$this->blockCounters[$fieldKey];
        $this->generatedBlockMap[$unique] = $fieldKey;

        return '['.$unique.'.'.self::BLOCK_ROW_KEY.";block={$blockTag}]";
    }

    private function concatenateTagContent(string $xml, string $tag): string
    {
        $tagPattern = preg_quote($tag, '/');
        if (! preg_match_all("/<{$tagPattern}\\b[^>]*>(.*?)<\\/{$tagPattern}>/s", $xml, $matches)) {
            return '';
        }

        return implode('', $matches[1]);
    }

    // ---- split-placeholder stitching ---------------------------------

    private function stitchSplitPlaceholders(string $xml): string
    {
        return preg_replace_callback(
            '/\$(?:\{|[^{$]*\>\{)[^}$]*\}/U',
            fn ($m) => $this->stripPreservingOrphanTags($m[0]),
            $xml,
        );
    }

    /**
     * Strip XML tags from inside a placeholder, but preserve orphan tags
     * by relocating them to the side of the placeholder where their
     * counterpart lives — keeps the document well-formed when an editor
     * split a span across a placeholder boundary.
     */
    private function stripPreservingOrphanTags(string $matched): string
    {
        preg_match_all('/<\/?[a-zA-Z][a-zA-Z0-9:_-]*\b[^>]*?>/', $matched, $tagMatches);
        $stack = [];
        $orphanClosers = [];
        foreach ($tagMatches[0] as $tag) {
            if (str_starts_with($tag, '</')) {
                if (! empty($stack)) {
                    array_pop($stack);
                } else {
                    $orphanClosers[] = $tag;
                }
            } elseif (substr($tag, -2) !== '/>') {
                $stack[] = $tag;
            }
        }
        $orphanOpeners = $stack;

        return implode('', $orphanOpeners).strip_tags($matched).implode('', $orphanClosers);
    }

    // ---- field replacement ---------------------------------------------

    private function buildReplacement(string $name, ?string $modifier): string
    {
        $params = [];

        if ($modifier !== null && isset(self::MODIFIER_MAP[$modifier])) {
            $params[] = self::MODIFIER_MAP[$modifier];
        }

        // Stored newlines in multiline values become the document
        // format's own line-break element.
        if (isset($this->multilineFields[$name])) {
            $params[] = 'ope=changebreak';
        }

        return $params === []
            ? "[m.{$name}]"
            : "[m.{$name};".implode(';', $params).']';
    }
}
