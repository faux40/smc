<?php

namespace Tests\Feature\Cards;

use App\Actions\FileClassDocument;
use App\Jobs\GenerateCardSheets;
use App\Models\Attachment;
use App\Models\CardField;
use App\Models\CardPrintRun;
use App\Models\CardStock;
use App\Models\CardTemplate;
use App\Models\ClassTraining;
use App\Models\ClassTrainingCardValue;
use App\Models\Completion;
use App\Models\Organization;
use App\Models\Training;
use App\Models\TrainingClass;
use App\Models\User;
use App\Support\Cards\CardImposer;
use App\Support\Cards\PdfNormalizer;
use App\Support\Cards\RichTextExpander;
use App\Support\Cards\RichTextMarkup;
use App\Support\DocMerge\DocumentMergeService;
use App\Support\DocMerge\PdfConverter;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use setasign\Fpdi\Fpdi;
use Symfony\Component\Process\ExecutableFinder;
use Tests\Support\BuildsPresentationFixtures;
use Tests\TestCase;

/**
 * The card print run end to end (custom-certs C4d): merge → soffice → qpdf →
 * impose → file into the class's documents.
 *
 * Runs the real toolchain rather than mocking it. The components each have
 * their own tests; what this suite is for is the chain — that the pieces are
 * wired in the right order with the right data, which is exactly what a set of
 * mocks would assume rather than verify.
 */
class GenerateCardSheetsTest extends TestCase
{
    use BuildsPresentationFixtures, RefreshDatabase;

    private Organization $org;

    private TrainingClass $class;

    private Training $training;

    private ClassTraining $topic;

    private CardStock $stock;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        if ((new ExecutableFinder)->find('soffice') === null || (new ExecutableFinder)->find('qpdf') === null) {
            $this->markTestSkipped('LibreOffice and qpdf are needed for the card pipeline.');
        }

        $this->seed(RoleSeeder::class);
        Storage::fake('linode');

        $this->org = Organization::factory()->create(['name' => 'Barritt Group']);
        $this->manager = User::factory()->for($this->org, 'organization')->withRole('Manager')->create();
        $this->class = TrainingClass::factory()->for($this->org, 'organization')->create([
            'name' => 'June Safety Day',
            'scheduled_date' => '2026-06-01',
            'status' => 'completed',
        ]);
        $this->training = Training::factory()->for($this->org, 'organization')->create([
            'name' => 'First Aid / CPR',
        ]);
        $this->topic = ClassTraining::factory()
            ->for($this->class, 'trainingClass')
            ->for($this->training, 'training')
            ->create(['training_name' => 'First Aid / CPR', 'hours' => 4]);

        // 10-up wallet stock, cells exactly the fixture card's size.
        $this->stock = CardStock::factory()->for($this->org, 'organization')->create([
            'page_width' => 612, 'page_height' => 792,
            'column_count' => 2, 'row_count' => 5,
            'card_width' => 243, 'card_height' => 153,
            'margin_left' => 63, 'margin_top' => 27,
            'gutter_x' => 0, 'gutter_y' => 0,
            'duplex_flip' => 'long_edge',
        ]);
    }

    /**
     * A card design on the linode disk, with $slides sides.
     *
     * $extraKeys go in their own frame — a design only merges the keys it
     * actually mentions, so a test about a field has to put it on the card.
     */
    private function template(int $slides = 1, array $extraKeys = []): CardTemplate
    {
        $frame = fn (string $body) => '<draw:frame svg:x="0.2in" svg:y="0.2in" svg:width="2.5in" svg:height="0.6in">'
            .'<draw:text-box><text:p>'.$body.'</text:p></draw:text-box></draw:frame>';

        $pages = [$frame('${full_name}'.implode('', array_map(
            fn (string $key) => '</text:p><text:p>${'.$key.'}',
            $extraKeys,
        )))];

        if ($slides === 2) {
            $pages[] = $frame('${trainer_id}');
        }

        $fixture = $this->makeRenderableOdpFixture($pages);
        // Unique per call, exactly as a real upload is: makeRun() builds a
        // default template even when one is passed in, and a shared path let
        // the single-sided file quietly overwrite the two-sided one.
        $path = "card-templates/{$this->org->id}/".uniqid('design_').'.odp';
        Storage::disk('linode')->put($path, file_get_contents($fixture));
        @unlink($fixture);

        return CardTemplate::factory()->for($this->org, 'organization')->create([
            'extension' => 'odp',
            'path' => $path,
            'slide_count' => $slides,
            'card_width' => 243.0,
            'card_height' => 153.0,
            'version' => 3,
        ]);
    }

    private function holder(string $first, string $last, int $certId): Completion
    {
        $student = User::factory()->for($this->org, 'organization')->create([
            'f_name' => $first, 'l_name' => $last, 'm_name' => null,
            'email' => strtolower("{$first}.{$last}@demo.local"),
        ]);

        return Completion::factory()->create([
            'org_id' => $this->org->id,
            'user_id' => $student->id,
            'module_type' => Training::class,
            'module_id' => $this->training->id,
            'class_training_id' => $this->topic->id,
            'completion_date' => '2026-06-01',
            'cert_id' => $certId,
        ]);
    }

    private function makeRun(array $overrides = []): CardPrintRun
    {
        return CardPrintRun::create(array_merge([
            'org_id' => $this->org->id,
            'class_id' => $this->class->id,
            'class_training_id' => $this->topic->id,
            'card_template_id' => $this->template()->id,
            'card_stock_id' => $this->stock->id,
            'start_cell' => 1,
            'include_backs' => false,
            'status' => 'queued',
            'requested_by' => $this->manager->id,
        ], $overrides));
    }

    private function dispatch(CardPrintRun $run): CardPrintRun
    {
        (new GenerateCardSheets($run->id))->handle(
            app(DocumentMergeService::class),
            app(PdfConverter::class),
            app(PdfNormalizer::class),
            app(CardImposer::class),
            app(FileClassDocument::class),
            app(RichTextExpander::class),
        );

        return $run->fresh();
    }

    /** Page count + size of a stored sheet PDF. */
    private function inspect(string $path): array
    {
        $local = tempnam(sys_get_temp_dir(), 'sheet').'.pdf';
        file_put_contents($local, Storage::disk('linode')->get($path));

        $reader = new Fpdi('P', 'pt');
        $pages = $reader->setSourceFile($local);
        $size = $reader->getTemplateSize($reader->importPage(1));
        @unlink($local);

        return [$pages, round($size['width']), round($size['height'])];
    }

    // ---- the happy path ---------------------------------------------------

    public function test_it_prints_a_sheet_and_files_it_with_the_class(): void
    {
        $this->holder('Sam', 'Ng', 1042);
        $this->holder('Dana', 'Abel', 1043);
        $this->holder('Lee', 'Ortiz', 1044);

        $run = $this->dispatch($this->makeRun());

        $this->assertSame('done', $run->status);
        $this->assertNull($run->error);
        $this->assertSame(3, $run->card_count);
        $this->assertSame(1, $run->sheet_count);
        $this->assertNotNull($run->front_path);
        $this->assertNull($run->back_path);
        // The design's version is pinned, so a later replace doesn't rewrite
        // what this run printed.
        $this->assertSame(3, $run->template_version);

        Storage::disk('linode')->assertExists($run->front_path);
        $this->assertSame([1, 612.0, 792.0], $this->inspect($run->front_path));

        $attachment = Attachment::query()->where('path', $run->front_path)->firstOrFail();
        $this->assertSame($this->class->id, $attachment->attachable_id);
        $this->assertSame('cards', $attachment->type);
        $this->assertStringContainsString('Cards_Front_June_Safety_Day', $attachment->filename);
        // A queue worker has no authenticated user; the requester owns it.
        $this->assertSame($this->manager->id, $attachment->uploaded_by_user_id);
    }

    public function test_the_merge_receives_each_persons_own_values(): void
    {
        // The crux of the job: a chain that ran but merged nothing would still
        // produce a plausible sheet, so capture what actually reached OpenTBS.
        // ArrayObject, not an array: the binding closure captures by value,
        // so a plain array would collect nothing here.
        $captured = new \ArrayObject;
        $this->app->bind(DocumentMergeService::class, fn () => new class($captured) extends DocumentMergeService
        {
            public function __construct(private \ArrayObject $seen) {}

            public function merge(string $templatePath, array $data, string $outputPath, array $blocks = []): string
            {
                $this->seen->append($data);

                return parent::merge($templatePath, $data, $outputPath, $blocks);
            }
        });

        $field = CardField::factory()->for($this->training)->create([
            'key' => 'trainer_id', 'default_value' => 'INST-0000',
        ]);
        ClassTrainingCardValue::create([
            'org_id' => $this->org->id,
            'class_training_id' => $this->topic->id,
            'card_field_id' => $field->id,
            'value' => 'INST-4471',
        ]);

        $this->holder('Sam', 'Ng', 1042);
        $this->holder('Dana', 'Abel', 1043);

        $run = $this->dispatch($this->makeRun());

        $this->assertSame('done', $run->status);
        $this->assertCount(2, $captured);
        // Alphabetical by last name, like the certificates.
        $this->assertSame('Dana Abel', $captured[0]['full_name']);
        $this->assertSame('Sam Ng', $captured[1]['full_name']);
        // Class answer beats the training default, on every card.
        $this->assertSame('INST-4471', $captured[0]['trainer_id']);
        $this->assertSame('INST-4471', $captured[1]['trainer_id']);
        $this->assertSame('First Aid / CPR', $captured[0]['training_name']);
    }

    public function test_a_formatted_value_reaches_the_converter_as_real_formatting(): void
    {
        /*
         * C5, and the reason the expansion is a step of this job rather than
         * of the merge: what matters is the file soffice is handed. Capture it
         * at that exact moment — after OpenTBS has substituted the value and
         * after the expander has been over it.
         */
        $seen = new \ArrayObject;
        $this->app->bind(PdfConverter::class, fn () => new class($seen) extends PdfConverter
        {
            public function __construct(private \ArrayObject $seen) {}

            public function toPdfBatch(array $paths, string $workDir): array
            {
                foreach ($paths as $path) {
                    $zip = new \ZipArchive;
                    $zip->open($path);
                    $this->seen->append((string) $zip->getFromName('content.xml'));
                    $zip->close();
                }

                return parent::toPdfBatch($paths, $workDir);
            }
        });

        $field = CardField::factory()->for($this->training)->create([
            'key' => 'endorsement', 'type' => 'rich', 'default_value' => null,
        ]);
        ClassTrainingCardValue::create([
            'org_id' => $this->org->id,
            'class_training_id' => $this->topic->id,
            'card_field_id' => $field->id,
            'value' => '**Authorized** for sit-down',
        ]);

        $this->holder('Sam', 'Ng', 1042);

        $run = $this->dispatch($this->makeRun([
            'card_template_id' => $this->template(1, ['endorsement'])->id,
        ]));

        $this->assertSame('done', $run->status);
        $this->assertCount(1, $seen);

        $content = $seen[0];

        // The bold half is a span pointing at a style the document declares.
        $this->assertMatchesRegularExpression(
            '/<text:span text:style-name="[^"]+">Authorized<\/text:span>/',
            $content,
        );
        $this->assertStringContainsString('fo:font-weight="bold"', $content);
        $this->assertStringContainsString('for sit-down', $content);

        // Neither the markers nor the markdown may survive to the print.
        $this->assertStringNotContainsString(RichTextMarkup::OPEN, $content);
        $this->assertStringNotContainsString(RichTextMarkup::CLOSE, $content);
        $this->assertStringNotContainsString('**', $content);
    }

    public function test_a_formatted_value_prints_from_a_pptx_design_too(): void
    {
        /*
         * The other half of C5's format matrix. ODP is covered above; this
         * proves the PPTX path — clone-and-amend `<a:rPr>` rather than minted
         * styles — through the same real chain, since "well-formed XML" and
         * "XML LibreOffice honours" are different claims.
         */
        $seen = new \ArrayObject;
        $this->app->bind(PdfConverter::class, fn () => new class($seen) extends PdfConverter
        {
            public function __construct(private \ArrayObject $seen) {}

            public function toPdfBatch(array $paths, string $workDir): array
            {
                foreach ($paths as $path) {
                    $zip = new \ZipArchive;
                    $zip->open($path);
                    $this->seen->append((string) $zip->getFromName('ppt/slides/slide1.xml'));
                    $zip->close();
                }

                return parent::toPdfBatch($paths, $workDir);
            }
        });

        $field = CardField::factory()->for($this->training)->create([
            'key' => 'endorsement', 'type' => 'rich', 'default_value' => null,
        ]);
        ClassTrainingCardValue::create([
            'org_id' => $this->org->id,
            'class_training_id' => $this->topic->id,
            'card_field_id' => $field->id,
            'value' => '**Authorized** for sit-down',
        ]);

        $this->holder('Sam', 'Ng', 1042);

        $fixture = $this->makeRenderablePptxFixture([
            '<a:p><a:r><a:rPr lang="en-US" sz="1200"/><a:t>${full_name}</a:t></a:r></a:p>'
            .'<a:p><a:r><a:rPr lang="en-US" sz="900"/><a:t>${endorsement}</a:t></a:r></a:p>',
        ]);
        $path = "card-templates/{$this->org->id}/".uniqid('design_').'.pptx';
        Storage::disk('linode')->put($path, file_get_contents($fixture));
        @unlink($fixture);

        $template = CardTemplate::factory()->for($this->org, 'organization')->create([
            'extension' => 'pptx',
            'path' => $path,
            'slide_count' => 1,
            'card_width' => 243.0,
            'card_height' => 153.0,
            'version' => 1,
        ]);

        $run = $this->dispatch($this->makeRun(['card_template_id' => $template->id]));

        $this->assertSame('done', $run->status);
        $this->assertNull($run->error);
        $this->assertCount(1, $seen);

        $slide = $seen[0];

        // The bold half became its own run with b="1" — and the author's
        // 9pt size survived onto it, which is the whole point of cloning.
        $this->assertMatchesRegularExpression(
            '/<a:rPr[^>]*sz="900"[^>]*b="1"[^>]*\/><a:t>Authorized<\/a:t>/',
            $slide,
        );
        $this->assertStringContainsString('<a:t> for sit-down</a:t>', $slide);
        $this->assertStringContainsString('<a:t>Sam Ng</a:t>', $slide);

        $this->assertStringNotContainsString(RichTextMarkup::OPEN, $slide);
        $this->assertStringNotContainsString(RichTextMarkup::CLOSE, $slide);
        $this->assertStringNotContainsString('**', $slide);
    }

    public function test_a_design_with_no_formatted_field_is_never_rewritten(): void
    {
        // Most cards have no rich field. Opening and rewriting every merged
        // zip for them would be work done to produce identical bytes.
        $calls = new \ArrayObject;
        $this->app->bind(RichTextExpander::class, fn () => new class($calls) extends RichTextExpander
        {
            public function __construct(private \ArrayObject $calls) {}

            public function expand(string $path, string $extension): void
            {
                $this->calls->append($path);
            }
        });

        CardField::factory()->for($this->training)->create([
            'key' => 'trainer_id', 'type' => 'short', 'default_value' => 'INST-1',
        ]);
        $this->holder('Sam', 'Ng', 1042);

        $run = $this->dispatch($this->makeRun());

        $this->assertSame('done', $run->status);
        $this->assertCount(0, $calls);
    }

    public function test_a_proof_run_prints_exactly_one_card(): void
    {
        /*
         * C6b: the real pipeline, sliced to the first person — same design,
         * same fonts, same start cell, so what comes out of the printer is
         * exactly what a full run would put in that cell. Burns one cell of
         * one sheet instead of a whole misaligned batch.
         */
        $merged = new \ArrayObject;
        $this->app->bind(DocumentMergeService::class, fn () => new class($merged) extends DocumentMergeService
        {
            public function __construct(private \ArrayObject $seen) {}

            public function merge(string $templatePath, array $data, string $outputPath, array $blocks = []): string
            {
                $this->seen->append($data);

                return parent::merge($templatePath, $data, $outputPath, $blocks);
            }
        });

        $this->holder('Sam', 'Ng', 1042);
        $this->holder('Dana', 'Abel', 1043);
        $this->holder('Lee', 'Ortiz', 1044);

        $run = $this->dispatch($this->makeRun(['proof' => true, 'start_cell' => 7]));

        $this->assertSame('done', $run->status);
        $this->assertSame(1, $run->card_count);
        $this->assertSame(1, $run->sheet_count);

        // One merge, and it's the FIRST card in print order (last-name sort,
        // as the certificates collate) — the proof is card #1 of the real
        // run, not an arbitrary person.
        $this->assertCount(1, $merged);
        $this->assertSame('Dana Abel', $merged[0]['full_name']);
    }

    public function test_a_long_roster_spills_onto_more_sheets(): void
    {
        foreach (range(1, 12) as $i) {
            $this->holder('P'.$i, 'Person'.str_pad((string) $i, 2, '0', STR_PAD_LEFT), 2000 + $i);
        }

        $run = $this->dispatch($this->makeRun());

        $this->assertSame(12, $run->card_count);
        $this->assertSame(2, $run->sheet_count);
        $this->assertSame(2, $this->inspect($run->front_path)[0]);
    }

    public function test_a_partial_sheet_starts_where_it_was_left(): void
    {
        // Eight cards from cell 4 needs a second sheet: 7 fit, 1 spills.
        foreach (range(1, 8) as $i) {
            $this->holder('P'.$i, 'Person'.str_pad((string) $i, 2, '0', STR_PAD_LEFT), 3000 + $i);
        }

        $run = $this->dispatch($this->makeRun(['start_cell' => 4]));

        $this->assertSame(2, $run->sheet_count);
    }

    // ---- backs ------------------------------------------------------------

    public function test_backs_are_filed_as_their_own_pdf_sharing_the_run_stamp(): void
    {
        $this->holder('Sam', 'Ng', 1042);

        $run = $this->dispatch($this->makeRun([
            'card_template_id' => $this->template(slides: 2)->id,
            'include_backs' => true,
        ]));

        $this->assertSame('done', $run->status, (string) $run->error);
        $this->assertNotNull($run->back_path);
        Storage::disk('linode')->assertExists($run->back_path);

        $filenames = Attachment::query()->pluck('filename');
        $this->assertCount(2, $filenames);
        // One stamp across both: a fronts file must never pair with another
        // run's backs.
        $this->assertStringContainsString("Cards_Front_June_Safety_Day_{$run->run_stamp}", $filenames[0]);
        $this->assertStringContainsString("Cards_Back_June_Safety_Day_{$run->run_stamp}", $filenames[1]);
    }

    public function test_asking_for_backs_on_a_single_sided_design_just_prints_fronts(): void
    {
        // A no-op, not an error: the design simply has no second side.
        $this->holder('Sam', 'Ng', 1042);

        $run = $this->dispatch($this->makeRun(['include_backs' => true]));

        $this->assertSame('done', $run->status);
        $this->assertNull($run->back_path);
        $this->assertSame(1, Attachment::query()->count());
    }

    public function test_a_two_sided_design_prints_fronts_only_when_backs_are_not_asked_for(): void
    {
        $this->holder('Sam', 'Ng', 1042);

        $run = $this->dispatch($this->makeRun([
            'card_template_id' => $this->template(slides: 2)->id,
            'include_backs' => false,
        ]));

        $this->assertNull($run->back_path);
        $this->assertSame(1, Attachment::query()->count());
    }

    // ---- failure ----------------------------------------------------------

    public function test_a_topic_nobody_completed_fails_with_a_reason(): void
    {
        $run = $this->dispatch($this->makeRun());

        $this->assertSame('failed', $run->status);
        $this->assertStringContainsString('Nobody on this class holds a certificate', $run->error);
        $this->assertSame(0, Attachment::query()->count());
    }

    public function test_a_missing_stock_fails_with_a_reason(): void
    {
        $this->holder('Sam', 'Ng', 1042);
        $run = $this->makeRun();
        $this->stock->forceDelete();

        $run = $this->dispatch($run);

        $this->assertSame('failed', $run->status);
        $this->assertStringContainsString('card stock', $run->error);
    }

    public function test_a_deleted_run_is_ignored(): void
    {
        $run = $this->makeRun();
        $id = $run->id;
        $run->delete();

        (new GenerateCardSheets($id))->handle(
            app(DocumentMergeService::class),
            app(PdfConverter::class),
            app(PdfNormalizer::class),
            app(CardImposer::class),
            app(FileClassDocument::class),
            app(RichTextExpander::class),
        );

        $this->assertSame(0, Attachment::query()->count());
    }

    public function test_it_leaves_no_working_files_behind(): void
    {
        $this->holder('Sam', 'Ng', 1042);

        $run = $this->dispatch($this->makeRun());

        $this->assertDirectoryDoesNotExist(sys_get_temp_dir().'/cards_'.$run->id);
    }
}
