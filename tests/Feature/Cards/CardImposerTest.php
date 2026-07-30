<?php

namespace Tests\Feature\Cards;

use App\Models\CardStock;
use App\Support\Cards\CardImposer;
use App\Support\Cards\CardSheetPlan;
use setasign\Fpdi\Fpdi;
use Tests\TestCase;

/**
 * Stamping merged card pages onto sheets of stock (custom-certs C4c).
 *
 * The imposer is deliberately dumb: {@see CardSheetPlan} decides sheets, cells
 * and points (and is tested exhaustively for it), so what's verified here is
 * the structure the PDF comes out with — page count, page size, and that a
 * card page is placed once per planned position. "Never scaled" is structural
 * rather than asserted: the imposer never passes a width or height to
 * useTemplate, so FPDI has nothing to scale by.
 */
class CardImposerTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/imposer_'.uniqid();
        mkdir($this->dir, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob("{$this->dir}/*") ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);

        parent::tearDown();
    }

    private function stock(array $overrides = []): CardStock
    {
        return new CardStock(array_merge([
            'page_width' => 612.0,
            'page_height' => 792.0,
            'column_count' => 2,
            'row_count' => 5,
            'card_width' => 243.0,
            'card_height' => 153.0,
            'margin_left' => 63.0,
            'margin_top' => 27.0,
            'gutter_x' => 0.0,
            'gutter_y' => 0.0,
            'duplex_flip' => 'long_edge',
        ], $overrides));
    }

    /**
     * A stand-in for a merged, converted card: a card-sized PDF with $pages
     * pages (1 = front only, 2 = front and back). FPDF writes PDF 1.3, which
     * is exactly what FPDI's parser reads — so this covers the imposer without
     * needing soffice or qpdf.
     */
    private function cardPdf(string $name, int $pages = 1): string
    {
        $pdf = new Fpdi('P', 'pt', [243.0, 153.0]);

        for ($i = 1; $i <= $pages; $i++) {
            $pdf->AddPage();
            $pdf->SetFont('Helvetica', '', 10);
            $pdf->Text(10, 20, "{$name} page {$i}");
        }

        $path = "{$this->dir}/{$name}.pdf";
        $pdf->Output('F', $path);

        return $path;
    }

    /** @return list<array{path:string,page:int}> */
    private function cards(int $count, int $pagesEach = 1, int $page = 1): array
    {
        $cards = [];

        for ($i = 0; $i < $count; $i++) {
            $cards[] = ['path' => $this->cardPdf("card{$i}", $pagesEach), 'page' => $page];
        }

        return $cards;
    }

    /** Page count + size in POINTS of a produced PDF, read back through FPDI. */
    private function inspect(string $path): array
    {
        // 'pt' explicitly: FPDI's default unit is mm, which would report a
        // correct 612x792pt sheet as 215.9x279.4 and look like a bug.
        $reader = new Fpdi('P', 'pt');
        $count = $reader->setSourceFile($path);
        $size = $reader->getTemplateSize($reader->importPage(1));

        return [$count, round($size['width'], 2), round($size['height'], 2)];
    }

    public function test_a_short_run_lands_on_one_sheet(): void
    {
        $plan = new CardSheetPlan($this->stock(), 243.0, 153.0);
        $out = "{$this->dir}/fronts.pdf";

        app(CardImposer::class)->impose(
            $this->cards(3),
            $plan->fronts(3),
            $this->stock(),
            $out,
        );

        $this->assertSame([1, 612.0, 792.0], $this->inspect($out));
    }

    public function test_a_long_run_makes_one_page_per_sheet(): void
    {
        $plan = new CardSheetPlan($this->stock(), 243.0, 153.0);
        $out = "{$this->dir}/fronts.pdf";

        app(CardImposer::class)->impose(
            $this->cards(23),
            $plan->fronts(23),
            $this->stock(),
            $out,
        );

        [$pages] = $this->inspect($out);
        $this->assertSame(3, $pages);
    }

    public function test_landscape_stock_keeps_its_orientation(): void
    {
        // FPDF normalises a size array to portrait and then swaps for a
        // landscape orientation flag — so passing 'P' for a wide sheet would
        // silently produce a tall one, and every card would land off-page.
        $stock = $this->stock([
            'page_width' => 792.0,
            'page_height' => 612.0,
            'column_count' => 3,
            'row_count' => 3,
        ]);
        $plan = new CardSheetPlan($stock, 243.0, 153.0);
        $out = "{$this->dir}/landscape.pdf";

        app(CardImposer::class)->impose($this->cards(2), $plan->fronts(2), $stock, $out);

        $this->assertSame([1, 792.0, 612.0], $this->inspect($out));
    }

    public function test_the_backs_of_a_two_sided_card_impose_from_page_two(): void
    {
        // A 2-slide template converts to a 2-page PDF per person: page 1 the
        // front, page 2 the back. The backs run reads the same files.
        $stock = $this->stock();
        $plan = new CardSheetPlan($stock, 243.0, 153.0);
        $cards = $this->cards(4, pagesEach: 2, page: 2);
        $out = "{$this->dir}/backs.pdf";

        app(CardImposer::class)->impose($cards, $plan->backs(4), $stock, $out);

        $this->assertSame([1, 612.0, 792.0], $this->inspect($out));
    }

    public function test_an_empty_plan_produces_nothing(): void
    {
        // Guarded because FPDF throws on a document with no pages, and "no
        // cards" is a user situation (nobody passed), not a crash.
        $out = "{$this->dir}/empty.pdf";

        $written = app(CardImposer::class)->impose([], [], $this->stock(), $out);

        $this->assertNull($written);
        $this->assertFileDoesNotExist($out);
    }

    public function test_a_plan_referring_to_a_missing_card_is_rejected(): void
    {
        // Rather than silently printing a sheet with a hole in it.
        $stock = $this->stock();
        $plan = new CardSheetPlan($stock, 243.0, 153.0);

        $this->expectException(\InvalidArgumentException::class);

        app(CardImposer::class)->impose(
            $this->cards(1),
            $plan->fronts(3),
            $stock,
            "{$this->dir}/short.pdf",
        );
    }

    public function test_each_source_card_is_parsed_once_however_often_it_is_placed(): void
    {
        // Not a micro-optimisation: a 200-card run would otherwise re-parse
        // and re-import the same files on every placement.
        $stock = $this->stock();
        $plan = new CardSheetPlan($stock, 243.0, 153.0);
        $path = $this->cardPdf('shared');
        $cards = array_fill(0, 6, ['path' => $path, 'page' => 1]);
        $out = "{$this->dir}/shared.pdf";

        $imposer = app(CardImposer::class);
        $imposer->impose($cards, $plan->fronts(6), $stock, $out);

        $this->assertSame(1, $imposer->sourcesParsed());
        $this->assertSame([1, 612.0, 792.0], $this->inspect($out));
    }
}
