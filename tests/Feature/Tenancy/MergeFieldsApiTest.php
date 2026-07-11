<?php

namespace Tests\Feature\Tenancy;

use App\Events\MergeFieldsChanged;
use App\Models\MergeField;
use App\Models\MergeValue;
use App\Models\Organization;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class MergeFieldsApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    // ---- index / visibility -------------------------------------------

    public function test_manager_can_list_fields_and_sees_system_plus_own_org(): void
    {
        $org = Organization::factory()->create();
        $other = Organization::factory()->create();
        $manager = User::factory()->for($org, 'organization')->withRole('Manager')->create();

        MergeField::factory()->system()->create(['key' => 'agency']);
        MergeField::factory()->for($org, 'organization')->create(['key' => 'union_rep']);
        MergeField::factory()->for($other, 'organization')->create(['key' => 'foreign_field']);

        $rows = $this->actingAs($manager)
            ->getJson('/api/merge-fields')
            ->assertOk()
            ->assertJsonCount(2)
            ->json();

        $keys = collect($rows)->pluck('key');
        $this->assertTrue($keys->contains('agency'));
        $this->assertTrue($keys->contains('union_rep'));
        $this->assertFalse($keys->contains('foreign_field'));

        $byKey = collect($rows)->keyBy('key');
        $this->assertTrue($byKey['agency']['is_system']);
        $this->assertFalse($byKey['union_rep']['is_system']);
    }

    public function test_index_marks_editability_per_row(): void
    {
        // System fields are read-only from the org UI (console-managed until
        // site-admin tooling); org fields are editable by Admin+.
        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();
        MergeField::factory()->system()->create(['key' => 'agency']);
        MergeField::factory()->for($org, 'organization')->create(['key' => 'union_rep']);

        $byKey = collect(
            $this->actingAs($admin)->getJson('/api/merge-fields')->assertOk()->json()
        )->keyBy('key');

        $this->assertFalse($byKey['agency']['can_edit']);
        $this->assertTrue($byKey['union_rep']['can_edit']);
    }

    public function test_below_manager_cannot_list_fields(): void
    {
        $org = Organization::factory()->create();
        $member = User::factory()->for($org, 'organization')->withRole('SelfEdit')->create();

        $this->actingAs($member)
            ->getJson('/api/merge-fields')
            ->assertForbidden();
    }

    public function test_index_orders_by_group_then_seq(): void
    {
        $org = Organization::factory()->create();
        $manager = User::factory()->for($org, 'organization')->withRole('Manager')->create();
        MergeField::factory()->for($org, 'organization')->create(['key' => 'b_second', 'field_group' => 'Agency', 'seq' => 2]);
        MergeField::factory()->for($org, 'organization')->create(['key' => 'a_first', 'field_group' => 'Agency', 'seq' => 1]);
        MergeField::factory()->for($org, 'organization')->create(['key' => 'z_other_group', 'field_group' => 'Emergency', 'seq' => 0]);

        $keys = collect(
            $this->actingAs($manager)->getJson('/api/merge-fields')->assertOk()->json()
        )->pluck('key')->all();

        $this->assertSame(['a_first', 'b_second', 'z_other_group'], $keys);
    }

    // ---- create ---------------------------------------------------------

    public function test_admin_can_create_org_field(): void
    {
        Event::fake([MergeFieldsChanged::class]);
        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();

        $this->actingAs($admin)
            ->postJson('/api/merge-fields', [
                'key' => 'union_rep',
                'label' => 'Union representative',
                'type' => 'text',
                'field_group' => 'Agency',
            ])
            ->assertCreated()
            ->assertJsonFragment(['key' => 'union_rep', 'is_system' => false]);

        $this->assertDatabaseHas('merge_fields', [
            'key' => 'union_rep',
            'org_id' => $org->id,
        ]);
        Event::assertDispatched(MergeFieldsChanged::class);
    }

    public function test_manager_cannot_create_field(): void
    {
        $org = Organization::factory()->create();
        $manager = User::factory()->for($org, 'organization')->withRole('Manager')->create();

        $this->actingAs($manager)
            ->postJson('/api/merge-fields', ['key' => 'sneaky', 'label' => 'X', 'type' => 'text'])
            ->assertForbidden();
    }

    public function test_create_validates_key_format(): void
    {
        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();

        foreach (['Bad Key', '1abc', 'UPPER', 'ends-badly', '${agency}'] as $bad) {
            $this->actingAs($admin)
                ->postJson('/api/merge-fields', ['key' => $bad, 'label' => 'X', 'type' => 'text'])
                ->assertStatus(422);
        }
    }

    public function test_create_validates_type(): void
    {
        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();

        $this->actingAs($admin)
            ->postJson('/api/merge-fields', ['key' => 'ok_key', 'label' => 'X', 'type' => 'jsonb'])
            ->assertStatus(422);
    }

    public function test_create_rejects_duplicate_key_in_org(): void
    {
        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();
        MergeField::factory()->for($org, 'organization')->create(['key' => 'union_rep']);

        $this->actingAs($admin)
            ->postJson('/api/merge-fields', ['key' => 'union_rep', 'label' => 'X', 'type' => 'text'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('key');
    }

    public function test_create_rejects_key_shadowing_a_system_field(): void
    {
        // Decision 2026-07-11: no shadowing. One definition of ${agency}
        // everywhere; the org enters its value instead.
        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();
        MergeField::factory()->system()->create(['key' => 'agency']);

        $this->actingAs($admin)
            ->postJson('/api/merge-fields', ['key' => 'agency', 'label' => 'X', 'type' => 'text'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('key');
    }

    public function test_same_key_allowed_in_different_orgs(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $adminB = User::factory()->for($orgB, 'organization')->withRole('Admin')->create();
        MergeField::factory()->for($orgA, 'organization')->create(['key' => 'union_rep']);

        $this->actingAs($adminB)
            ->postJson('/api/merge-fields', ['key' => 'union_rep', 'label' => 'X', 'type' => 'text'])
            ->assertCreated();
    }

    public function test_soft_deleted_key_can_be_recreated(): void
    {
        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();
        $field = MergeField::factory()->for($org, 'organization')->create(['key' => 'union_rep']);
        $field->delete();

        $this->actingAs($admin)
            ->postJson('/api/merge-fields', ['key' => 'union_rep', 'label' => 'X', 'type' => 'text'])
            ->assertCreated();
    }

    // ---- update ---------------------------------------------------------

    public function test_admin_can_update_own_org_field(): void
    {
        Event::fake([MergeFieldsChanged::class]);
        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();
        $field = MergeField::factory()->for($org, 'organization')->create(['key' => 'union_rep', 'label' => 'Old']);

        $this->actingAs($admin)
            ->patchJson("/api/merge-fields/{$field->id}", [
                'key' => 'union_rep',
                'label' => 'New label',
                'type' => 'text',
            ])
            ->assertOk();

        $this->assertSame('New label', $field->fresh()->label);
        Event::assertDispatched(MergeFieldsChanged::class);
    }

    public function test_system_field_cannot_be_updated_from_org_ui(): void
    {
        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();
        $field = MergeField::factory()->system()->create(['key' => 'agency']);

        $this->actingAs($admin)
            ->patchJson("/api/merge-fields/{$field->id}", ['key' => 'agency', 'label' => 'Hacked', 'type' => 'text'])
            ->assertForbidden();
    }

    public function test_cross_org_update_is_404(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $adminA = User::factory()->for($orgA, 'organization')->withRole('Admin')->create();
        $fieldB = MergeField::factory()->for($orgB, 'organization')->create();

        $this->actingAs($adminA)
            ->patchJson("/api/merge-fields/{$fieldB->id}", ['key' => 'x', 'label' => 'X', 'type' => 'text'])
            ->assertNotFound();
    }

    public function test_update_rejects_type_change_when_values_exist(): void
    {
        // A stored string value under a field flipped to `list` (or vice
        // versa) would break every consumer expecting the other shape.
        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();
        $field = MergeField::factory()->for($org, 'organization')->create(['key' => 'union_rep', 'type' => 'text']);
        MergeValue::factory()->for($org, 'organization')->for($field, 'field')->create(['value' => 'Pat Smith']);

        $this->actingAs($admin)
            ->patchJson("/api/merge-fields/{$field->id}", ['key' => 'union_rep', 'label' => 'X', 'type' => 'list'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('type');
    }

    public function test_update_allows_type_change_when_no_values(): void
    {
        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();
        $field = MergeField::factory()->for($org, 'organization')->create(['key' => 'union_rep', 'type' => 'text']);

        $this->actingAs($admin)
            ->patchJson("/api/merge-fields/{$field->id}", ['key' => 'union_rep', 'label' => 'X', 'type' => 'list'])
            ->assertOk();

        $this->assertSame('list', $field->fresh()->type);
    }

    // ---- destroy --------------------------------------------------------

    public function test_admin_can_delete_org_field_and_values_go_with_it(): void
    {
        Event::fake([MergeFieldsChanged::class]);
        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();
        $field = MergeField::factory()->for($org, 'organization')->create();
        MergeValue::factory()->for($org, 'organization')->for($field, 'field')->create();

        $this->actingAs($admin)
            ->deleteJson("/api/merge-fields/{$field->id}")
            ->assertOk();

        $this->assertSoftDeleted('merge_fields', ['id' => $field->id]);
        // Values are hard rows under a soft-deleted field: cleared explicitly
        // so a re-created key starts blank (mirrors TagsController's pivot clear).
        $this->assertDatabaseCount('merge_values', 0);
        Event::assertDispatched(MergeFieldsChanged::class);
    }

    public function test_system_field_cannot_be_deleted_from_org_ui(): void
    {
        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();
        $field = MergeField::factory()->system()->create();

        $this->actingAs($admin)
            ->deleteJson("/api/merge-fields/{$field->id}")
            ->assertForbidden();
    }

    public function test_cross_org_delete_is_404(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $adminA = User::factory()->for($orgA, 'organization')->withRole('Admin')->create();
        $fieldB = MergeField::factory()->for($orgB, 'organization')->create();

        $this->actingAs($adminA)
            ->deleteJson("/api/merge-fields/{$fieldB->id}")
            ->assertNotFound();
    }
}
