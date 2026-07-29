<?php

namespace Tests\Feature\Tenancy;

use App\Models\CardStock;
use App\Models\Organization;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Card stock = the printable geometry of a purchased card sheet. Two scopes
 * share one table exactly like merge_fields: system stocks (org_id NULL —
 * the common Avery-style layouts, console/seeder managed) and an org's own.
 */
class CardStockModelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_card_stocks_table_shape(): void
    {
        $this->assertTrue(Schema::hasColumns('card_stocks', [
            'id', 'org_id', 'name',
            'page_width', 'page_height',
            'column_count', 'row_count',
            'card_width', 'card_height',
            'margin_top', 'margin_left',
            'gutter_x', 'gutter_y',
            'offset_x', 'offset_y',
            'duplex_flip', 'notes',
            'created_at', 'updated_at', 'deleted_at',
        ]));

        $org = Organization::factory()->create();
        $stock = CardStock::factory()->for($org, 'organization')->create();

        $this->assertSame($org->id, $stock->org_id);
        $this->assertFalse($stock->isSystem());
    }

    public function test_measurements_come_back_as_floats_not_decimal_strings(): void
    {
        // The geometry helper does arithmetic on these; a decimal cast would
        // hand back strings and silently stringify the maths.
        $org = Organization::factory()->create();
        $stock = CardStock::factory()->for($org, 'organization')->create([
            'page_width' => 612, 'card_width' => 243.5, 'column_count' => 2,
        ]);

        $fresh = $stock->fresh();
        $this->assertIsFloat($fresh->page_width);
        $this->assertSame(243.5, $fresh->card_width);
        $this->assertIsInt($fresh->column_count);
    }

    public function test_calibration_offsets_default_to_zero(): void
    {
        // Reserved for the precision pass; every stock starts un-nudged.
        $org = Organization::factory()->create();
        $stock = CardStock::factory()->for($org, 'organization')->create();

        $this->assertSame(0.0, $stock->fresh()->offset_x);
        $this->assertSame(0.0, $stock->fresh()->offset_y);
    }

    public function test_visible_to_returns_system_stocks_and_the_orgs_own(): void
    {
        $org = Organization::factory()->create();
        $other = Organization::factory()->create();

        $system = CardStock::factory()->system()->create(['name' => 'Avery 5371']);
        $mine = CardStock::factory()->for($org, 'organization')->create();
        $theirs = CardStock::factory()->for($other, 'organization')->create();

        $visible = CardStock::visibleTo($org->id)->pluck('id');

        $this->assertTrue($visible->contains($system->id));
        $this->assertTrue($visible->contains($mine->id));
        $this->assertFalse($visible->contains($theirs->id));
    }

    public function test_a_foreign_orgs_stock_does_not_resolve_but_a_system_one_does(): void
    {
        $org = Organization::factory()->create();
        $other = Organization::factory()->create();
        $actor = User::factory()->for($org, 'organization')->withRole('Admin')->create();
        $this->actingAs($actor);

        $foreign = CardStock::factory()->for($other, 'organization')->create();
        $system = CardStock::factory()->system()->create();

        $this->assertNull((new CardStock)->resolveRouteBinding($foreign->id));
        // System rows resolve; the policy decides what you may do with them.
        $this->assertNotNull((new CardStock)->resolveRouteBinding($system->id));
    }

    public function test_admins_manage_their_own_stocks_and_managers_only_read(): void
    {
        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();
        $manager = User::factory()->for($org, 'organization')->withRole('Manager')->create();
        $mine = CardStock::factory()->for($org, 'organization')->create();

        $this->assertTrue(Gate::forUser($admin)->allows('viewAny', CardStock::class));
        $this->assertTrue(Gate::forUser($admin)->allows('update', $mine));
        $this->assertTrue(Gate::forUser($admin)->allows('create', CardStock::class));

        // Managers pick a stock when printing, so they must be able to list.
        $this->assertTrue(Gate::forUser($manager)->allows('viewAny', CardStock::class));
        $this->assertFalse(Gate::forUser($manager)->allows('update', $mine));
        $this->assertFalse(Gate::forUser($manager)->allows('create', CardStock::class));
    }

    public function test_system_stocks_are_read_only_from_the_org_ui(): void
    {
        // Same rule as system merge fields: shipped content, console-managed.
        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();
        $system = CardStock::factory()->system()->create();

        $this->assertFalse(Gate::forUser($admin)->allows('update', $system));
        $this->assertFalse(Gate::forUser($admin)->allows('delete', $system));
    }
}
