<?php

namespace Tests\Feature\Tenancy;

use App\Models\CardField;
use App\Models\ClassTraining;
use App\Models\ClassTrainingCardValue;
use App\Models\Organization;
use App\Models\Training;
use App\Models\TrainingClass;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Defining a training's custom card fields (custom-certs C3). One PUT states
 * the whole set — what's absent is removed — so the payload the editor holds
 * is the definition, with no add/remove/reorder endpoints to keep in step.
 */
class TrainingCardFieldsApiTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $admin;

    private Training $training;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);

        $this->org = Organization::factory()->create();
        $this->admin = User::factory()->for($this->org, 'organization')->withRole('Admin')->create();
        $this->training = Training::factory()->for($this->org, 'organization')->create();
    }

    private function url(?Training $training = null): string
    {
        return '/api/trainings/'.($training ?? $this->training)->id.'/card-fields';
    }

    /** @param array<int, array<string, mixed>> $fields */
    private function sync(array $fields, ?User $actor = null)
    {
        return $this->actingAs($actor ?? $this->admin)
            ->putJson($this->url(), ['fields' => $fields]);
    }

    private function field(array $overrides = []): array
    {
        return array_merge([
            'id' => null,
            'key' => 'trainer_id',
            'label' => 'Trainer ID',
            'type' => 'short',
            'default_value' => null,
        ], $overrides);
    }

    // ---- defining ---------------------------------------------------------

    public function test_an_admin_defines_fields_and_gets_them_back_in_order(): void
    {
        $this->sync([
            $this->field(['key' => 'trainer_id', 'label' => 'Trainer ID']),
            $this->field(['key' => 'endorsement', 'label' => 'Endorsement', 'type' => 'rich']),
        ])
            ->assertOk()
            ->assertJsonCount(2)
            ->assertJsonPath('0.key', 'trainer_id')
            ->assertJsonPath('0.placeholder', '${trainer_id}')
            ->assertJsonPath('1.key', 'endorsement')
            ->assertJsonPath('1.type', 'rich');

        // seq comes from the payload's order, not from the client.
        $this->assertSame([0, 1], CardField::orderBy('seq')->pluck('seq')->all());
        $this->assertSame($this->org->id, CardField::first()->org_id);
    }

    public function test_reordering_is_just_a_different_payload_order(): void
    {
        $first = CardField::factory()->for($this->training)->create(['key' => 'alpha', 'seq' => 0]);
        $second = CardField::factory()->for($this->training)->create(['key' => 'beta', 'seq' => 1]);

        $this->sync([
            $this->field(['id' => $second->id, 'key' => 'beta', 'label' => 'Beta']),
            $this->field(['id' => $first->id, 'key' => 'alpha', 'label' => 'Alpha']),
        ])->assertOk();

        $this->assertSame(['beta', 'alpha'], $this->training->cardFields()->pluck('key')->all());
    }

    public function test_an_absent_field_is_removed(): void
    {
        $kept = CardField::factory()->for($this->training)->create(['key' => 'kept']);
        $dropped = CardField::factory()->for($this->training)->create(['key' => 'dropped']);

        $this->sync([$this->field(['id' => $kept->id, 'key' => 'kept'])])
            ->assertOk()
            ->assertJsonCount(1);

        $this->assertModelExists($kept);
        $this->assertModelMissing($dropped);
    }

    public function test_clearing_every_field_is_allowed(): void
    {
        CardField::factory()->for($this->training)->create();

        $this->sync([])->assertOk()->assertJsonCount(0);

        $this->assertSame(0, $this->training->cardFields()->count());
    }

    public function test_editing_a_field_keeps_the_answers_already_entered(): void
    {
        // Answers are keyed on the field's id, so relabelling — or even
        // renaming the key — must not lose what classes already recorded.
        $field = CardField::factory()->for($this->training)->create(['key' => 'trainer_id']);
        $ct = $this->topicFor($this->training);
        ClassTrainingCardValue::create([
            'org_id' => $this->org->id,
            'class_training_id' => $ct->id,
            'card_field_id' => $field->id,
            'value' => 'INST-4471',
        ]);

        $this->sync([
            $this->field(['id' => $field->id, 'key' => 'instructor_id', 'label' => 'Instructor ID']),
        ])->assertOk();

        $this->assertSame('instructor_id', $field->fresh()->key);
        $this->assertSame('INST-4471', $ct->cardValues()->first()->value);
    }

    public function test_removing_a_field_takes_its_answers_with_it(): void
    {
        $field = CardField::factory()->for($this->training)->create();
        $ct = $this->topicFor($this->training);
        ClassTrainingCardValue::create([
            'org_id' => $this->org->id,
            'class_training_id' => $ct->id,
            'card_field_id' => $field->id,
            'value' => 'INST-4471',
        ]);

        $this->sync([])->assertOk();

        $this->assertDatabaseCount('class_training_card_values', 0);
    }

    public function test_two_fields_can_swap_keys(): void
    {
        // The unique (training, key) index makes this the one reorder that can
        // deadlock a naive one-pass update — mid-flight both rows would hold
        // the same key.
        $a = CardField::factory()->for($this->training)->create(['key' => 'front', 'seq' => 0]);
        $b = CardField::factory()->for($this->training)->create(['key' => 'back', 'seq' => 1]);

        $this->sync([
            $this->field(['id' => $a->id, 'key' => 'back', 'label' => 'Back']),
            $this->field(['id' => $b->id, 'key' => 'front', 'label' => 'Front']),
        ])->assertOk();

        $this->assertSame('back', $a->fresh()->key);
        $this->assertSame('front', $b->fresh()->key);
    }

    public function test_a_default_value_is_stored(): void
    {
        // The point of a default: "our trainer id is always this" typed once.
        $this->sync([$this->field(['default_value' => 'INST-4471'])])
            ->assertOk()
            ->assertJsonPath('0.default_value', 'INST-4471');
    }

    public function test_definitions_report_how_many_answers_exist(): void
    {
        // Removing a field discards the answers entered against it, so the
        // editor's confirmation names the number rather than warning vaguely.
        $field = CardField::factory()->for($this->training)->create();
        $untouched = CardField::factory()->for($this->training)->create(['key' => 'untouched']);

        foreach ([$this->topicFor($this->training), $this->topicFor($this->training)] as $ct) {
            ClassTrainingCardValue::create([
                'org_id' => $this->org->id,
                'class_training_id' => $ct->id,
                'card_field_id' => $field->id,
                'value' => 'INST-4471',
            ]);
        }

        $json = $this->actingAs($this->admin)->getJson($this->url())->assertOk()->json();
        $counts = array_column($json, 'value_count', 'id');

        $this->assertSame(2, $counts[$field->id]);
        $this->assertSame(0, $counts[$untouched->id]);
    }

    public function test_the_sync_response_reports_answer_counts_too(): void
    {
        // The editor rebuilds its baseline from the response, so it must carry
        // the same information the initial load did.
        $field = CardField::factory()->for($this->training)->create();
        ClassTrainingCardValue::create([
            'org_id' => $this->org->id,
            'class_training_id' => $this->topicFor($this->training)->id,
            'card_field_id' => $field->id,
            'value' => 'INST-4471',
        ]);

        $this->sync([$this->field(['id' => $field->id, 'key' => $field->key])])
            ->assertOk()
            ->assertJsonPath('0.value_count', 1);
    }

    // ---- validation -------------------------------------------------------

    public function test_a_key_must_be_lowercase_snake_case(): void
    {
        foreach (['Trainer ID', 'trainer-id', '1st_trainer', 'trainer id', 'TRAINER'] as $bad) {
            $this->sync([$this->field(['key' => $bad])])
                ->assertStatus(422)
                ->assertJsonValidationErrors('fields.0.key');
        }
    }

    public function test_a_key_cannot_shadow_a_built_in_merge_key(): void
    {
        // ${first_name} must always mean the student's first name.
        $this->sync([$this->field(['key' => 'first_name'])])
            ->assertStatus(422)
            ->assertJsonValidationErrors('fields.0.key');
    }

    public function test_keys_must_be_unique_within_the_payload(): void
    {
        $this->sync([
            $this->field(['key' => 'trainer_id']),
            $this->field(['key' => 'trainer_id', 'label' => 'Other']),
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['fields.0.key', 'fields.1.key']);
    }

    public function test_the_type_must_be_short_or_rich(): void
    {
        $this->sync([$this->field(['type' => 'wysiwyg'])])
            ->assertStatus(422)
            ->assertJsonValidationErrors('fields.0.type');
    }

    public function test_a_short_default_is_one_line_and_capped_at_100(): void
    {
        $this->sync([$this->field(['default_value' => str_repeat('x', 101)])])
            ->assertStatus(422)
            ->assertJsonValidationErrors('fields.0.default_value');

        // "no formatting" includes not smuggling in line breaks.
        $this->sync([$this->field(['default_value' => "line one\nline two"])])
            ->assertStatus(422)
            ->assertJsonValidationErrors('fields.0.default_value');
    }

    public function test_a_rich_default_may_be_multiline_up_to_2000(): void
    {
        $this->sync([$this->field([
            'type' => 'rich',
            'key' => 'endorsement',
            'default_value' => "**Authorized** for:\n\n- Sit-down\n- Stand-up",
        ])])->assertOk();

        $this->sync([$this->field([
            'type' => 'rich',
            'key' => 'endorsement',
            'default_value' => str_repeat('x', 2001),
        ])])
            ->assertStatus(422)
            ->assertJsonValidationErrors('fields.0.default_value');
    }

    public function test_a_label_is_required(): void
    {
        $this->sync([$this->field(['label' => ''])])
            ->assertStatus(422)
            ->assertJsonValidationErrors('fields.0.label');
    }

    public function test_an_id_from_another_training_is_rejected(): void
    {
        // Otherwise a sync could adopt (and rewrite) a field belonging to a
        // different training.
        $other = Training::factory()->for($this->org, 'organization')->create();
        $foreign = CardField::factory()->for($other)->create();

        $this->sync([$this->field(['id' => $foreign->id])])
            ->assertStatus(422)
            ->assertJsonValidationErrors('fields.0.id');
    }

    // ---- authorization ----------------------------------------------------

    public function test_a_manager_cannot_define_fields(): void
    {
        // Managers enter values on a class; defining the vocabulary is Admin+.
        $manager = User::factory()->for($this->org, 'organization')->withRole('Manager')->create();

        $this->sync([$this->field()], $manager)->assertForbidden();
    }

    public function test_a_self_view_user_cannot_read_the_definitions(): void
    {
        $viewer = User::factory()->for($this->org, 'organization')->withRole('SelfView')->create();

        $this->actingAs($viewer)->getJson($this->url())->assertForbidden();
    }

    public function test_an_admin_reads_the_definitions(): void
    {
        CardField::factory()->for($this->training)->create(['key' => 'trainer_id', 'seq' => 0]);

        $this->actingAs($this->admin)
            ->getJson($this->url())
            ->assertOk()
            ->assertJsonPath('0.key', 'trainer_id');
    }

    public function test_another_orgs_training_is_not_found(): void
    {
        $other = Organization::factory()->create();
        $foreign = Training::factory()->for($other, 'organization')->create();

        $this->actingAs($this->admin)
            ->putJson($this->url($foreign), ['fields' => [$this->field()]])
            ->assertNotFound();
    }

    public function test_a_guest_is_rejected(): void
    {
        $this->putJson($this->url(), ['fields' => []])->assertUnauthorized();
    }

    // ---- helpers ----------------------------------------------------------

    private function topicFor(Training $training): ClassTraining
    {
        $class = TrainingClass::factory()->for($this->org, 'organization')->create();

        return ClassTraining::factory()
            ->for($class, 'trainingClass')
            ->for($training, 'training')
            ->create();
    }
}
