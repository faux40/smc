<?php

namespace Tests\Feature\Tenancy;

use App\Models\CardStock;
use App\Models\Organization;
use App\Models\Training;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A training carries the card stock its classes print onto by default, beside
 * the design they print. Both answer the same question — "which one is right
 * for First Aid?" — and neither is something a Manager closing out a class
 * should have to remember. The print dialog still overrides per run: the
 * default is what's usually in the printer, not a rule.
 */
class TrainingCardStockTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private function payload(array $overrides = []): array
    {
        // as-needed keeps the frequency rules out of the way; this suite is
        // about the card stock.
        return array_merge([
            'name' => 'First Aid / CPR',
            'initial_only' => false,
            'repeating' => false,
            'as_needed' => true,
            'std_freq_id' => null,
        ], $overrides);
    }

    public function test_a_training_can_be_given_a_card_stock(): void
    {
        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();
        $stock = CardStock::factory()->for($org, 'organization')->create();

        $this->actingAs($admin)
            ->postJson('/api/trainings', $this->payload([
                'card_stock_id' => $stock->id,
            ]))
            ->assertCreated()
            ->assertJsonPath('card_stock_id', $stock->id);

        $this->assertDatabaseHas('trainings', [
            'name' => 'First Aid / CPR',
            'card_stock_id' => $stock->id,
        ]);
    }

    public function test_a_training_without_a_card_stock_is_fine(): void
    {
        // Most trainings print no card at all, so no stock applies.
        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();

        $this->actingAs($admin)
            ->postJson('/api/trainings', $this->payload())
            ->assertCreated()
            ->assertJsonPath('card_stock_id', null);
    }

    public function test_a_system_card_stock_can_be_assigned(): void
    {
        // The shipped layouts are read-only, not unusable — and they are what
        // most orgs will actually be printing onto.
        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();
        $system = CardStock::factory()->system()->create();

        $this->actingAs($admin)
            ->postJson('/api/trainings', $this->payload(['card_stock_id' => $system->id]))
            ->assertCreated()
            ->assertJsonPath('card_stock_id', $system->id);
    }

    public function test_another_orgs_card_stock_cannot_be_assigned(): void
    {
        $org = Organization::factory()->create();
        $other = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();
        $foreign = CardStock::factory()->for($other, 'organization')->create();

        $this->actingAs($admin)
            ->postJson('/api/trainings', $this->payload(['card_stock_id' => $foreign->id]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('card_stock_id');
    }

    /** The training form is a full-form PATCH: an absent key means cleared. */
    public function test_update_treats_an_omitted_card_stock_as_cleared(): void
    {
        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();
        $stock = CardStock::factory()->for($org, 'organization')->create();
        $training = Training::factory()->for($org, 'organization')->create([
            'card_stock_id' => $stock->id,
        ]);

        $this->actingAs($admin)
            ->patchJson("/api/trainings/{$training->id}", $this->payload())
            ->assertOk()
            ->assertJsonPath('card_stock_id', null);
    }

    public function test_deleting_a_stock_detaches_it_from_trainings(): void
    {
        // The row is soft-deleted, so the FK would still "resolve" to a stock
        // the picker no longer lists — leaving the print dialog pre-selecting
        // an id it cannot show and the server would reject.
        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();
        $stock = CardStock::factory()->for($org, 'organization')->create();
        $training = Training::factory()->for($org, 'organization')->create([
            'card_stock_id' => $stock->id,
        ]);

        $this->actingAs($admin)
            ->deleteJson("/api/card-stocks/{$stock->id}")
            ->assertOk();

        $this->assertNull($training->fresh()->card_stock_id);
    }

    public function test_deleting_a_stock_leaves_another_orgs_trainings_alone(): void
    {
        $org = Organization::factory()->create();
        $other = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();
        $mine = CardStock::factory()->for($org, 'organization')->create();
        $theirStock = CardStock::factory()->for($other, 'organization')->create();
        $theirTraining = Training::factory()->for($other, 'organization')->create([
            'card_stock_id' => $theirStock->id,
        ]);

        $this->actingAs($admin)
            ->deleteJson("/api/card-stocks/{$mine->id}")
            ->assertOk();

        $this->assertSame($theirStock->id, $theirTraining->fresh()->card_stock_id);
    }
}
