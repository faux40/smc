<?php

namespace Tests\Feature\Cards;

use App\Actions\FileClassDocument;
use App\Events\AttachmentCreated;
use App\Models\Organization;
use App\Models\TrainingClass;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Filing an already-written file into a class's documents (custom-certs C4d).
 *
 * The existing handle() takes a PdfBuilder because certificates and summaries
 * are rendered on the spot. Card sheets arrive as a finished PDF on disk — the
 * queued job merged, converted and imposed it — so they need the same
 * attachment plumbing fed from a path instead.
 */
class FileClassDocumentFromPathTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    private Organization $org;

    private TrainingClass $class;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        Storage::fake('linode');

        $this->dir = sys_get_temp_dir().'/filing_'.uniqid();
        mkdir($this->dir, 0700, true);

        $this->org = Organization::factory()->create();
        $this->class = TrainingClass::factory()->for($this->org, 'organization')->create([
            'name' => 'June Safety Day',
        ]);
    }

    protected function tearDown(): void
    {
        foreach (glob("{$this->dir}/*") ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);

        parent::tearDown();
    }

    private function sourceFile(string $contents = '%PDF-1.4 fake'): string
    {
        $path = "{$this->dir}/sheet.pdf";
        file_put_contents($path, $contents);

        return $path;
    }

    public function test_it_stores_the_file_and_records_the_attachment(): void
    {
        $actor = User::factory()->for($this->org, 'organization')->withRole('Manager')->create();
        $this->actingAs($actor);

        $attachment = app(FileClassDocument::class)->fromPath(
            $this->class,
            $this->sourceFile(),
            'Cards_Front_June_Safety_Day_20260729_1432.pdf',
            'cards',
            'First Aid / CPR — fronts',
        );

        Storage::disk('linode')->assertExists($attachment->path);
        $this->assertSame($this->org->id, $attachment->org_id);
        $this->assertSame(TrainingClass::class, $attachment->attachable_type);
        $this->assertSame($this->class->id, $attachment->attachable_id);
        $this->assertSame('Cards_Front_June_Safety_Day_20260729_1432.pdf', $attachment->filename);
        $this->assertSame('cards', $attachment->type);
        $this->assertSame('First Aid / CPR — fronts', $attachment->description);
        $this->assertSame('application/pdf', $attachment->mime);
    }

    public function test_the_stored_bytes_are_the_files_bytes(): void
    {
        $this->actingAs(User::factory()->for($this->org, 'organization')->withRole('Manager')->create());

        $attachment = app(FileClassDocument::class)->fromPath(
            $this->class,
            $this->sourceFile('%PDF-1.4 sheet one'),
            'Cards.pdf',
        );

        $this->assertSame(
            '%PDF-1.4 sheet one',
            Storage::disk('linode')->get($attachment->path),
        );
    }

    public function test_it_records_the_size_it_actually_stored(): void
    {
        // handle() leaves size null because a PdfBuilder hasn't written yet;
        // here the bytes exist, so the documents list can show a real size.
        $this->actingAs(User::factory()->for($this->org, 'organization')->withRole('Manager')->create());

        $attachment = app(FileClassDocument::class)->fromPath(
            $this->class,
            $this->sourceFile('%PDF-1.4 twenty-nine.'),
            'Cards.pdf',
        );

        $this->assertSame(strlen('%PDF-1.4 twenty-nine.'), $attachment->size);
    }

    public function test_repeated_filings_never_collide(): void
    {
        // Reprints are normal: a second run of the same cards must not
        // overwrite the first.
        $this->actingAs(User::factory()->for($this->org, 'organization')->withRole('Manager')->create());

        $first = app(FileClassDocument::class)->fromPath($this->class, $this->sourceFile(), 'Cards.pdf');
        $second = app(FileClassDocument::class)->fromPath($this->class, $this->sourceFile(), 'Cards.pdf');

        $this->assertNotSame($first->path, $second->path);
        Storage::disk('linode')->assertExists($first->path);
        Storage::disk('linode')->assertExists($second->path);
    }

    public function test_it_announces_the_attachment_like_any_other(): void
    {
        // The class documents list refreshes off this event; a queued job's
        // output has to arrive the same way a synchronous one does.
        Event::fake([AttachmentCreated::class]);
        $this->actingAs(User::factory()->for($this->org, 'organization')->withRole('Manager')->create());

        app(FileClassDocument::class)->fromPath($this->class, $this->sourceFile(), 'Cards.pdf');

        Event::assertDispatched(AttachmentCreated::class);
    }

    public function test_a_queued_run_supplies_the_uploader_itself(): void
    {
        // A job runs outside the request so Auth::id() is null, and
        // attachments.uploaded_by_user_id is NOT NULL — so the caller passes
        // the person who asked for the run.
        $requester = User::factory()->for($this->org, 'organization')->withRole('Manager')->create();

        $attachment = app(FileClassDocument::class)->fromPath(
            $this->class,
            $this->sourceFile(),
            'Cards.pdf',
            'cards',
            null,
            $requester->id,
        );

        $this->assertSame($requester->id, $attachment->uploaded_by_user_id);
        Storage::disk('linode')->assertExists($attachment->path);
    }

    public function test_filing_with_no_uploader_at_all_is_refused(): void
    {
        // Better than a NOT NULL violation three frames deeper.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/uploader is required/i');

        app(FileClassDocument::class)->fromPath(
            $this->class,
            $this->sourceFile(),
            'Cards.pdf',
        );
    }

    public function test_a_missing_source_file_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        app(FileClassDocument::class)->fromPath(
            $this->class,
            "{$this->dir}/does-not-exist.pdf",
            'Cards.pdf',
        );
    }
}
