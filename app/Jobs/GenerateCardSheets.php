<?php

namespace App\Jobs;

use App\Actions\FileClassDocument;
use App\Events\ClassChanged;
use App\Models\CardPrintRun;
use App\Support\Cards\CardImposer;
use App\Support\Cards\CardMergeData;
use App\Support\Cards\CardSheetPlan;
use App\Support\Cards\PdfNormalizer;
use App\Support\Cards\RichTextExpander;
use App\Support\Cards\RichTextMarkup;
use App\Support\DocMerge\DocumentMergeService;
use App\Support\DocMerge\PdfConverter;
use App\Support\DocMerge\TemplateTranslator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Prints a class topic's cards (custom-certs C4d): one merged card per person
 * who earned the credit → PDF → imposed onto sheets of the chosen stock →
 * filed into the class's documents.
 *
 * Modelled on {@see GenerateDocument}: a status row carries the outcome, and
 * failures land on it with a readable reason rather than vanishing into a log.
 * No retries — the run is deterministic, so a second attempt fails the same
 * way; the user fixes the cause and asks again.
 *
 * Fronts and backs are separate PDFs sharing one filename stamp: the operator
 * prints the fronts, reloads the stack and prints the backs, and the stamp is
 * what stops a fronts file being paired with another run's backs.
 */
class GenerateCardSheets implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    /** Generous: one soffice batch plus a normalise per card. */
    public int $timeout = 900;

    private const DISK = 'linode';

    public function __construct(public readonly string $cardPrintRunId) {}

    public function handle(
        DocumentMergeService $merger,
        PdfConverter $converter,
        PdfNormalizer $normalizer,
        CardImposer $imposer,
        FileClassDocument $filer,
        RichTextExpander $expander,
    ): void {
        $run = CardPrintRun::query()
            ->withoutGlobalScope('organization')
            ->with([
                'topic.trainingClass',
                'topic.training.cardFields',
                'topic.cardValues',
                'template',
                'stock',
            ])
            ->find($this->cardPrintRunId);

        if ($run === null) {
            return; // deleted while queued
        }

        $run->update(['status' => 'processing']);

        $workDir = null;

        try {
            $topic = $run->topic;
            $class = $topic?->trainingClass;
            $template = $run->template;
            $stock = $run->stock;

            if ($topic === null || $class === null) {
                throw new \RuntimeException('The class this run belongs to no longer exists.');
            }

            if ($template === null) {
                throw new \RuntimeException('The card design for this run is no longer available.');
            }

            if ($stock === null) {
                throw new \RuntimeException('The card stock for this run is no longer available.');
            }

            $rows = CardMergeData::forTopic($topic);

            if ($rows === []) {
                // Not a crash — a real situation the requester needs told.
                throw new \RuntimeException(
                    "Nobody on this class holds a certificate for “{$topic->training_name}”, so there are no cards to print.",
                );
            }

            // A proof run (C6b) is the identical pipeline sliced to the first
            // card — same design, same fonts, same start cell — so what comes
            // out of the printer is exactly what a full run would put in that
            // cell, at the cost of one cell instead of a misaligned batch.
            // After the empty check on purpose: a proof of nobody is still
            // "nobody", not a blank success.
            if ($run->proof) {
                $rows = array_slice($rows, 0, 1);
            }

            $workDir = sys_get_temp_dir().'/cards_'.$run->id;

            if (! is_dir($workDir)) {
                mkdir($workDir, 0700, true);
            }

            // 1. design file: linode -> local
            $source = "{$workDir}/template.{$template->extension}";
            file_put_contents($source, Storage::disk(self::DISK)->get($template->path));

            // 2. translate ${key} -> TBS once, then merge per person. Card
            //    fields are plain values — no list or multiline types, so no
            //    block plumbing (that's the documents module's problem).
            $translated = (new TemplateTranslator)->translateFile(
                $source,
                "{$workDir}/translated.{$template->extension}",
            );

            $mergedPaths = [];

            foreach ($rows as $i => $row) {
                $mergedPaths[] = $merger->merge(
                    $translated,
                    $row,
                    sprintf('%s/card_%04d.%s', $workDir, $i, $template->extension),
                );
            }

            // 3. turn any formatted value into real runs (C5). After the
            //    merge, because only now is the author's own run visible to
            //    clone; before the conversion, because LibreOffice is what
            //    renders the result. Skipped outright when nothing was
            //    marked, which is most cards — the gate is the merged data
            //    rather than the field definitions, so a formatted field
            //    left blank on this class costs nothing either.
            if ($this->hasMarkedValues($rows)) {
                foreach ($mergedPaths as $path) {
                    $expander->expand($path, $template->extension);
                }
            }

            // 4. one soffice run for the batch, then normalise each for FPDI
            $converted = $converter->toPdfBatch($mergedPaths, $workDir);

            $normalized = [];

            foreach ($converted as $i => $pdf) {
                $normalized[] = $normalizer->normalize($pdf, sprintf('%s/norm_%04d.pdf', $workDir, $i));
            }

            // 5. impose. The plan owns every placement decision.
            $plan = new CardSheetPlan(
                $stock,
                (float) $template->card_width,
                (float) $template->card_height,
            );

            $frontPages = $plan->fronts(count($rows), $run->start_cell);
            $frontPath = $imposer->impose(
                array_map(fn (string $p) => ['path' => $p, 'page' => 1], $normalized),
                $frontPages,
                $stock,
                "{$workDir}/fronts.pdf",
            );

            // Backs only when the design actually has a second slide — asking
            // for them on a single-sided card is a no-op, not an error.
            $wantsBacks = $run->include_backs && $template->hasBack();
            $backPath = $wantsBacks
                ? $imposer->impose(
                    array_map(fn (string $p) => ['path' => $p, 'page' => 2], $normalized),
                    $plan->backs(count($rows), $run->start_cell),
                    $stock,
                    "{$workDir}/backs.pdf",
                )
                : null;

            // 6. file both under one stamp
            $stamp = Carbon::now(config('app.display_timezone'))->format('Ymd_Hi');
            $topicName = (string) $topic->training_name;

            $frontAttachment = $filer->fromPath(
                $class,
                $frontPath,
                FileClassDocument::filename($class, 'Cards_Front', $stamp),
                'cards',
                "{$topicName} — card fronts",
                $run->requested_by,
            );

            $backAttachment = $backPath === null ? null : $filer->fromPath(
                $class,
                $backPath,
                FileClassDocument::filename($class, 'Cards_Back', $stamp),
                'cards',
                "{$topicName} — card backs",
                $run->requested_by,
            );

            $run->update([
                'status' => 'done',
                'error' => null,
                'card_count' => count($rows),
                'sheet_count' => count($frontPages),
                'front_path' => $frontAttachment->path,
                'back_path' => $backAttachment?->path,
                'run_stamp' => $stamp,
                'template_version' => $template->version,
            ]);
        } catch (\Throwable $e) {
            Log::error('Card sheet generation failed', [
                'card_print_run_id' => $run->id,
                'error' => $e->getMessage(),
            ]);
            $run->update(['status' => 'failed', 'error' => $e->getMessage()]);
        } finally {
            $this->cleanUp($workDir);
        }

        // The class detail refetches on this, which picks up both the filed
        // documents and the run's outcome.
        $run->refresh();

        if ($run->trainingClass !== null) {
            event(new ClassChanged($run->class_id, $run->org_id, 'updated'));
        }
    }

    /**
     * Is there any formatted value in this run at all?
     *
     * Asked of the merged data rather than the field definitions: a training
     * can define a formatted field that this class left blank, and that costs
     * nothing to print, so it should cost nothing to check either.
     *
     * @param  list<array<string, string>>  $rows
     */
    private function hasMarkedValues(array $rows): bool
    {
        foreach ($rows as $row) {
            foreach ($row as $value) {
                if (str_contains($value, RichTextMarkup::OPEN)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function cleanUp(?string $workDir): void
    {
        if ($workDir === null || ! is_dir($workDir)) {
            return;
        }

        foreach (glob("{$workDir}/*") ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($workDir);
    }
}
