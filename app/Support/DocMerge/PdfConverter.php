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
     * How many files one soffice invocation is given. Process startup is most
     * of the cost, so batching matters; the cap keeps the command line sane
     * for a large class.
     */
    private const BATCH = 25;

    /**
     * Convert many files in as few soffice runs as possible; returns the PDF
     * paths in input order.
     *
     * A card run converts one file per person (custom-certs C4), and starting
     * LibreOffice 40 times to print 40 cards would dominate the job's runtime.
     * soffice accepts multiple inputs per invocation and writes one PDF each.
     *
     * `$home` overrides the HOME the converter runs under, which is how a
     * card run makes its org's uploaded fonts visible (custom-certs C6c):
     * fontconfig reads `$HOME/.fonts`, so staging files there lets soffice
     * SEE a family without installing anything into the container and
     * without one org's licensed font reaching another's cards. Null keeps
     * the shared profile — measured at ~70ms cheaper, and the common case
     * has no fonts to stage.
     *
     * @param  list<string>  $inputPaths
     * @return list<string>
     */
    public function toPdfBatch(array $inputPaths, string $outputDir, ?string $home = null): array
    {
        $outputs = [];

        foreach (array_chunk($inputPaths, self::BATCH) as $chunk) {
            $process = new Process([
                config('services.soffice.path', 'soffice'),
                '--headless',
                '--convert-to', 'pdf',
                '--outdir', $outputDir,
                ...$chunk,
            ]);
            $process->setEnv(['HOME' => $home ?? sys_get_temp_dir()]);
            // Longer than the single-file timeout: this is many documents.
            $process->setTimeout(600);
            $process->run();

            foreach ($chunk as $input) {
                $pdf = $outputDir.'/'.pathinfo($input, PATHINFO_FILENAME).'.pdf';

                // Checked per file rather than trusting the exit code: soffice
                // can report success while quietly skipping one input, and a
                // missing card must not become a blank cell on the sheet.
                if (! is_file($pdf)) {
                    throw new \RuntimeException(
                        'PDF conversion failed for '.basename($input).': '
                        .trim($process->getErrorOutput() ?: $process->getOutput()),
                    );
                }

                $outputs[] = $pdf;
            }
        }

        return $outputs;
    }

    /**
     * Convert $inputPath to PDF in $outputDir; returns the PDF path.
     */
    public function toPdf(string $inputPath, string $outputDir, ?string $home = null): string
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
        $process->setEnv(['HOME' => $home ?? sys_get_temp_dir()]);
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
