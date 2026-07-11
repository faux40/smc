<?php

namespace App\Support\DocMerge;

use Symfony\Component\Process\Process;

/**
 * DOCX/ODT -> PDF via LibreOffice headless (`soffice`, in the Docker
 * image since Phase D2). Container-injected so tests mock it; fidelity
 * against the real template library was verified in the D2 spike.
 */
class PdfConverter
{
    /**
     * Convert $inputPath to PDF in $outputDir; returns the PDF path.
     */
    public function toPdf(string $inputPath, string $outputDir): string
    {
        $process = new Process([
            config('services.soffice.path', 'soffice'),
            '--headless',
            '--convert-to', 'pdf',
            '--outdir', $outputDir,
            $inputPath,
        ]);
        // soffice writes profile state to HOME; point it somewhere writable
        // (queue workers may run with a restricted HOME).
        $process->setEnv(['HOME' => sys_get_temp_dir()]);
        $process->setTimeout(120);
        $process->run();

        $pdfPath = $outputDir.'/'.pathinfo($inputPath, PATHINFO_FILENAME).'.pdf';

        if (! $process->isSuccessful() || ! is_file($pdfPath)) {
            throw new \RuntimeException(
                'PDF conversion failed: '.trim($process->getErrorOutput() ?: $process->getOutput()),
            );
        }

        return $pdfPath;
    }
}
