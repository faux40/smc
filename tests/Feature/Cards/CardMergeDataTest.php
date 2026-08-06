<?php

namespace Tests\Feature\Cards;

use App\Models\CardField;
use App\Models\ClassTraining;
use App\Models\ClassTrainingCardValue;
use App\Models\Completion;
use App\Models\Organization;
use App\Models\Training;
use App\Models\TrainingClass;
use App\Models\User;
use App\Support\Cards\CardMergeData;
use App\Support\Cards\CardMergeKeys;
use App\Support\Cards\RichTextMarkup;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The values behind a card's `${keys}` (custom-certs C4a) — one row per person
 * who earned the credit, resolved from the person, the class, the frozen topic
 * snapshot, the credit itself, the org, and the training's custom fields.
 *
 * Everything a card can print comes from here, so this is also what makes the
 * catalogue in {@see CardMergeKeys} a promise rather than a list.
 */
class CardMergeDataTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private TrainingClass $class;

    private Training $training;

    private ClassTraining $topic;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);

        $this->org = Organization::factory()->create(['name' => 'Barritt Group']);
        $this->class = TrainingClass::factory()->for($this->org, 'organization')->create([
            'name' => 'June Safety Day',
            'scheduled_date' => '2026-06-01',
            'start_time' => '08:00',
            'end_time' => '12:00',
            'location' => 'North Yard',
            'address' => '12 Mill Rd',
            'instructor' => 'Dana Reed',
            'status' => 'completed',
            'completion_date' => '2026-06-01',
        ]);
        $this->training = Training::factory()->for($this->org, 'organization')->create([
            'name' => 'First Aid / CPR',
        ]);
        $this->topic = ClassTraining::factory()
            ->for($this->class, 'trainingClass')
            ->for($this->training, 'training')
            ->create([
                'training_name' => 'First Aid / CPR',
                'cert_code' => 'FA-CPR',
                'hours' => 4,
                'repeating' => true,
                'repeat_days' => 730,
            ]);
    }

    /** A person who earned this topic's credit. */
    private function holder(array $user = [], array $completion = []): Completion
    {
        $student = User::factory()->for($this->org, 'organization')->create(array_merge([
            'f_name' => 'Sam',
            'm_name' => 'Lee',
            'l_name' => 'Ng',
            'email' => 'sam.ng@demo.local',
            'employee_number' => 'E-1001',
        ], $user));

        return Completion::factory()->create(array_merge([
            'org_id' => $this->org->id,
            'user_id' => $student->id,
            'module_type' => Training::class,
            'module_id' => $this->training->id,
            'class_training_id' => $this->topic->id,
            'completion_date' => '2026-06-01',
            'expire_date' => '2028-05-31',
            'cert_id' => 1042,
            'hours' => 4,
        ], $completion));
    }

    private function field(array $attrs): CardField
    {
        return CardField::factory()->for($this->training)->create($attrs);
    }

    private function answer(CardField $field, string $value): void
    {
        ClassTrainingCardValue::create([
            'org_id' => $this->org->id,
            'class_training_id' => $this->topic->id,
            'card_field_id' => $field->id,
            'value' => $value,
        ]);
    }

    // ---- who gets a row ---------------------------------------------------

    public function test_one_row_per_person_who_earned_the_credit(): void
    {
        $this->holder(['f_name' => 'Sam', 'l_name' => 'Ng']);
        $this->holder(['f_name' => 'Dana', 'l_name' => 'Abel', 'email' => 'd@demo.local']);

        $rows = CardMergeData::forTopic($this->topic);

        $this->assertCount(2, $rows);
        // Alphabetical by last, first, middle — the certificate print order, so
        // a stack of cards collates with the stack of certificates.
        $this->assertSame(['Abel', 'Ng'], array_column($rows, 'last_name'));
    }

    public function test_someone_without_an_issued_certificate_gets_no_card(): void
    {
        // Same rule as certificates: cert_id is what "issued" means.
        $this->holder();
        $this->holder(['email' => 'no.cert@demo.local'], ['cert_id' => null]);

        $this->assertCount(1, CardMergeData::forTopic($this->topic));
    }

    public function test_another_topics_holders_are_not_included(): void
    {
        $forklift = Training::factory()->for($this->org, 'organization')->create();
        $otherTopic = ClassTraining::factory()
            ->for($this->class, 'trainingClass')
            ->for($forklift, 'training')
            ->create();

        $this->holder();

        $student = User::factory()->for($this->org, 'organization')->create();
        Completion::factory()->create([
            'org_id' => $this->org->id,
            'user_id' => $student->id,
            'module_type' => Training::class,
            'module_id' => $forklift->id,
            'class_training_id' => $otherTopic->id,
            'completion_date' => '2026-06-01',
            'cert_id' => 2001,
        ]);

        $rows = CardMergeData::forTopic($this->topic);

        $this->assertCount(1, $rows);
        $this->assertSame('1042', $rows[0]['cert_id']);
    }

    // ---- the built-in vocabulary ------------------------------------------

    public function test_the_row_holds_exactly_the_catalogue_plus_the_custom_keys(): void
    {
        // The contract that keeps CardMergeKeys honest: a key the catalogue
        // advertises but nothing fills would print blank, and a key filled but
        // not advertised is undiscoverable.
        $this->field(['key' => 'trainer_id']);
        $this->holder();

        $row = CardMergeData::forTopic($this->topic)[0];

        $expected = [...CardMergeKeys::all(), 'trainer_id'];
        sort($expected);
        $actual = array_keys($row);
        sort($actual);

        $this->assertSame($expected, $actual);
    }

    public function test_person_values(): void
    {
        $this->holder([
            'f_name' => 'Sam',
            'm_name' => 'Lee',
            'l_name' => 'Ng',
            'email' => 'sam.ng@demo.local',
            'employee_number' => 'E-1001',
        ]);

        $row = CardMergeData::forTopic($this->topic)[0];

        $this->assertSame('Sam', $row['first_name']);
        $this->assertSame('Lee', $row['middle_name']);
        $this->assertSame('Ng', $row['last_name']);
        $this->assertSame('Sam Lee Ng', $row['full_name']);
        $this->assertSame('E-1001', $row['employee_number']);
        $this->assertSame('sam.ng@demo.local', $row['email']);
    }

    public function test_class_values(): void
    {
        $this->holder();

        $row = CardMergeData::forTopic($this->topic)[0];

        $this->assertSame('June Safety Day', $row['class_name']);
        $this->assertSame('06/01/2026', $row['class_date']);
        $this->assertSame('North Yard', $row['class_location']);
        $this->assertSame('12 Mill Rd', $row['class_address']);
        $this->assertSame('Dana Reed', $row['instructor']);
        $this->assertSame('8:00 AM', $row['start_time']);
        $this->assertSame('12:00 PM', $row['end_time']);
    }

    public function test_training_values_come_from_the_frozen_topic_snapshot(): void
    {
        // Renaming the training later must not change what a reprint says,
        // for the same reason certificates read the snapshot.
        $this->holder();
        $this->training->update(['name' => 'Renamed Course']);

        $row = CardMergeData::forTopic($this->topic->fresh())[0];

        $this->assertSame('First Aid / CPR', $row['training_name']);
        $this->assertSame('FA-CPR', $row['cert_code']);
    }

    public function test_cert_title_prints_the_snapshot_cert_title(): void
    {
        // The training-level "what the paper says" knob, snapshotted onto the
        // topic like cert_text/cert_code — cards get the same key certificates
        // already honour.
        $this->topic->update(['cert_title' => 'First Aid, CPR & AED — ANSI Z308.1']);
        $this->holder();

        $row = CardMergeData::forTopic($this->topic->fresh())[0];

        $this->assertSame('First Aid, CPR & AED — ANSI Z308.1', $row['cert_title']);
    }

    public function test_cert_title_falls_back_to_the_frozen_training_name(): void
    {
        // No snapshot cert_title → the frozen topic name, NOT the live
        // training's current name or its current cert_title. A rename (or a
        // cert_title added later) must never rewrite what an old class prints
        // — the trap that bit the requirement elements.
        $this->holder();
        $this->training->update([
            'name' => 'Renamed Course',
            'cert_title' => 'A Title Added Long After The Class Ran',
        ]);

        $row = CardMergeData::forTopic($this->topic->fresh())[0];

        $this->assertSame('First Aid / CPR', $row['cert_title']);
    }

    public function test_hours_print_without_trailing_zeros(): void
    {
        // "4.00 hours" on a wallet card reads like a bug; 4.5 must survive.
        $this->holder([], ['hours' => 4]);
        $this->holder(['email' => 'half@demo.local'], ['hours' => 4.5, 'cert_id' => 1043]);

        $rows = collect(CardMergeData::forTopic($this->topic))->keyBy('email');

        $this->assertSame('4', $rows['sam.ng@demo.local']['hours']);
        $this->assertSame('4.5', $rows['half@demo.local']['hours']);
    }

    public function test_hours_fall_back_to_the_topic_allocation(): void
    {
        $this->holder([], ['hours' => null]);

        $this->assertSame('4', CardMergeData::forTopic($this->topic)[0]['hours']);
    }

    public function test_credit_values(): void
    {
        $this->holder();

        $row = CardMergeData::forTopic($this->topic)[0];

        $this->assertSame('1042', $row['cert_id']);
        $this->assertSame('06/01/2026', $row['completion_date']);
        $this->assertSame('05/31/2028', $row['expire_date']);
    }

    public function test_an_unstamped_expiry_falls_back_to_the_topics_frequency(): void
    {
        // Same fallback certificates use for records that never had one
        // stamped: completion date + the snapshot's repeat interval.
        $this->holder([], ['expire_date' => null]);

        $this->assertSame('05/31/2028', CardMergeData::forTopic($this->topic)[0]['expire_date']);
    }

    public function test_org_and_today(): void
    {
        $this->holder();

        $row = CardMergeData::forTopic($this->topic)[0];

        $this->assertSame('Barritt Group', $row['org_name']);
        $this->assertSame(
            now(config('app.display_timezone'))->format('m/d/Y'),
            $row['today'],
        );
    }

    public function test_every_value_is_a_string_even_when_absent(): void
    {
        // A null reaching the merge prints "null" or leaves ${key} showing on
        // purchased stock.
        $bare = TrainingClass::factory()->for($this->org, 'organization')->create([
            'name' => 'Bare Class',
            // scheduled_date is NOT NULL — a class always has a date. Every
            // other field here is genuinely optional.
            'scheduled_date' => '2026-06-01',
            'start_time' => null,
            'end_time' => null,
            'location' => null,
            'address' => null,
            'instructor' => null,
        ]);
        $bareTopic = ClassTraining::factory()
            ->for($bare, 'trainingClass')
            ->for($this->training, 'training')
            ->create(['cert_code' => null, 'hours' => null, 'repeating' => false, 'repeat_days' => null]);

        $student = User::factory()->for($this->org, 'organization')->create([
            'm_name' => null,
            'employee_number' => null,
        ]);
        Completion::factory()->create([
            'org_id' => $this->org->id,
            'user_id' => $student->id,
            'module_type' => Training::class,
            'module_id' => $this->training->id,
            'class_training_id' => $bareTopic->id,
            'completion_date' => '2026-06-01',
            'expire_date' => null,
            'cert_id' => 7,
            'hours' => null,
        ]);

        $row = CardMergeData::forTopic($bareTopic)[0];

        foreach ($row as $key => $value) {
            $this->assertIsString($value, "{$key} is not a string");
        }
        $this->assertSame('', $row['middle_name']);
        $this->assertSame('', $row['start_time']);
        $this->assertSame('', $row['class_location']);
        $this->assertSame('', $row['cert_code']);
        $this->assertSame('', $row['expire_date']);
        $this->assertSame('', $row['hours']);
    }

    // ---- custom fields ----------------------------------------------------

    public function test_a_custom_field_uses_this_classs_answer(): void
    {
        $field = $this->field(['key' => 'trainer_id', 'default_value' => 'INST-0000']);
        $this->answer($field, 'INST-4471');
        $this->holder();

        $this->assertSame('INST-4471', CardMergeData::forTopic($this->topic)[0]['trainer_id']);
    }

    public function test_a_custom_field_falls_back_to_the_trainings_default(): void
    {
        $this->field(['key' => 'trainer_id', 'default_value' => 'INST-0000']);
        $this->holder();

        $this->assertSame('INST-0000', CardMergeData::forTopic($this->topic)[0]['trainer_id']);
    }

    public function test_a_custom_field_with_neither_prints_blank(): void
    {
        $this->field(['key' => 'trainer_id', 'default_value' => null]);
        $this->holder();

        $this->assertSame('', CardMergeData::forTopic($this->topic)[0]['trainer_id']);
    }

    public function test_a_formatted_field_reaches_the_merge_as_marked_up_markdown(): void
    {
        /*
         * C5: the markdown now travels intact to the merge, wrapped in
         * markers, and RichTextExpander turns it into real runs afterwards.
         * It used to be flattened to plain text here, which threw the
         * formatting away before anything could act on it.
         */
        $field = $this->field(['key' => 'endorsement', 'type' => 'rich']);
        $this->answer($field, '**Authorized** for sit-down');
        $this->holder();

        $value = CardMergeData::forTopic($this->topic)[0]['endorsement'];

        $this->assertSame(
            RichTextMarkup::OPEN.'**Authorized** for sit-down'.RichTextMarkup::CLOSE,
            $value,
        );
    }

    public function test_a_plain_field_is_never_marked(): void
    {
        // Only `rich` fields get the treatment; marking a short field would
        // put the pass to work for nothing and risk a stray marker printing.
        $field = $this->field(['key' => 'trainer_id', 'type' => 'short']);
        $this->answer($field, '**INST-1**');
        $this->holder();

        $this->assertSame(
            '**INST-1**',
            CardMergeData::forTopic($this->topic)[0]['trainer_id'],
        );
    }

    public function test_an_empty_formatted_field_carries_no_marker(): void
    {
        /*
         * The common case — a card defines a formatted field and most classes
         * leave it blank. An empty pair of markers would be an empty run for
         * the expander to place, and a stray marker on the card if anything
         * downstream ever skipped the pass.
         */
        $this->field(['key' => 'endorsement', 'type' => 'rich', 'default_value' => null]);
        $this->holder();

        $this->assertSame('', CardMergeData::forTopic($this->topic)[0]['endorsement']);
    }

    public function test_a_marker_character_inside_a_value_cannot_confuse_the_pass(): void
    {
        // Nobody types a private-use character, but a paste from somewhere odd
        // could carry one, and an unbalanced marker would leave the expander
        // matching across the wrong stretch of text.
        $field = $this->field(['key' => 'endorsement', 'type' => 'rich']);
        $this->answer($field, 'safe'.RichTextMarkup::CLOSE.'ty');
        $this->holder();

        $this->assertSame(
            RichTextMarkup::OPEN.'safety'.RichTextMarkup::CLOSE,
            CardMergeData::forTopic($this->topic)[0]['endorsement'],
        );
    }

    public function test_a_custom_key_cannot_be_overwritten_by_the_catalogue(): void
    {
        // C3 rejects a reserved key, so this can only happen if a key is added
        // to the catalogue later. The built-in must win, and the row must not
        // silently gain a second meaning.
        $this->holder();

        $row = CardMergeData::forTopic($this->topic)[0];

        $this->assertSame('Sam', $row['first_name']);
    }
}
