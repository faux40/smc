<?php

namespace App\Support\Cards;

use App\Models\CardStock;
use setasign\Fpdi\Fpdi;

/**
 * Stamps merged card pages onto sheets of purchased stock (custom-certs C4c).
 *
 * Deliberately dumb: {@see CardSheetPlan} has already decided every sheet,
 * cell and point, so this only places what it's told. It never passes a width
 * or height to `useTemplate`, which is what structurally guarantees the "never
 * scale" rule — there is no scale factor to get wrong.
 *
 * Input pages must be FPDI-readable (PDF ≤1.4). soffice emits 1.6/1.7, so the
 * job runs its output through {@see PdfNormalizer} first.
 */
class CardImposer
{
    /** Distinct source files parsed on the last run (see sourcesParsed()). */
    private int $sourcesParsed = 0;

    /**
     * @param  list<array{path:string,page:int}>  $cards  one entry per card, in card order
     * @param  list<list<array{card:int,cell:int,x:float,y:float}>>  $pages  from CardSheetPlan
     * @return string|null the written path, or null when there was nothing to place
     */
    public function impose(array $cards, array $pages, CardStock $stock, string $outputPath): ?string
    {
        $this->sourcesParsed = 0;

        if ($pages === [] || $cards === []) {
            // "Nobody earned a card" is a user situation, not a crash — and
            // FPDF refuses to write a document with no pages.
            return null;
        }

        $pdf = $this->document($stock);
        $templates = [];

        foreach ($pages as $placements) {
            $pdf->AddPage();

            foreach ($placements as $placement) {
                $card = $cards[$placement['card']] ?? null;

                if ($card === null) {
                    throw new \InvalidArgumentException(
                        "The plan places card {$placement['card']}, which wasn't supplied.",
                    );
                }

                $key = $card['path'].':'.$card['page'];

                // Import each source page once: a 200-card run would otherwise
                // re-parse the same files on every placement.
                if (! isset($templates[$key])) {
                    $pdf->setSourceFile($card['path']);
                    $templates[$key] = $pdf->importPage($card['page']);
                    $this->sourcesParsed++;
                }

                // x/y only — supplying no size is what keeps the card at 100%.
                $pdf->useTemplate($templates[$key], $placement['x'], $placement['y']);
            }
        }

        $pdf->Output('F', $outputPath);

        return $outputPath;
    }

    /**
     * Distinct source pages imported during the last impose(), so the caching
     * above is observable rather than a claim in a comment.
     */
    public function sourcesParsed(): int
    {
        return $this->sourcesParsed;
    }

    /**
     * A document the exact size of the stock.
     *
     * The orientation flag matters: FPDF normalises a size array to portrait
     * and then swaps it back only for a landscape flag, so passing 'P' for a
     * wide sheet would silently produce a tall one and every card would land
     * off the page.
     */
    private function document(CardStock $stock): Fpdi
    {
        $width = (float) $stock->page_width;
        $height = (float) $stock->page_height;

        return new Fpdi(
            $width > $height ? 'L' : 'P',
            'pt',
            [$width, $height],
        );
    }
}
