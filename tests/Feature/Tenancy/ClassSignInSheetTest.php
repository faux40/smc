<?php

namespace Tests\Feature\Tenancy;

use App\Models\ClassEnrollment;
use App\Models\Organization;
use App\Models\TrainingClass;
use App\Models\User;
use App\Support\ClassSignInSheet;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\LaravelPdf\Facades\Pdf;
use Tests\TestCase;

class ClassSignInSheetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        // Fake the PDF driver so the endpoint test asserts the view/response
        // without shelling out to Browsershot/Chromium (matches the other PDF
        // feature tests + keeps the suite env-independent).
        Pdf::fake();
    }

    private function manager(Organization $org): User
    {
        return User::factory()->for($org, 'organization')->withRole('Manager')->create();
    }

    /** Enroll $n distinct users on $class and return the class refreshed. */
    private function enroll(Organization $org, TrainingClass $class, int $n): TrainingClass
    {
        foreach (range(1, $n) as $i) {
            $u = User::factory()->for($org, 'organization')
                ->create(['f_name' => 'User', 'l_name' => str_pad((string) $i, 2, '0', STR_PAD_LEFT)]);
            ClassEnrollment::factory()->for($class, 'trainingClass')->create(['user_id' => $u->id]);
        }

        return $class->fresh();
    }

    public function test_data_lists_enrolled_names_and_pads_to_fill_the_first_page(): void
    {
        $org = Organization::factory()->create();
        app()->instance('currentOrgId', $org->id);
        $class = TrainingClass::factory()->for($org, 'organization')->create([
            'instructor' => 'John B',
            'total_hours' => 7,
            'max_students' => null,
        ]);
        $u = User::factory()->for($org, 'organization')->create(['f_name' => 'Ann', 'l_name' => 'Lee']);
        ClassEnrollment::factory()->for($class, 'trainingClass')->create(['user_id' => $u->id]);

        $data = ClassSignInSheet::data($class->fresh());

        $this->assertSame(1, $data['students']);
        $this->assertSame('John B', $data['trainer']);
        $this->assertSame('Ann Lee', $data['rows'][0]);
        $this->assertSame('', $data['rows'][1]); // padded blank
        // Exactly one full first page of rows (walk-in space).
        $this->assertCount(ClassSignInSheet::FIRST_PAGE_ROWS, $data['rows']);
    }

    public function test_an_empty_class_still_fills_the_page_with_blank_rows(): void
    {
        $org = Organization::factory()->create();
        app()->instance('currentOrgId', $org->id);
        $class = TrainingClass::factory()->for($org, 'organization')->create([
            'max_students' => null,
        ]);

        $data = ClassSignInSheet::data($class->fresh());

        $this->assertCount(ClassSignInSheet::FIRST_PAGE_ROWS, $data['rows']);
        $this->assertSame('', $data['rows'][0]);
    }

    public function test_overflow_pads_to_whole_pages_so_the_last_page_fills(): void
    {
        $org = Organization::factory()->create();
        app()->instance('currentOrgId', $org->id);
        $class = TrainingClass::factory()->for($org, 'organization')->create([
            'max_students' => null,
        ]);
        // One more than the first page holds → spills onto a 2nd page, which
        // pads to ITS full capacity (continuation pages hold more rows — no
        // page-1 header block).
        $class = $this->enroll($org, $class, ClassSignInSheet::FIRST_PAGE_ROWS + 1);

        $data = ClassSignInSheet::data($class);

        $expected = ClassSignInSheet::FIRST_PAGE_ROWS + ClassSignInSheet::NEXT_PAGE_ROWS;
        $this->assertCount($expected, $data['rows']);
        $this->assertSame('', $data['rows'][$expected - 1]); // trailing blank for walk-ins
    }

    public function test_a_set_max_caps_the_rows_instead_of_filling_the_page(): void
    {
        $org = Organization::factory()->create();
        app()->instance('currentOrgId', $org->id);
        $class = TrainingClass::factory()->for($org, 'organization')->create([
            'max_students' => 6,
        ]);
        $class = $this->enroll($org, $class, 2);

        $data = ClassSignInSheet::data($class);

        $this->assertCount(6, $data['rows']); // exactly max, not a page-fill
        $this->assertSame('', $data['rows'][5]);
    }

    public function test_enrollment_beyond_max_shows_every_student_with_no_blanks(): void
    {
        $org = Organization::factory()->create();
        app()->instance('currentOrgId', $org->id);
        $class = TrainingClass::factory()->for($org, 'organization')->create([
            'max_students' => 3,
        ]);
        $class = $this->enroll($org, $class, 5);

        $data = ClassSignInSheet::data($class);

        $this->assertCount(5, $data['rows']); // all students, max ignored
        $this->assertNotSame('', $data['rows'][4]); // and no trailing blanks
    }

    public function test_a_zero_max_is_treated_as_unset(): void
    {
        $org = Organization::factory()->create();
        app()->instance('currentOrgId', $org->id);
        $class = TrainingClass::factory()->for($org, 'organization')->create([
            'max_students' => 0,
        ]);

        $data = ClassSignInSheet::data($class->fresh());

        $this->assertCount(ClassSignInSheet::FIRST_PAGE_ROWS, $data['rows']);
    }

    public function test_endpoint_returns_a_pdf_even_with_no_enrollees(): void
    {
        $org = Organization::factory()->create();
        $manager = $this->manager($org);
        $class = TrainingClass::factory()->for($org, 'organization')->create();

        $this->actingAs($manager)->get("/api/classes/{$class->id}/sign-in-sheet")->assertOk();

        Pdf::assertRespondedWithPdf(fn ($pdf) => $pdf->viewName === 'pdf.sign-in-sheet');
    }

    public function test_endpoint_repeats_the_class_info_in_the_page_header_and_numbers_pages(): void
    {
        $org = Organization::factory()->create(['name' => 'Barritt Group']);
        $manager = $this->manager($org);
        $class = TrainingClass::factory()->for($org, 'organization')->create([
            'name' => 'Fall Protection',
            'location' => 'Yard 3',
            'instructor' => 'John B',
        ]);

        $this->actingAs($manager)->get("/api/classes/{$class->id}/sign-in-sheet")->assertOk();

        Pdf::assertRespondedWithPdf(function ($pdf) {
            // Per-page repeating header (Chromium margin header) carries the
            // important class info…
            return $pdf->headerViewName === 'pdf.partials.sign-in-header'
                && $pdf->headerData['org_name'] === 'Barritt Group'
                && $pdf->headerData['title'] === 'Fall Protection'
                && $pdf->headerData['location'] === 'Yard 3'
                && $pdf->headerData['trainer'] === 'John B'
                // …and the footer numbers pages (no "of N" — back page is
                // skippable).
                && $pdf->footerViewName === 'pdf.partials.footer'
                && ($pdf->footerData['pageNumber'] ?? false) === true;
        });
    }
}
