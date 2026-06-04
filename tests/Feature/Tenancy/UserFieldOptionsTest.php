<?php

namespace Tests\Feature\Tenancy;

use App\Models\Organization;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserFieldOptionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_returns_distinct_sorted_org_scoped_field_values(): void
    {
        $org = Organization::factory()->create();
        $actor = User::factory()->for($org, 'organization')->create();

        User::factory()->for($org, 'organization')->create([
            'department' => 'Operations',
            'location' => 'Yard 3',
            'job_title' => 'Foreman',
        ]);
        // Duplicate department + a blank/null on the others — duplicates collapse,
        // blanks are excluded.
        User::factory()->for($org, 'organization')->create([
            'department' => 'Operations',
            'location' => 'Yard 1',
            'job_title' => null,
        ]);
        User::factory()->for($org, 'organization')->create([
            'department' => 'Admin',
            'location' => '',
            'job_title' => 'Foreman',
        ]);

        $response = $this->actingAs($actor)
            ->getJson('/api/users/field-options')
            ->assertOk();

        $response->assertExactJson([
            'department' => ['Admin', 'Operations'],
            'location' => ['Yard 1', 'Yard 3'],
            'job_title' => ['Foreman'],
        ]);
    }

    public function test_does_not_leak_other_org_values(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $actor = User::factory()->for($orgA, 'organization')->create([
            'department' => 'Engineering',
        ]);
        User::factory()->for($orgB, 'organization')->create([
            'department' => 'Secret-OrgB-Dept',
            'location' => 'OrgB-Location',
            'job_title' => 'OrgB-Title',
        ]);

        $response = $this->actingAs($actor)
            ->getJson('/api/users/field-options')
            ->assertOk();

        $response->assertJsonFragment(['department' => ['Engineering']]);
        $this->assertStringNotContainsString('OrgB', $response->getContent());
    }

    public function test_requires_authentication(): void
    {
        $this->getJson('/api/users/field-options')->assertUnauthorized();
    }
}
