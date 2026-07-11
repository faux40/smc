<?php

namespace App\Support\DocMerge;

use clsTinyButStrong;

/**
 * The actual OpenTBS merge: TBS `[m.*]` fields + repeating blocks into
 * a (translated) DOCX/ODT, covering the main subfile plus every header
 * and footer. Ported from bg_hazards_demo's DocumentMergeService.
 */
class DocumentMergeService
{
    /**
     * @param  array<string, mixed>  $data  field key => scalar value
     * @param  array<string, array<int, array<string, string>>>  $blocks  block name => rows
     */
    public function merge(string $templatePath, array $data, string $outputPath, array $blocks = []): string
    {
        class_exists('clsOpenTBS'); // trigger the composer autoload shim

        $tbs = new clsTinyButStrong;
        $tbs->NoErr = true;
        $tbs->Plugin(TBS_INSTALL, OPENTBS_PLUGIN);
        $tbs->LoadTemplate($templatePath, OPENTBS_ALREADY_UTF8);

        $this->mergeIntoCurrentSubfile($tbs, $data, $blocks);

        foreach ((array) $tbs->Plugin(OPENTBS_GET_HEADERS_FOOTERS) as $subfile) {
            $tbs->Plugin(OPENTBS_SELECT_FILE, $subfile);
            $this->mergeIntoCurrentSubfile($tbs, $data, $blocks);
        }

        $tbs->Plugin(OPENTBS_SELECT_MAIN);
        $tbs->Show(OPENTBS_FILE, $outputPath);

        return $outputPath;
    }

    private function mergeIntoCurrentSubfile(clsTinyButStrong $tbs, array $data, array $blocks): void
    {
        foreach ($blocks as $name => $rows) {
            $tbs->MergeBlock($name, $rows);
        }
        $tbs->MergeField('m', $data);
    }
}
