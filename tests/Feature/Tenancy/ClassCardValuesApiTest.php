<?php

namespace Tests\Feature\Tenancy;

use App\Models\CardField;
use App\Models\ClassTraining;
use App\Models\Organization;
use App\Models\Training;
use App\Models\TrainingClass;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Filling in a class's answers for its topics' custom card fields
 * (custom-certs C3). Rides the existing per-topic PATCH: same guards, and
 * "card values" is just another thing you can edit about a topic on a class.
 *
 * Definitions are inherited from the training, so a class only ever supplies
 * values — and only while it's still editable (a completed class is read-only;
 * printing cards from one means reopening it).
 */
class ClassCardValuesApiTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $manager;

    private TrainingClass $class;

    private Training $training;

    private ClassTraining $topic;

    private CardField $trainerId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);

        $this->org = Organization::factory()->create();
        $this->manager = User::factory()->for($this->org, 'organization')->withRole('Manager')->create();
        $this->class = TrainingClass::factory()->for($this->org, 'organization')->create(['status' => 'scheduled']);
        $this->training = Training::factory()->for($this->org, 'organization')->create();
        $this->topic = ClassTraining::factory()
            ->for($this->class, 'trainingClass')
            ->for($this->training, 'training')
            ->create();
        $this->trainerId = CardField::factory()->for($this->training)->create([
            'key' => 'trainer_id',
            'label' => 'Trainer ID',
            'type' => 'short',
            'default_value' => 'INST-0000',
            'seq' => 0,
        ]);
    }

    private function url(?ClassTraining $topic = null): string
    {
        return "/api/classes/{$this->class->id}/trainings/".($topic ?? $this->topic)->id;
    }

    /** @param array<string, mixed> $values */
    private function save(array $values, ?User $actor = null)
    {
        return $this->actingAs($actor ?? $this->manager)
            ->patchJson($this->url(), ['card_values' => $values]);
    }

    /** The topic's card_fields block from a class-detail response. */
    private function fieldsFromDetail(array $json, ?string $topicId = null): array
    {
        $topicId ??= $this->topic->id;

        foreach ($json['trainings'] as $t) {
            if ($t['id'] === $topicId) {
                return $t['card_fields'];
            }
        }

        return [];
    }

    // ---- entering values --------------------------------------------------

    public function test_a_manager_enters_a_value_and_it_comes_back_on_the_topic(): void
    {
        $json = $this->save([$this->trainerId->id => 'INST-4471'])
            ->assertOk()
            ->json();

        $fields = $this->fieldsFromDetail($json);

        $this->assertCount(1, $fields);
        $this->assertSame('trainer_id', $fields[0]['key']);
        $this->assertSame('${trainer_id}', $fields[0]['placeholder']);
        $this->assertSame('Trainer ID', $fields[0]['label']);
        // The training's default is exposed alongside, so the form can show it
        // as the placeholder — "leave blank and this is what prints".
        $this->assertSame('INST-0000', $fields[0]['default_value']);
        $this->assertSame('INST-4471', $fields[0]['value']);
    }

    public function test_a_topic_with_no_value_yet_reports_null(): void
    {
        $fields = $this->fieldsFromDetail(
            $this->actingAs($this->manager)->getJson("/api/classes/{$this->class->id}")->assertOk()->json()
        );

        $this->assertSame('INST-0000', $fields[0]['default_value']);
        $this->assertNull($fields[0]['value']);
    }

    public function test_saving_twice_updates_rather_than_duplicates(): void
    {
        $this->save([$this->trainerId->id => 'INST-1'])->assertOk();
        $this->save([$this->trainerId->id => 'INST-2'])->assertOk();

        $this->assertDatabaseCount('class_training_card_values', 1);
        $this->assertSame('INST-2', $this->topic->cardValues()->first()->value);
    }

    public function test_an_empty_value_clears_the_answer(): void
    {
        // Cleared means "fall back to the training default", which is the
        // absence of a row — not an empty string stored on top of it.
        $this->save([$this->trainerId->id => 'INST-4471'])->assertOk();

        $json = $this->save([$this->trainerId->id => ''])->assertOk()->json();

        $this->assertDatabaseCount('class_training_card_values', 0);
        $this->assertNull($this->fieldsFromDetail($json)[0]['value']);
    }

    public function test_a_null_value_clears_the_answer(): void
    {
        $this->save([$this->trainerId->id => 'INST-4471'])->assertOk();

        $this->save([$this->trainerId->id => null])->assertOk();

        $this->assertDatabaseCount('class_training_card_values', 0);
    }

    public function test_fields_not_mentioned_are_left_alone(): void
    {
        // The modal may send one field at a time; an absent key is "no
        // change", not "clear".
        $notes = CardField::factory()->for($this->training)->rich()->create(['key' => 'notes', 'seq' => 1]);

        $this->save([$this->trainerId->id => 'INST-4471'])->assertOk();
        $this->save([$notes->id => 'Signed off'])->assertOk();

        $this->assertDatabaseCount('class_training_card_values', 2);
        $this->assertSame(
            'INST-4471',
            $this->topic->cardValues()->where('card_field_id', $this->trainerId->id)->first()->value,
        );
    }

    public function test_two_topics_keep_their_answers_apart(): void
    {
        // The reason values hang off class_training: one class teaching First
        // Aid and Forklift has a trainer id for each.
        $forklift = Training::factory()->for($this->org, 'organization')->create();
        $secondTopic = ClassTraining::factory()
            ->for($this->class, 'trainingClass')
            ->for($forklift, 'training')
            ->create();
        $forkliftTrainer = CardField::factory()->for($forklift)->create(['key' => 'trainer_id']);

        $this->save([$this->trainerId->id => 'FIRST-AID-1'])->assertOk();

        $json = $this->actingAs($this->manager)
            ->patchJson($this->url($secondTopic), ['card_values' => [$forkliftTrainer->id => 'FORKLIFT-9']])
            ->assertOk()
            ->json();

        $this->assertSame('FIRST-AID-1', $this->fieldsFromDetail($json)[0]['value']);
        $this->assertSame('FORKLIFT-9', $this->fieldsFromDetail($json, $secondTopic->id)[0]['value']);
    }

    public function test_a_topic_lists_its_fields_in_seq_order(): void
    {
        CardField::factory()->for($this->training)->create(['key' => 'zzz_last', 'seq' => 9]);
        CardField::factory()->for($this->training)->rich()->create(['key' => 'middle', 'seq' => 1]);

        $fields = $this->fieldsFromDetail(
            $this->actingAs($this->manager)->getJson("/api/classes/{$this->class->id}")->assertOk()->json()
        );

        $this->assertSame(['trainer_id', 'middle', 'zzz_last'], array_column($fields, 'key'));
    }

    public function test_a_topic_whose_training_has_no_fields_reports_an_empty_block(): void
    {
        $bare = Training::factory()->for($this->org, 'organization')->create();
        $bareTopic = ClassTraining::factory()
            ->for($this->class, 'trainingClass')
            ->for($bare, 'training')
            ->create();

        $json = $this->actingAs($this->manager)->getJson("/api/classes/{$this->class->id}")->assertOk()->json();

        $this->assertSame([], $this->fieldsFromDetail($json, $bareTopic->id));
    }

    public function test_saving_card_values_does_not_disturb_the_topics_other_fields(): void
    {
        // The endpoint's existing contract: only touch what was sent.
        $this->topic->update(['hours' => 4.5, 'cert_title' => 'First Aid / CPR']);

        $this->save([$this->trainerId->id => 'INST-4471'])->assertOk();

        $fresh = $this->topic->fresh();
        $this->assertSame('4.50', (string) $fresh->hours);
        $this->assertSame('First Aid / CPR', $fresh->cert_title);
    }

    // ---- validation -------------------------------------------------------

    public function test_a_field_from_another_training_is_rejected(): void
    {
        // Definitions are inherited from THIS topic's training; anything else
        // would let a class answer a question it was never asked.
        $otherTraining = Training::factory()->for($this->org, 'organization')->create();
        $foreign = CardField::factory()->for($otherTraining)->create();

        $this->save([$foreign->id => 'nope'])
            ->assertStatus(422)
            ->assertJsonValidationErrors("card_values.{$foreign->id}");
    }

    public function test_an_unknown_field_id_is_rejected(): void
    {
        $this->save(['not-a-field' => 'nope'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('card_values.not-a-field');
    }

    public function test_a_short_value_is_one_line_and_capped_at_100(): void
    {
        $this->save([$this->trainerId->id => str_repeat('x', 101)])
            ->assertStatus(422)
            ->assertJsonValidationErrors("card_values.{$this->trainerId->id}");

        $this->save([$this->trainerId->id => "line one\nline two"])
            ->assertStatus(422)
            ->assertJsonValidationErrors("card_values.{$this->trainerId->id}");
    }

    public function test_a_rich_value_may_be_multiline_up_to_2000(): void
    {
        $notes = CardField::factory()->for($this->training)->rich()->create(['key' => 'notes']);

        $this->save([$notes->id => "**Authorized** for:\n\n- Sit-down\n- Stand-up"])->assertOk();

        $this->save([$notes->id => str_repeat('x', 2001)])
            ->assertStatus(422)
            ->assertJsonValidationErrors("card_values.{$notes->id}");
    }

    // ---- guards -----------------------------------------------------------

    public function test_a_completed_class_is_read_only(): void
    {
        // Confirmed behaviour: cards for a finished class mean reopening it,
        // rather than a side door that edits a closed record.
        $this->class->update(['status' => 'completed']);

        $this->save([$this->trainerId->id => 'INST-4471'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'This class is completed and read-only.');

        $this->assertDatabaseCount('class_training_card_values', 0);
    }

    public function test_a_self_view_user_cannot_enter_values(): void
    {
        $viewer = User::factory()->for($this->org, 'organization')->withRole('SelfView')->create();

        $this->save([$this->trainerId->id => 'INST-4471'], $viewer)->assertForbidden();
    }

    public function test_a_topic_from_another_class_is_not_found(): void
    {
        $otherClass = TrainingClass::factory()->for($this->org, 'organization')->create();
        $otherTopic = ClassTraining::factory()
            ->for($otherClass, 'trainingClass')
            ->for($this->training, 'training')
            ->create();

        $this->actingAs($this->manager)
            ->patchJson($this->url($otherTopic), ['card_values' => [$this->trainerId->id => 'x']])
            ->assertNotFound();
    }

    public function test_another_orgs_class_is_not_found(): void
    {
        $other = Organization::factory()->create();
        $foreignClass = TrainingClass::factory()->for($other, 'organization')->create();

        $this->actingAs($this->manager)
            ->patchJson("/api/classes/{$foreignClass->id}/trainings/{$this->topic->id}", [
                'card_values' => [$this->trainerId->id => 'x'],
            ])
            ->assertNotFound();
    }
}
