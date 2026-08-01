<?php

namespace Tests\Feature\Cards;

use App\Jobs\GenerateCardSheets;
use App\Models\Attachment;
use App\Models\CardPrintRun;
use App\Models\CardStock;
use App\Models\CardTemplate;
use App\Models\ClassTraining;
use App\Models\Organization;
use App\Models\Training;
use App\Models\TrainingClass;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Asking for a topic's cards (custom-certs C4d). The request only chooses
 * *what* to print — design, stock, where on the sheet to start — and the
 * queued job does the work, so this endpoint's job is to reject an
 * unprintable combination before anything is queued.
 */
class CardPrintRunsApiTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $manager;

    private TrainingClass $class;

    private Training $training;

    private ClassTraining $topic;

    private CardTemplate $template;

    private CardStock $stock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        Queue::fake();

        $this->org = Organization::factory()->create();
        $this->manager = User::factory()->for($this->org, 'organization')->withRole('Manager')->create();
        $this->class = TrainingClass::factory()->for($this->org, 'organization')->create(['status' => 'completed']);
        $this->template = CardTemplate::factory()->for($this->org, 'organization')->create();
        $this->training = Training::factory()->for($this->org, 'organization')->create([
            'card_template_id' => $this->template->id,
        ]);
        $this->topic = ClassTraining::factory()
            ->for($this->class, 'trainingClass')
            ->for($this->training, 'training')
            ->create();
        // 2 x 5 = 10 cells.
        $this->stock = CardStock::factory()->for($this->org, 'organization')->create([
            'column_count' => 2, 'row_count' => 5,
        ]);
    }

    private function url(?TrainingClass $class = null): string
    {
        return '/api/classes/'.($class ?? $this->class)->id.'/card-runs';
    }

    /** A run row as the job would have left it. */
    private function makeRun(array $overrides = []): CardPrintRun
    {
        return CardPrintRun::create(array_merge([
            'org_id' => $this->org->id,
            'class_id' => $this->class->id,
            'class_training_id' => $this->topic->id,
            'card_template_id' => $this->template->id,
            'card_stock_id' => $this->stock->id,
            'start_cell' => 1,
            'status' => 'done',
        ], $overrides));
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'class_training_id' => $this->topic->id,
            'card_stock_id' => $this->stock->id,
            'start_cell' => 1,
            'include_backs' => false,
        ], $overrides);
    }

    private function requestRun(array $overrides = [], ?User $actor = null)
    {
        return $this->actingAs($actor ?? $this->manager)
            ->postJson($this->url(), $this->payload($overrides));
    }

    // ---- requesting a run -------------------------------------------------

    public function test_a_manager_queues_a_run(): void
    {
        $this->requestRun()
            ->assertStatus(202)
            ->assertJsonPath('status', 'queued')
            ->assertJsonPath('include_backs', false);

        $run = CardPrintRun::query()->sole();
        $this->assertSame($this->org->id, $run->org_id);
        $this->assertSame($this->class->id, $run->class_id);
        $this->assertSame($this->topic->id, $run->class_training_id);
        $this->assertSame($this->manager->id, $run->requested_by);

        Queue::assertPushed(
            GenerateCardSheets::class,
            fn (GenerateCardSheets $job) => $job->cardPrintRunId === $run->id,
        );
    }

    public function test_a_proof_run_is_stored_as_one(): void
    {
        // C6b: one real card before committing a sheet of stock. The flag
        // must persist — the queued job reads the run row, not the request.
        $this->requestRun(['proof' => true])
            ->assertStatus(202)
            ->assertJsonPath('proof', true);

        $this->assertTrue(CardPrintRun::query()->sole()->proof);
    }

    public function test_a_run_is_not_a_proof_unless_asked(): void
    {
        $this->requestRun()
            ->assertStatus(202)
            ->assertJsonPath('proof', false);

        $this->assertFalse(CardPrintRun::query()->sole()->proof);
    }

    public function test_it_prints_the_trainings_own_design_by_default(): void
    {
        $this->requestRun()->assertStatus(202);

        $this->assertSame($this->template->id, CardPrintRun::query()->sole()->card_template_id);
    }

    public function test_a_different_design_can_be_chosen_for_one_run(): void
    {
        // The print-time override C2 planned for: same class, a different
        // card stock-and-design combination this once.
        $other = CardTemplate::factory()->for($this->org, 'organization')->create();

        $this->requestRun(['card_template_id' => $other->id])->assertStatus(202);

        $this->assertSame($other->id, CardPrintRun::query()->sole()->card_template_id);
    }

    public function test_a_system_design_can_be_chosen(): void
    {
        $system = CardTemplate::factory()->system()->create();

        $this->requestRun(['card_template_id' => $system->id])->assertStatus(202);

        $this->assertSame($system->id, CardPrintRun::query()->sole()->card_template_id);
    }

    public function test_a_completed_class_is_exactly_when_cards_are_printed(): void
    {
        // The C3 read-only rule covers *editing* a closed class. Printing from
        // one is the main case, like certificates.
        $this->assertSame('completed', $this->class->status);

        $this->requestRun()->assertStatus(202);
    }

    // ---- rejecting the unprintable ----------------------------------------

    public function test_a_training_with_no_design_cannot_print(): void
    {
        $this->training->update(['card_template_id' => null]);

        $this->requestRun()
            ->assertStatus(422)
            ->assertJsonValidationErrors('card_template_id');

        Queue::assertNothingPushed();
    }

    public function test_a_topic_from_another_class_is_rejected(): void
    {
        $otherClass = TrainingClass::factory()->for($this->org, 'organization')->create();
        $otherTopic = ClassTraining::factory()
            ->for($otherClass, 'trainingClass')
            ->for($this->training, 'training')
            ->create();

        $this->requestRun(['class_training_id' => $otherTopic->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('class_training_id');
    }

    public function test_another_orgs_design_or_stock_is_rejected(): void
    {
        $other = Organization::factory()->create();

        $this->requestRun(['card_template_id' => CardTemplate::factory()->for($other, 'organization')->create()->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('card_template_id');

        $this->requestRun(['card_stock_id' => CardStock::factory()->for($other, 'organization')->create()->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('card_stock_id');
    }

    public function test_a_start_cell_beyond_the_sheet_is_rejected(): void
    {
        // Caught here rather than as an exception inside the queued job, where
        // the user would only see it as a failed run.
        $this->requestRun(['start_cell' => 11])
            ->assertStatus(422)
            ->assertJsonValidationErrors('start_cell');

        $this->requestRun(['start_cell' => 10])->assertStatus(202);
    }

    public function test_a_start_cell_below_one_is_rejected(): void
    {
        $this->requestRun(['start_cell' => 0])
            ->assertStatus(422)
            ->assertJsonValidationErrors('start_cell');
    }

    // ---- listing ----------------------------------------------------------

    public function test_the_class_lists_its_runs_newest_first(): void
    {
        $older = CardPrintRun::create([
            'org_id' => $this->org->id,
            'class_id' => $this->class->id,
            'class_training_id' => $this->topic->id,
            'card_template_id' => $this->template->id,
            'card_stock_id' => $this->stock->id,
            'status' => 'done',
            'card_count' => 3,
            'sheet_count' => 1,
            'created_at' => now()->subHour(),
        ]);
        $newer = CardPrintRun::create([
            'org_id' => $this->org->id,
            'class_id' => $this->class->id,
            'class_training_id' => $this->topic->id,
            'card_template_id' => $this->template->id,
            'card_stock_id' => $this->stock->id,
            'status' => 'failed',
            'error' => 'Nobody on this class holds a certificate for “First Aid”.',
        ]);

        $this->actingAs($this->manager)
            ->getJson($this->url())
            ->assertOk()
            ->assertJsonCount(2)
            ->assertJsonPath('0.id', $newer->id)
            ->assertJsonPath('0.status', 'failed')
            // The reason a run failed is the whole point of showing runs.
            ->assertJsonPath('0.error', 'Nobody on this class holds a certificate for “First Aid”.')
            ->assertJsonPath('1.id', $older->id)
            ->assertJsonPath('1.card_count', 3);
    }

    // ---- clearing a run ---------------------------------------------------

    public function test_a_run_can_be_cleared_from_the_list(): void
    {
        $run = $this->makeRun(['status' => 'failed', 'error' => 'No design.']);

        $this->actingAs($this->manager)
            ->deleteJson($this->url().'/'.$run->id)
            ->assertOk();

        $this->assertDatabaseMissing('card_print_runs', ['id' => $run->id]);
    }

    public function test_clearing_a_run_leaves_the_sheets_it_filed(): void
    {
        /*
         * The record goes, the documents stay. The sheets are filed as class
         * documents with their own delete, and they are the printed artifact
         * — dismissing the note that a run happened must not take them with
         * it, or a tidy-up quietly destroys the audit trail.
         */
        $attachment = Attachment::create([
            'org_id' => $this->org->id,
            'attachable_type' => TrainingClass::class,
            'attachable_id' => $this->class->id,
            'filename' => 'Cards_Front.pdf',
            'disk' => 'linode',
            'path' => 'class-documents/front.pdf',
            'type' => 'cards',
            'uploaded_by_user_id' => $this->manager->id,
        ]);
        $run = $this->makeRun(['status' => 'done', 'front_path' => $attachment->path]);

        $this->actingAs($this->manager)
            ->deleteJson($this->url().'/'.$run->id)
            ->assertOk();

        $this->assertDatabaseHas('attachments', ['id' => $attachment->id]);
    }

    public function test_another_classs_run_cannot_be_cleared_through_this_class(): void
    {
        $otherClass = TrainingClass::factory()->for($this->org, 'organization')->create();
        $otherTopic = ClassTraining::factory()
            ->for($otherClass, 'trainingClass')
            ->for($this->training, 'training')
            ->create();
        $foreign = $this->makeRun([
            'class_id' => $otherClass->id,
            'class_training_id' => $otherTopic->id,
        ]);

        $this->actingAs($this->manager)
            ->deleteJson($this->url().'/'.$foreign->id)
            ->assertNotFound();

        $this->assertDatabaseHas('card_print_runs', ['id' => $foreign->id]);
    }

    public function test_a_self_view_user_cannot_clear_a_run(): void
    {
        $viewer = User::factory()->for($this->org, 'organization')->withRole('SelfView')->create();
        $run = $this->makeRun();

        $this->actingAs($viewer)
            ->deleteJson($this->url().'/'.$run->id)
            ->assertForbidden();
    }

    public function test_another_classs_runs_are_not_listed(): void
    {
        $otherClass = TrainingClass::factory()->for($this->org, 'organization')->create();
        $otherTopic = ClassTraining::factory()
            ->for($otherClass, 'trainingClass')
            ->for($this->training, 'training')
            ->create();
        CardPrintRun::create([
            'org_id' => $this->org->id,
            'class_id' => $otherClass->id,
            'class_training_id' => $otherTopic->id,
            'status' => 'done',
        ]);

        $this->actingAs($this->manager)->getJson($this->url())->assertOk()->assertJsonCount(0);
    }

    // ---- authorization ----------------------------------------------------

    public function test_a_self_view_user_cannot_print_or_list(): void
    {
        $viewer = User::factory()->for($this->org, 'organization')->withRole('SelfView')->create();

        $this->requestRun([], $viewer)->assertForbidden();
        $this->actingAs($viewer)->getJson($this->url())->assertForbidden();
    }

    public function test_another_orgs_class_is_not_found(): void
    {
        $other = Organization::factory()->create();
        $foreign = TrainingClass::factory()->for($other, 'organization')->create();

        $this->actingAs($this->manager)
            ->postJson($this->url($foreign), $this->payload())
            ->assertNotFound();
    }

    public function test_a_guest_is_rejected(): void
    {
        $this->postJson($this->url(), $this->payload())->assertUnauthorized();
    }
}
