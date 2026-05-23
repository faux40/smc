<?php

namespace Tests\Feature\Tenancy;

use App\Models\ClassEnrollment;
use App\Models\Organization;
use App\Models\TrainingClass;
use App\Models\User;
use App\Support\ClassSignInSheet;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClassSignInSheetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private function manager(Organization $org): User
    {
        return User::factory()->for($org, 'organization')->withRole('Manager')->create();
    }

    public function test_data_lists_enrolled_names_and_pads_to_fill_the_page(): void
    {
        $org = Organization::factory()->create();
        app()->instance('currentOrgId', $org->id);
        $class = TrainingClass::factory()->for($org, 'organization')->create([
            'instructor' => 'John B',
            'total_hours' => 7,
        ]);
        $u = User::factory()->for($org, 'organization')->create(['f_name' => 'Ann', 'l_name' => 'Lee']);
        ClassEnrollment::factory()->for($class, 'trainingClass')->create(['user_id' => $u->id]);

        $data = ClassSignInSheet::data($class->fresh());

        $this->assertSame(1, $data['students']);
        $this->assertSame('John B', $data['trainer']);
        $this->assertSame('Ann Lee', $data['rows'][0]);
        $this->assertSame('', $data['rows'][1]); // padded blank
        $this->assertGreaterThanOrEqual(18, count($data['rows']));
    }

    public function test_endpoint_returns_a_pdf_even_with_no_enrollees(): void
    {
        $org = Organization::factory()->create();
        $manager = $this->manager($org);
        $class = TrainingClass::factory()->for($org, 'organization')->create();

        $response = $this->actingAs($manager)->get("/api/classes/{$class->id}/sign-in-sheet");

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('content-type'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }
}
