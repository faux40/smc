<?php

namespace Tests\Feature\Cards;

use App\Models\CardStock;
use App\Models\Organization;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use setasign\Fpdi\Fpdi;
use Tests\TestCase;

/**
 * The printable calibration sheet for a card stock (custom-certs C6a): the
 * grid's cell outlines and measuring marks, printed on plain paper and held
 * against the stock to read off how far a printer drifts — the number that
 * goes into the stock's offset fields.
 */
class CardCalibrationSheetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private function download(User $actor, CardStock $stock): string
    {
        $response = $this->actingAs($actor)
            ->get("/api/card-stocks/{$stock->id}/calibration-sheet")
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        return $response->streamedContent();
    }

    /** Page count + size of a generated sheet, via the same reader the imposer feeds. */
    private function inspect(string $pdf): array
    {
        $local = tempnam(sys_get_temp_dir(), 'cal').'.pdf';
        file_put_contents($local, $pdf);

        $reader = new Fpdi('P', 'pt');
        $pages = $reader->setSourceFile($local);
        $size = $reader->getTemplateSize($reader->importPage(1));
        @unlink($local);

        return [$pages, round($size['width']), round($size['height'])];
    }

    public function test_a_manager_downloads_a_sheet_the_size_of_the_stock(): void
    {
        // Managers print the cards, so they print the calibration sheet;
        // the page must be the stock's own size or the marks mean nothing.
        $org = Organization::factory()->create();
        $manager = User::factory()->for($org, 'organization')->withRole('Manager')->create();
        $stock = CardStock::factory()->for($org, 'organization')->create([
            'page_width' => 612, 'page_height' => 792,
            'duplex_flip' => null,
        ]);

        [$pages, $width, $height] = $this->inspect($this->download($manager, $stock));

        $this->assertSame(1, $pages);
        $this->assertSame(612.0, $width);
        $this->assertSame(792.0, $height);
    }

    public function test_a_duplex_stock_gets_a_second_page_for_the_back_pass(): void
    {
        /*
         * Drift is per pass through the printer, and the one-offset-pair
         * model is a bet that both passes drift alike — the back page is
         * what turns that bet into a measurement on the actual printer.
         */
        $org = Organization::factory()->create();
        $manager = User::factory()->for($org, 'organization')->withRole('Manager')->create();
        $stock = CardStock::factory()->for($org, 'organization')->create([
            'duplex_flip' => 'long_edge',
        ]);

        [$pages] = $this->inspect($this->download($manager, $stock));

        $this->assertSame(2, $pages);
    }

    public function test_a_system_stock_has_a_sheet_too(): void
    {
        // Built-in stocks are exactly what most orgs print on. (Storing the
        // measured offsets still needs an org-owned copy — the sheet itself
        // must not.)
        $org = Organization::factory()->create();
        $manager = User::factory()->for($org, 'organization')->withRole('Manager')->create();
        $stock = CardStock::factory()->system()->create();

        $this->download($manager, $stock);
    }

    public function test_a_foreign_orgs_stock_is_not_found(): void
    {
        $org = Organization::factory()->create();
        $other = Organization::factory()->create();
        $manager = User::factory()->for($org, 'organization')->withRole('Manager')->create();
        $foreign = CardStock::factory()->for($other, 'organization')->create();

        $this->actingAs($manager)
            ->get("/api/card-stocks/{$foreign->id}/calibration-sheet")
            ->assertNotFound();
    }

    public function test_below_manager_is_refused(): void
    {
        $org = Organization::factory()->create();
        $viewer = User::factory()->for($org, 'organization')->withRole('SelfView')->create();
        $stock = CardStock::factory()->for($org, 'organization')->create();

        $this->actingAs($viewer)
            ->get("/api/card-stocks/{$stock->id}/calibration-sheet")
            ->assertForbidden();
    }
}
