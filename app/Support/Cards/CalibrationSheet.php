<?php

namespace App\Support\Cards;

use App\Models\CardStock;
use setasign\Fpdi\Fpdi;

/**
 * The printable calibration sheet for a card stock (custom-certs C6a).
 *
 * Printed on plain paper at 100% and held against a sheet of the actual
 * stock (or sacrificially printed onto one): the outlines should sit exactly
 * on the card edges, and however far they miss is the printer's drift — the
 * number to type into the stock's offset fields. Regenerating after entering
 * offsets draws the corrected grid, so a second print verifies the fix.
 *
 * A duplex stock gets a second page for the back pass. The cell POSITIONS are
 * identical — mirroring changes which card lands in a cell, never where the
 * cells are — but each cell is labelled with the card whose back would land
 * there, so the operator can also confirm the flip direction is right. Drift
 * is measured per pass; matching passes confirm the one-offset-pair model,
 * differing ones are the evidence for per-pass offsets.
 *
 * Same drawing stack as {@see CardImposer} (FPDF via Fpdi), points
 * throughout, origin top-left.
 */
class CalibrationSheet
{
    /** How far the corner ticks extend beyond each cell, in points. */
    private const TICK = 9.0;

    /** Length of the rulers, in millimetres. */
    private const RULER_MM = 40;

    private const PT_PER_MM = 72 / 25.4;

    private readonly CardSheetGeometry $geometry;

    public function __construct(private readonly CardStock $stock)
    {
        $this->geometry = new CardSheetGeometry($stock);
    }

    /** Write the sheet to $outputPath and return it. */
    public function render(string $outputPath): string
    {
        $pdf = $this->document();

        $this->page($pdf, 'Front pass', $this->frontLabels());

        if ($this->stock->duplex_flip !== null) {
            $this->page(
                $pdf,
                sprintf('Back pass — flip on the %s edge', str_replace('_', ' ', (string) $this->stock->duplex_flip)),
                $this->backLabels(),
            );
        }

        $pdf->Output('F', $outputPath);

        return $outputPath;
    }

    // ---- pages ---------------------------------------------------------

    /**
     * @param  array<int, string>  $labels  text per cell index
     */
    private function page(Fpdi $pdf, string $passTitle, array $labels): void
    {
        $pdf->AddPage();
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetDrawColor(0, 0, 0);

        $this->header($pdf, $passTitle);
        $this->rulers($pdf);

        for ($i = 0; $i < $this->geometry->perSheet(); $i++) {
            $rect = $this->geometry->cellRect($i);

            $this->cellOutline($pdf, $rect);
            $this->cellLabel($pdf, $rect, $labels[$i] ?? '');
        }
    }

    private function header(Fpdi $pdf, string $passTitle): void
    {
        // x = 120: clear of the top ruler's 40mm (~113pt) of ticks.
        $pdf->SetFont('Helvetica', 'B', 9);
        $pdf->SetXY(120, 4);
        $pdf->Cell(0, 10, $this->text(sprintf('%s — calibration — %s', $this->stock->name, $passTitle)));

        // Two short lines rather than one long one: a single Cell doesn't
        // wrap, and the tail of the offsets sentence fell off the page.
        $pdf->SetFont('Helvetica', '', 7);
        $pdf->SetXY(120, 12);
        $pdf->Cell(0, 8, $this->text(
            'Print at 100% (actual size). Outlines should sit on the card edges; measure any miss in mm.',
        ));
        $pdf->SetXY(120, 19);
        $pdf->Cell(0, 8, $this->text($this->offsetsLine()));
    }

    /**
     * FPDF's core fonts speak cp1252, not UTF-8 — fed UTF-8, an em-dash
     * prints as mojibake. The stock name is user data, so this is a real
     * conversion with a translit fallback, not a hunt for ASCII literals.
     */
    private function text(string $utf8): string
    {
        return iconv('UTF-8', 'windows-1252//TRANSLIT//IGNORE', $utf8) ?: $utf8;
    }

    /**
     * What the drawn grid already includes — so a sheet printed after
     * calibrating reads as "verify", not "measure again from zero".
     */
    private function offsetsLine(): string
    {
        $x = (float) ($this->stock->offset_x ?? 0);
        $y = (float) ($this->stock->offset_y ?? 0);

        if ($x === 0.0 && $y === 0.0) {
            return 'No offsets applied yet: enter what you measure. Positive X moves right, positive Y moves down.';
        }

        return sprintf(
            'Offsets already applied: %+.1fmm X, %+.1fmm Y. A remaining miss is what still needs adding.',
            $x / self::PT_PER_MM,
            $y / self::PT_PER_MM,
        );
    }

    // ---- cell drawing --------------------------------------------------

    /**
     * @param  array{x: float, y: float, width: float, height: float}  $rect
     */
    private function cellOutline(Fpdi $pdf, array $rect): void
    {
        $pdf->SetLineWidth(0.25);
        $pdf->Rect($rect['x'], $rect['y'], $rect['width'], $rect['height']);

        // Ticks extending outward from each corner: visible even when the
        // outline lands exactly under the card's edge, which is the goal.
        foreach ([
            [$rect['x'], $rect['y'], -1, -1],
            [$rect['x'] + $rect['width'], $rect['y'], 1, -1],
            [$rect['x'], $rect['y'] + $rect['height'], -1, 1],
            [$rect['x'] + $rect['width'], $rect['y'] + $rect['height'], 1, 1],
        ] as [$x, $y, $dx, $dy]) {
            $pdf->Line($x, $y, $x + $dx * self::TICK, $y);
            $pdf->Line($x, $y, $x, $y + $dy * self::TICK);
        }
    }

    /**
     * @param  array{x: float, y: float, width: float, height: float}  $rect
     */
    private function cellLabel(Fpdi $pdf, array $rect, string $label): void
    {
        $pdf->SetFont('Helvetica', '', 7);
        $pdf->SetXY($rect['x'] + 3, $rect['y'] + 2);
        $pdf->Cell($rect['width'] - 6, 8, $this->text($label));
    }

    /** @return array<int, string> */
    private function frontLabels(): array
    {
        $labels = [];

        for ($i = 0; $i < $this->geometry->perSheet(); $i++) {
            $labels[$i] = sprintf('Cell %d', $i + 1);
        }

        return $labels;
    }

    /**
     * The back page names the card whose BACK lands in each cell, from the
     * same plan the real run uses — printing this page and reading the
     * numbers is how the operator confirms the flip setting matches how
     * they actually reload the stack.
     *
     * @return array<int, string>
     */
    private function backLabels(): array
    {
        $plan = new CardSheetPlan(
            $this->stock,
            (float) $this->stock->card_width,
            (float) $this->stock->card_height,
        );

        $labels = [];

        foreach ($plan->backs($this->geometry->perSheet())[0] ?? [] as $placement) {
            $labels[$placement['cell']] = sprintf('Back of card %d', $placement['card'] + 1);
        }

        return $labels;
    }

    // ---- rulers --------------------------------------------------------

    /**
     * Millimetre scales along the top and left page edges, so the drift can
     * be read straight off the print without hunting for a ruler that
     * matches. Anchored at the page corner: the paper edge is the one line
     * the printer cannot move.
     */
    private function rulers(Fpdi $pdf): void
    {
        $pdf->SetLineWidth(0.25);
        $pdf->SetFont('Helvetica', '', 5);

        for ($mm = 0; $mm <= self::RULER_MM; $mm++) {
            $at = $mm * self::PT_PER_MM;
            $long = $mm % 10 === 0 ? 10.0 : ($mm % 5 === 0 ? 7.0 : 4.0);

            // Top edge, ticking downward…
            $pdf->Line($at, 0, $at, $long);
            // …and left edge, ticking rightward.
            $pdf->Line(0, $at, $long, $at);

            if ($mm !== 0 && $mm % 10 === 0) {
                $pdf->SetXY($at - 3, 10.5);
                $pdf->Cell(6, 5, (string) $mm, 0, 0, 'C');
                $pdf->SetXY(10.5, $at - 2.5);
                $pdf->Cell(6, 5, (string) $mm);
            }
        }
    }

    private function document(): Fpdi
    {
        $width = (float) $this->stock->page_width;
        $height = (float) $this->stock->page_height;

        // Orientation flag as in CardImposer: FPDF normalises the size array
        // to portrait and only swaps back for 'L', so 'P' on a wide sheet
        // would silently rotate the whole grid.
        $pdf = new Fpdi(
            $width > $height ? 'L' : 'P',
            'pt',
            [$width, $height],
        );

        $pdf->SetAutoPageBreak(false);
        $pdf->SetTitle('Calibration — '.$this->stock->name);

        return $pdf;
    }
}
