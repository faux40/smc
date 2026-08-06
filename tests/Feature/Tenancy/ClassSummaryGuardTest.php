<?php

namespace Tests\Feature\Tenancy;

use App\Models\Organization;
use App\Models\TrainingClass;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\LaravelPdf\Facades\Pdf;
use Tests\TestCase;

/**
 * A class summary reports what a class awarded, so it only means anything once
 * the class is closed. That rule lived only in classes/Show.vue, which decided
 * which button to render; the endpoint itself would happily produce a summary
 * of a class that hasn't happened yet.
 *
 * The classes index now carries a per-row print icon, which widens who can
 * reach these URLs and from where, so the rule belongs on the server too.
 */
class ClassSummaryGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        Pdf::fake();
    }

    private function manager(Organization $org): User
    {
        return User::factory()->for($org, 'organization')->withRole('Manager')->create();
    }

    public function test_summary_of_a_completed_class_renders(): void
    {
        $org = Organization::factory()->create();
        $manager = $this->manager($org);
        $class = TrainingClass::factory()->for($org, 'organization')->create([
            'status' => 'completed',
            'completion_date' => '2026-06-01',
            'completed_at' => now(),
        ]);

        $this->actingAs($manager)
            ->get("/api/classes/{$class->id}/summary")
            ->assertOk();
    }

    public function test_summary_of_a_scheduled_class_is_refused(): void
    {
        $org = Organization::factory()->create();
        $manager = $this->manager($org);
        $class = TrainingClass::factory()->for($org, 'organization')->create([
            'status' => 'scheduled',
        ]);

        $this->actingAs($manager)
            ->getJson("/api/classes/{$class->id}/summary")
            ->assertStatus(422);
    }

    public function test_filing_a_summary_of_a_scheduled_class_is_refused(): void
    {
        // The POST twin files the same PDF into the class's documents; it must
        // not be a way around the guard.
        $org = Organization::factory()->create();
        $manager = $this->manager($org);
        $class = TrainingClass::factory()->for($org, 'organization')->create([
            'status' => 'scheduled',
        ]);

        $this->actingAs($manager)
            ->postJson("/api/classes/{$class->id}/summary")
            ->assertStatus(422);
    }

    public function test_sign_in_sheet_works_for_a_scheduled_class(): void
    {
        // The mirror case: a sign-in sheet is for a class that hasn't happened.
        $org = Organization::factory()->create();
        $manager = $this->manager($org);
        $class = TrainingClass::factory()->for($org, 'organization')->create([
            'status' => 'scheduled',
        ]);

        $this->actingAs($manager)
            ->get("/api/classes/{$class->id}/sign-in-sheet")
            ->assertOk();
    }

    public function test_sign_in_sheet_still_works_for_a_completed_class(): void
    {
        // Deliberately NOT guarded: reprinting the sheet for a class that has
        // already run is a normal records request.
        $org = Organization::factory()->create();
        $manager = $this->manager($org);
        $class = TrainingClass::factory()->for($org, 'organization')->create([
            'status' => 'completed',
            'completion_date' => '2026-06-01',
            'completed_at' => now(),
        ]);

        $this->actingAs($manager)
            ->get("/api/classes/{$class->id}/sign-in-sheet")
            ->assertOk();
    }
}
