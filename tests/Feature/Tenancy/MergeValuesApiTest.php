<?php

namespace Tests\Feature\Tenancy;

use App\Events\MergeValuesChanged;
use App\Models\MergeField;
use App\Models\MergeValue;
use App\Models\Organization;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class MergeValuesApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    // ---- index ----------------------------------------------------------

    public function test_manager_lists_only_own_org_values(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $manager = User::factory()->for($orgA, 'organization')->withRole('Manager')->create();
        $system = MergeField::factory()->system()->create(['key' => 'agency']);
        MergeValue::factory()->for($orgA, 'organization')->for($system, 'field')->create(['value' => 'Rio Dell']);
        MergeValue::factory()->for($orgB, 'organization')->for($system, 'field')->create(['value' => 'Other Org']);

        $rows = $this->actingAs($manager)
            ->getJson('/api/merge-values')
            ->assertOk()
            ->assertJsonCount(1)
            ->json();

        $this->assertSame('Rio Dell', $rows[0]['value']);
    }

    public function test_below_manager_cannot_list_values(): void
    {
        $org = Organization::factory()->create();
        $member = User::factory()->for($org, 'organization')->withRole('SelfEdit')->create();

        $this->actingAs($member)
            ->getJson('/api/merge-values')
            ->assertForbidden();
    }

    // ---- upsert ---------------------------------------------------------

    public function test_manager_can_set_org_default_value_for_system_field(): void
    {
        Event::fake([MergeValuesChanged::class]);
        $org = Organization::factory()->create();
        $manager = User::factory()->for($org, 'organization')->withRole('Manager')->create();
        $field = MergeField::factory()->system()->create(['key' => 'agency']);

        $this->actingAs($manager)
            ->putJson('/api/merge-values', [
                'merge_field_id' => $field->id,
                'value' => 'City of Rio Dell',
            ])
            ->assertOk()
            ->assertJsonFragment(['location' => '', 'department' => '']);

        $this->assertDatabaseHas('merge_values', [
            'org_id' => $org->id,
            'merge_field_id' => $field->id,
            'location' => '',
            'department' => '',
        ]);
        Event::assertDispatched(MergeValuesChanged::class);
    }

    public function test_upsert_updates_the_existing_variation_row(): void
    {
        $org = Organization::factory()->create();
        $manager = User::factory()->for($org, 'organization')->withRole('Manager')->create();
        $field = MergeField::factory()->for($org, 'organization')->create(['type' => 'text']);
        MergeValue::factory()->for($org, 'organization')->for($field, 'field')->create(['value' => 'Old']);

        $this->actingAs($manager)
            ->putJson('/api/merge-values', [
                'merge_field_id' => $field->id,
                'value' => 'New',
            ])
            ->assertOk();

        $this->assertDatabaseCount('merge_values', 1);
        $this->assertSame('New', MergeValue::first()->value);
    }

    public function test_location_variation_is_a_separate_row(): void
    {
        $org = Organization::factory()->create();
        $manager = User::factory()->for($org, 'organization')->withRole('Manager')->create();
        $field = MergeField::factory()->for($org, 'organization')->create(['type' => 'text']);
        MergeValue::factory()->for($org, 'organization')->for($field, 'field')->create(['value' => 'Org default']);

        $this->actingAs($manager)
            ->putJson('/api/merge-values', [
                'merge_field_id' => $field->id,
                'location' => 'North Yard',
                'value' => 'North override',
            ])
            ->assertOk();

        $this->assertDatabaseCount('merge_values', 2);
        $this->assertDatabaseHas('merge_values', [
            'location' => 'North Yard',
            'department' => '',
        ]);
    }

    public function test_below_manager_cannot_upsert(): void
    {
        $org = Organization::factory()->create();
        $member = User::factory()->for($org, 'organization')->withRole('SelfEdit')->create();
        $field = MergeField::factory()->system()->create();

        $this->actingAs($member)
            ->putJson('/api/merge-values', ['merge_field_id' => $field->id, 'value' => 'x'])
            ->assertForbidden();
    }

    public function test_upsert_rejects_foreign_org_field(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $managerA = User::factory()->for($orgA, 'organization')->withRole('Manager')->create();
        $fieldB = MergeField::factory()->for($orgB, 'organization')->create();

        $this->actingAs($managerA)
            ->putJson('/api/merge-values', ['merge_field_id' => $fieldB->id, 'value' => 'x'])
            ->assertStatus(422);
    }

    // ---- type-shaped value validation ------------------------------------

    public function test_list_field_round_trips_an_array(): void
    {
        $org = Organization::factory()->create();
        $manager = User::factory()->for($org, 'organization')->withRole('Manager')->create();
        $field = MergeField::factory()->for($org, 'organization')->create(['type' => 'list']);

        $this->actingAs($manager)
            ->putJson('/api/merge-values', [
                'merge_field_id' => $field->id,
                'value' => ['Public Works', 'Parks', 'Water'],
            ])
            ->assertOk();

        $this->assertSame(['Public Works', 'Parks', 'Water'], MergeValue::first()->value);
    }

    public function test_list_field_rejects_a_plain_string(): void
    {
        $org = Organization::factory()->create();
        $manager = User::factory()->for($org, 'organization')->withRole('Manager')->create();
        $field = MergeField::factory()->for($org, 'organization')->create(['type' => 'list']);

        $this->actingAs($manager)
            ->putJson('/api/merge-values', ['merge_field_id' => $field->id, 'value' => 'not|a|list'])
            ->assertStatus(422);
    }

    public function test_text_field_rejects_an_array(): void
    {
        $org = Organization::factory()->create();
        $manager = User::factory()->for($org, 'organization')->withRole('Manager')->create();
        $field = MergeField::factory()->for($org, 'organization')->create(['type' => 'text']);

        $this->actingAs($manager)
            ->putJson('/api/merge-values', ['merge_field_id' => $field->id, 'value' => ['a', 'b']])
            ->assertStatus(422);
    }

    public function test_date_field_requires_iso_date(): void
    {
        $org = Organization::factory()->create();
        $manager = User::factory()->for($org, 'organization')->withRole('Manager')->create();
        $field = MergeField::factory()->for($org, 'organization')->create(['type' => 'date']);

        $this->actingAs($manager)
            ->putJson('/api/merge-values', ['merge_field_id' => $field->id, 'value' => 'July 4th'])
            ->assertStatus(422);

        $this->actingAs($manager)
            ->putJson('/api/merge-values', ['merge_field_id' => $field->id, 'value' => '2026-07-04'])
            ->assertOk();
    }

    // ---- delete (clear override) -----------------------------------------

    public function test_manager_can_clear_a_value(): void
    {
        Event::fake([MergeValuesChanged::class]);
        $org = Organization::factory()->create();
        $manager = User::factory()->for($org, 'organization')->withRole('Manager')->create();
        $field = MergeField::factory()->for($org, 'organization')->create();
        $value = MergeValue::factory()->for($org, 'organization')->for($field, 'field')->create();

        $this->actingAs($manager)
            ->deleteJson("/api/merge-values/{$value->id}")
            ->assertOk();

        // Hard delete by design — the variation unique index must stay free.
        $this->assertDatabaseCount('merge_values', 0);
        Event::assertDispatched(MergeValuesChanged::class);
    }

    public function test_cross_org_delete_is_404(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $managerA = User::factory()->for($orgA, 'organization')->withRole('Manager')->create();
        $field = MergeField::factory()->system()->create();
        $valueB = MergeValue::factory()->for($orgB, 'organization')->for($field, 'field')->create();

        $this->actingAs($managerA)
            ->deleteJson("/api/merge-values/{$valueB->id}")
            ->assertNotFound();
    }
}
