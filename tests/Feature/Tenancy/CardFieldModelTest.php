<?php

namespace Tests\Feature\Tenancy;

use App\Models\CardField;
use App\Models\ClassTraining;
use App\Models\ClassTrainingCardValue;
use App\Models\Organization;
use App\Models\Training;
use App\Models\TrainingClass;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Custom card fields (custom-certs C3) — the user-defined key/value pairs a
 * card design can merge beyond the built-in catalogue (trainer id, etc.).
 *
 * Definitions belong to a TRAINING and are inherited by every class that
 * teaches it; the values belong to a `class_training` row, so one class can
 * carry First Aid *and* Forklift with a separate set of values for each.
 */
class CardFieldModelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    /** Org + training + one class teaching it. */
    private function scenario(): array
    {
        $org = Organization::factory()->create();
        $training = Training::factory()->for($org, 'organization')->create();
        $class = TrainingClass::factory()->for($org, 'organization')->create();
        $ct = ClassTraining::factory()->for($class, 'trainingClass')->for($training, 'training')->create();

        return [$org, $training, $ct];
    }

    public function test_card_fields_table_shape(): void
    {
        $this->assertTrue(Schema::hasColumns('card_fields', [
            'id', 'org_id', 'training_id',
            'key', 'label', 'type', 'default_value', 'seq',
            'created_at', 'updated_at',
        ]));

        // Hard-delete by design (the printed PDF filed to class documents is
        // the historical record, not this table), so no deleted_at to make
        // key reuse ambiguous.
        $this->assertFalse(Schema::hasColumn('card_fields', 'deleted_at'));

        [$org, $training] = $this->scenario();
        $field = CardField::factory()->for($training)->create(['key' => 'trainer_id']);

        $this->assertSame($org->id, $field->org_id);
        $this->assertSame($training->id, $field->training_id);
    }

    public function test_card_values_table_shape(): void
    {
        $this->assertTrue(Schema::hasColumns('class_training_card_values', [
            'id', 'org_id', 'class_training_id', 'card_field_id', 'value',
            'created_at', 'updated_at',
        ]));

        [$org, $training, $ct] = $this->scenario();
        $field = CardField::factory()->for($training)->create();

        $value = ClassTrainingCardValue::create([
            'org_id' => $org->id,
            'class_training_id' => $ct->id,
            'card_field_id' => $field->id,
            'value' => 'INST-4471',
        ]);

        $this->assertSame('INST-4471', $value->fresh()->value);
    }

    public function test_a_key_is_unique_per_training_but_reusable_across_trainings(): void
    {
        [, $training] = $this->scenario();
        $other = Training::factory()->for($training->organization, 'organization')->create();

        CardField::factory()->for($training)->create(['key' => 'trainer_id']);
        // Two trainings each wanting a trainer_id is the normal case.
        CardField::factory()->for($other)->create(['key' => 'trainer_id']);

        $this->expectException(QueryException::class);
        CardField::factory()->for($training)->create(['key' => 'trainer_id']);
    }

    public function test_one_value_per_field_per_class_topic(): void
    {
        [$org, $training, $ct] = $this->scenario();
        $field = CardField::factory()->for($training)->create();

        $row = fn () => ClassTrainingCardValue::create([
            'org_id' => $org->id,
            'class_training_id' => $ct->id,
            'card_field_id' => $field->id,
            'value' => 'x',
        ]);

        $row();

        $this->expectException(QueryException::class);
        $row();
    }

    public function test_fields_come_back_in_seq_order(): void
    {
        // The order the admin arranged them in is the order they're entered
        // and the order the builder lists them for copy-paste.
        [, $training] = $this->scenario();
        CardField::factory()->for($training)->create(['key' => 'third', 'seq' => 3]);
        CardField::factory()->for($training)->create(['key' => 'first', 'seq' => 1]);
        CardField::factory()->for($training)->create(['key' => 'second', 'seq' => 2]);

        $this->assertSame(
            ['first', 'second', 'third'],
            $training->cardFields()->pluck('key')->all(),
        );
    }

    public function test_deleting_a_field_takes_its_class_values_with_it(): void
    {
        // The confirmed behaviour: removing a definition removes the values
        // entered against it, which is why the UI warns with a count first.
        [$org, $training, $ct] = $this->scenario();
        $field = CardField::factory()->for($training)->create();
        ClassTrainingCardValue::create([
            'org_id' => $org->id,
            'class_training_id' => $ct->id,
            'card_field_id' => $field->id,
            'value' => 'INST-4471',
        ]);

        $field->delete();

        $this->assertDatabaseCount('class_training_card_values', 0);
    }

    public function test_deleting_a_training_takes_its_fields_with_it(): void
    {
        [, $training] = $this->scenario();
        CardField::factory()->for($training)->create();

        $training->forceDelete();

        $this->assertDatabaseCount('card_fields', 0);
    }

    public function test_detaching_a_topic_from_a_class_takes_its_values_with_it(): void
    {
        [$org, $training, $ct] = $this->scenario();
        $field = CardField::factory()->for($training)->create();
        ClassTrainingCardValue::create([
            'org_id' => $org->id,
            'class_training_id' => $ct->id,
            'card_field_id' => $field->id,
            'value' => 'INST-4471',
        ]);

        $ct->delete();

        $this->assertDatabaseCount('class_training_card_values', 0);
        // The definition survives — only this class's answer went away.
        $this->assertDatabaseCount('card_fields', 1);
    }

    public function test_the_org_scope_hides_another_orgs_fields(): void
    {
        [, $training] = $this->scenario();
        $other = Organization::factory()->create();
        $theirTraining = Training::factory()->for($other, 'organization')->create();

        $mine = CardField::factory()->for($training)->create();
        $theirs = CardField::factory()->for($theirTraining)->create();

        app()->instance('currentOrgId', $training->org_id);

        $ids = CardField::query()->pluck('id');
        $this->assertTrue($ids->contains($mine->id));
        $this->assertFalse($ids->contains($theirs->id));
    }

    public function test_a_topic_exposes_its_values_keyed_by_field(): void
    {
        // How the class-detail payload and (later) the merge assemble a card:
        // look up "what did this class answer for this field".
        [$org, $training, $ct] = $this->scenario();
        $trainer = CardField::factory()->for($training)->create(['key' => 'trainer_id']);
        CardField::factory()->for($training)->create(['key' => 'notes']);

        ClassTrainingCardValue::create([
            'org_id' => $org->id,
            'class_training_id' => $ct->id,
            'card_field_id' => $trainer->id,
            'value' => 'INST-4471',
        ]);

        $byField = $ct->cardValues()->get()->keyBy('card_field_id');

        $this->assertSame('INST-4471', $byField[$trainer->id]->value);
        $this->assertCount(1, $byField);
    }
}
