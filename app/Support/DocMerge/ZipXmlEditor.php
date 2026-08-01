<?php

namespace App\Support\DocMerge;

use App\Support\Cards\RichTextExpander;
use RuntimeException;
use ZipArchive;

/**
 * Read and rewrite the `.xml` members of an office document.
 *
 * Every format the app touches — docx, odt, pptx, odp — is a zip of XML, and
 * both the placeholder translation that happens before a merge
 * ({@see TemplateTranslator}) and the rich-text expansion that happens after
 * it ({@see RichTextExpander}) are the same walk over those
 * members. Extracted so the second one isn't a second implementation of it.
 */
class ZipXmlEditor
{
    /**
     * Iterate the `.xml` members of a zip archive; a non-null return from the
     * callback replaces that member's content.
     *
     * @param  callable(string, string): ?string  $callback  (member name, content) => replacement|null
     */
    public function each(string $path, callable $callback): void
    {
        $zip = new ZipArchive;

        if ($zip->open($path) !== true) {
            throw new RuntimeException("Failed to open archive: {$path}");
        }

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);

            if (! str_ends_with($name, '.xml')) {
                continue;
            }

            $content = $zip->getFromIndex($i);

            if ($content === false) {
                continue;
            }

            $replacement = $callback($name, $content);

            if ($replacement !== null) {
                $zip->addFromString($name, $replacement);
            }
        }

        $zip->close();
    }
}
