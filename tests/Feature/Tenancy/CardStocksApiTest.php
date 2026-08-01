<?php

namespace Tests\Feature\Tenancy;

use App\Models\CardStock;
use App\Models\Organization;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CardStocksApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    /** A valid 10-up wallet sheet payload, in points. */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Wallet cards 10-up',
            'page_width' => 612,
            'page_height' => 792,
            'column_count' => 2,
            'row_count' => 5,
            'card_width' => 243,
            'card_height' => 153,
            'margin_top' => 27,
            'margin_left' => 63,
            'gutter_x' => 0,
            'gutter_y' => 0,
        ], $overrides);
    }

    // ---- index / visibility -------------------------------------------

    public function test_manager_lists_system_plus_own_org_stocks(): void
    {
        $org = Organization::factory()->create();
        $other = Organization::factory()->create();
        $manager = User::factory()->for($org, 'organization')->withRole('Manager')->create();

        CardStock::factory()->system()->create(['name' => 'Avery 5371']);
        CardStock::factory()->for($org, 'organization')->create(['name' => 'Our badge stock']);
        CardStock::factory()->for($other, 'organization')->create(['name' => 'Foreign stock']);

        $rows = $this->actingAs($manager)
            ->getJson('/api/card-stocks')
            ->assertOk()
            ->assertJsonCount(2)
            ->json();

        $byName = collect($rows)->keyBy('name');
        $this->assertTrue($byName->has('Avery 5371'));
        $this->assertTrue($byName->has('Our badge stock'));
        $this->assertFalse($byName->has('Foreign stock'));

        // System stock is listed but not editable from the org UI.
        $this->assertTrue($byName['Avery 5371']['is_system']);
        $this->assertFalse($byName['Avery 5371']['can_edit']);
        $this->assertFalse($byName['Our badge stock']['is_system']);
    }

    public function test_index_reports_the_cards_per_sheet(): void
    {
        // Derived, never stored — the client shows "10 per sheet" without
        // duplicating the grid maths.
        $org = Organization::factory()->create();
        $manager = User::factory()->for($org, 'organization')->withRole('Manager')->create();
        CardStock::factory()->for($org, 'organization')->create([
            'column_count' => 2, 'row_count' => 5,
        ]);

        $this->actingAs($manager)
            ->getJson('/api/card-stocks')
            ->assertOk()
            ->assertJsonPath('0.per_sheet', 10);
    }

    // ---- create --------------------------------------------------------

    public function test_admin_creates_a_stock_in_their_own_org(): void
    {
        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();

        $this->actingAs($admin)
            ->postJson('/api/card-stocks', $this->payload())
            ->assertCreated()
            ->assertJsonPath('name', 'Wallet cards 10-up')
            ->assertJsonPath('per_sheet', 10)
            ->assertJsonPath('is_system', false);

        $this->assertDatabaseHas('card_stocks', [
            'org_id' => $org->id,
            'name' => 'Wallet cards 10-up',
            'column_count' => 2,
        ]);
    }

    public function test_a_manager_cannot_define_a_stock(): void
    {
        // Managers pick a stock when printing; Admins define them.
        $org = Organization::factory()->create();
        $manager = User::factory()->for($org, 'organization')->withRole('Manager')->create();

        $this->actingAs($manager)
            ->postJson('/api/card-stocks', $this->payload())
            ->assertForbidden();
    }

    public function test_calibration_offsets_are_stored_negatives_included(): void
    {
        // C6a: the whole-sheet printer nudge. Negative is half the point —
        // a printer drifts up and left as easily as down and right.
        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();

        $this->actingAs($admin)
            ->postJson('/api/card-stocks', $this->payload([
                'offset_x' => 4.5,
                'offset_y' => -3.5,
            ]))
            ->assertCreated()
            ->assertJsonPath('offset_x', 4.5)
            ->assertJsonPath('offset_y', -3.5);

        $stock = CardStock::first();
        $this->assertSame(4.5, $stock->offset_x);
        $this->assertSame(-3.5, $stock->offset_y);
    }

    public function test_offsets_default_to_zero_when_not_sent(): void
    {
        // Older clients and simple scripts shouldn't have to know
        // calibration exists.
        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();

        $this->actingAs($admin)
            ->postJson('/api/card-stocks', $this->payload())
            ->assertCreated();

        $this->assertSame(0.0, CardStock::first()->offset_x);
    }

    public function test_an_offset_beyond_an_inch_is_rejected_outright(): void
    {
        /*
         * ±72pt (1in) is far beyond any real printer's drift. Past that the
         * number is almost certainly a margin typed into the wrong box, and
         * "your offset is implausible" beats a confusing overflow message —
         * or worse, a plausible-looking grid shifted a full inch.
         */
        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();

        $this->actingAs($admin)
            ->postJson('/api/card-stocks', $this->payload(['offset_x' => 73]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['offset_x']);
    }

    public function test_an_offset_that_shoves_the_grid_off_the_page_is_rejected(): void
    {
        // The 10-up grid ends flush with the page bottom, so any downward
        // nudge clips the last row. The error lands on the offset, not on
        // row_count — the grid was fine, the nudge is the culprit.
        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();

        $this->actingAs($admin)
            ->postJson('/api/card-stocks', $this->payload(['offset_y' => 3]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['offset_y']);
    }

    public function test_a_negative_offset_past_the_margin_is_rejected(): void
    {
        // Left margin is 63pt; pulling the grid 64pt left clips column 0.
        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();

        $this->actingAs($admin)
            ->postJson('/api/card-stocks', $this->payload(['offset_x' => -64]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['offset_x']);
    }

    public function test_the_index_serves_the_offsets(): void
    {
        // The client mirror and the sheet preview need them; a row without
        // offsets would render the uncalibrated grid.
        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();
        CardStock::factory()->for($org, 'organization')->create([
            'offset_x' => 4.5, 'offset_y' => -3.5,
        ]);

        $this->actingAs($admin)
            ->getJson('/api/card-stocks')
            ->assertOk()
            ->assertJsonPath('0.offset_x', 4.5)
            ->assertJsonPath('0.offset_y', -3.5);
    }

    // ---- validation ----------------------------------------------------

    public function test_the_grid_must_fit_on_the_page(): void
    {
        // The whole point of the stock: three 3.375in columns cannot fit an
        // 8.5in page, and a silent overflow would print cards off the sheet.
        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();

        $this->actingAs($admin)
            ->postJson('/api/card-stocks', $this->payload(['column_count' => 3]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('column_count');
    }

    public function test_a_grid_taller_than_the_page_is_rejected(): void
    {
        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();

        $this->actingAs($admin)
            ->postJson('/api/card-stocks', $this->payload(['row_count' => 6]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('row_count');
    }

    public function test_gutters_count_toward_the_fit(): void
    {
        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();

        $this->actingAs($admin)
            ->postJson('/api/card-stocks', $this->payload(['gutter_y' => 72]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('row_count');
    }

    public function test_dimensions_must_be_positive_and_counts_at_least_one(): void
    {
        // Postgres has no unsigned smallint, so the floor lives here.
        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();

        $this->actingAs($admin)
            ->postJson('/api/card-stocks', $this->payload([
                'column_count' => 0,
                'card_width' => 0,
                'page_width' => -10,
                'gutter_x' => -1,
                'name' => '',
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'column_count', 'card_width', 'page_width', 'gutter_x', 'name',
            ]);
    }

    public function test_duplex_flip_is_limited_to_the_known_bindings(): void
    {
        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();

        $this->actingAs($admin)
            ->postJson('/api/card-stocks', $this->payload(['duplex_flip' => 'sideways']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('duplex_flip');

        $this->actingAs($admin)
            ->postJson('/api/card-stocks', $this->payload(['duplex_flip' => 'long_edge']))
            ->assertCreated();
    }

    // ---- update / delete ------------------------------------------------

    public function test_admin_updates_their_own_stock(): void
    {
        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();
        $stock = CardStock::factory()->for($org, 'organization')->create();

        $this->actingAs($admin)
            // 63 + 2x243 + 9 = 558pt, still inside the 612pt page.
            ->patchJson("/api/card-stocks/{$stock->id}", $this->payload([
                'name' => 'Retuned stock',
                'gutter_x' => 9,
            ]))
            ->assertOk()
            ->assertJsonPath('name', 'Retuned stock')
            ->assertJsonPath('gutter_x', 9);
    }

    public function test_a_system_stock_cannot_be_edited_or_deleted(): void
    {
        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();
        $system = CardStock::factory()->system()->create();

        $this->actingAs($admin)
            ->patchJson("/api/card-stocks/{$system->id}", $this->payload())
            ->assertForbidden();

        $this->actingAs($admin)
            ->deleteJson("/api/card-stocks/{$system->id}")
            ->assertForbidden();
    }

    public function test_another_orgs_stock_is_not_found(): void
    {
        $org = Organization::factory()->create();
        $other = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();
        $foreign = CardStock::factory()->for($other, 'organization')->create();

        $this->actingAs($admin)
            ->patchJson("/api/card-stocks/{$foreign->id}", $this->payload())
            ->assertNotFound();
    }

    public function test_delete_soft_deletes_the_stock(): void
    {
        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();
        $stock = CardStock::factory()->for($org, 'organization')->create();

        $this->actingAs($admin)
            ->deleteJson("/api/card-stocks/{$stock->id}")
            ->assertOk();

        $this->assertSoftDeleted('card_stocks', ['id' => $stock->id]);
    }
}
