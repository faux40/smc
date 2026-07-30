<?php

namespace App\Support\Cards;

use App\Support\DocMerge\PdfConverter;
use Symfony\Component\Process\Process;

/**
 * Rewrites a PDF into the shape FPDI can read (custom-certs C4c).
 *
 * FPDI's bundled parser handles PDF ≤1.4. LibreOffice emits 1.6/1.7, whose
 * cross-reference and object streams it can't follow — so every converted card
 * passes through here before {@see CardImposer} places it. `qpdf` rewrites
 * those structures without touching the page content, so nothing about the
 * rendered card changes.
 *
 * Container-injected like {@see PdfConverter} so tests
 * needn't shell out.
 */
class PdfNormalizer
{
    /**
     * Normalise $inputPath to $outputPath; returns the output path.
     *
     * @throws \RuntimeException when qpdf is missing or refuses the file
     */
    public function normalize(string $inputPath, string $outputPath): string
    {
        $process = new Process([
            config('services.qpdf.path', 'qpdf'),
            // The one thing FPDI needs: no object/xref streams. Stream *data*
            // stays compressed, so the file doesn't balloon.
            '--object-streams=disable',
            $inputPath,
            $outputPath,
        ]);
        $process->setTimeout(60);
        $process->run();

        // qpdf exits 3 for warnings it recovered from and still wrote the
        // file — a linearisation grumble shouldn't fail a print run.
        $recovered = $process->getExitCode() === 3 && is_file($outputPath);

        if (! $process->isSuccessful() && ! $recovered) {
            throw new \RuntimeException(
                'PDF normalisation failed: '.trim($process->getErrorOutput() ?: $process->getOutput()),
            );
        }

        if (! is_file($outputPath)) {
            throw new \RuntimeException("PDF normalisation produced no file at {$outputPath}.");
        }

        return $outputPath;
    }
}
