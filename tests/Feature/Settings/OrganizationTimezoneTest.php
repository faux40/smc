<?php

namespace Tests\Feature\Settings;

use App\Models\Organization;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Phase 15.6 — the org settings page exposes a timezone picker that
 * drives org-local scheduling of the weekly manager digest.
 */
class OrganizationTimezoneTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private function ownerOf(Organization $org): User
    {
        return User::factory()->for($org, 'organization')->withRole('Owner')->create();
    }

    public function test_edit_page_includes_timezone_and_the_identifier_list(): void
    {
        $org = Organization::factory()->create(['timezone' => 'America/New_York']);
        $owner = $this->ownerOf($org);

        $this->actingAs($owner)
            ->get(route('organization.edit'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('settings/Organization')
                ->where('organization.timezone', 'America/New_York')
                ->has('timezones')
                ->where('timezones', fn ($tzs) => collect($tzs)->contains('America/New_York'))
            );
    }

    public function test_update_persists_a_valid_timezone(): void
    {
        $org = Organization::factory()->create(['timezone' => 'UTC']);
        $owner = $this->ownerOf($org);

        $this->actingAs($owner)
            ->patch(route('organization.update'), [
                'name' => $org->name,
                'timezone' => 'America/Chicago',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('organization.edit'));

        $this->assertSame('America/Chicago', $org->fresh()->timezone);
    }

    public function test_update_rejects_an_invalid_timezone(): void
    {
        $org = Organization::factory()->create();
        $owner = $this->ownerOf($org);

        $this->actingAs($owner)
            ->patch(route('organization.update'), [
                'name' => $org->name,
                'timezone' => 'Mars/Olympus_Mons',
            ])
            ->assertSessionHasErrors('timezone');

        $this->assertSame('UTC', $org->fresh()->timezone);
    }

    public function test_update_requires_a_timezone(): void
    {
        $org = Organization::factory()->create();
        $owner = $this->ownerOf($org);

        $this->actingAs($owner)
            ->patch(route('organization.update'), ['name' => $org->name])
            ->assertSessionHasErrors('timezone');
    }
}
