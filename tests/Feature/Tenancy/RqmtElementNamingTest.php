<?php

namespace Tests\Feature\Tenancy;

use App\Events\RqmtElementCreated;
use App\Events\RqmtElementUpdated;
use App\Models\Organization;
use App\Models\Requirement;
use App\Models\Training;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Element display names follow the live training (rename-safe naming).
 *
 * Born from a real trap: a June rename of "Fall Protection" to "Fall
 * Protection Competent Person" left three requirements showing an element
 * name no training carried anymore. `rqmt_elements.name` is now a nullable
 * OVERRIDE — null means "display the module's live name" — and the API
 * serializes `name` (effective), `custom_name` (the raw override) and
 * `module_name` (live) so the UI can show a diverged override beside the
 * real thing.
 */
class RqmtElementNamingTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->org = Organization::factory()->create();
        $this->admin = User::factory()->for($this->org, 'organization')->withRole('Admin')->create();
    }

    private function training(string $name): Training
    {
        return Training::factory()->for($this->org, 'organization')->create(['name' => $name]);
    }

    private function requirement(): Requirement
    {
        return Requirement::factory()->for($this->org, 'organization')->create();
    }

    /** POST a minimal element through the real endpoint. */
    private function postElement(Requirement $req, Training $training, array $overrides = []): string
    {
        return $this->actingAs($this->admin)
            ->postJson("/api/requirements/{$req->id}/elements", array_merge([
                'module_type' => Training::class,
                'module_id' => $training->id,
                'initial_only' => true,
                'repeating' => false,
                'as_needed' => false,
                'std_freq_id' => null,
            ], $overrides))
            ->assertCreated()
            ->json('id');
    }

    private function indexRows(Requirement $req): array
    {
        return $this->actingAs($this->admin)
            ->getJson("/api/requirements/{$req->id}/elements")
            ->assertOk()
            ->json();
    }

    private function candidateRows(Training $training): array
    {
        return $this->actingAs($this->admin)
            ->getJson('/api/rqmt-elements/candidates?module_type='.urlencode(Training::class)."&module_id={$training->id}")
            ->assertOk()
            ->json();
    }

    public function test_an_element_created_without_a_name_follows_the_trainings_live_name(): void
    {
        $training = $this->training('Fall Protection Competent Person');
        $req = $this->requirement();

        $this->postElement($req, $training);

        $row = $this->indexRows($req)[0];
        $this->assertSame('Fall Protection Competent Person', $row['name']);
        $this->assertNull($row['custom_name']);
        $this->assertSame('Fall Protection Competent Person', $row['module_name']);
    }

    public function test_renaming_the_training_renames_every_element_pointing_at_it(): void
    {
        // The exact scenario that caught us: elements on several requirements,
        // then the training gets a better name. Every display surface must
        // follow — nothing may keep showing the old snapshot.
        $training = $this->training('Fall Protection');
        $reqA = $this->requirement();
        $reqB = $this->requirement();
        $this->postElement($reqA, $training);
        $this->postElement($reqB, $training);

        $training->update(['name' => 'Fall Protection Competent Person']);

        $this->assertSame('Fall Protection Competent Person', $this->indexRows($reqA)[0]['name']);
        $this->assertSame('Fall Protection Competent Person', $this->indexRows($reqB)[0]['name']);
        $this->assertSame(
            ['Fall Protection Competent Person', 'Fall Protection Competent Person'],
            collect($this->candidateRows($training))->pluck('name')->all(),
        );
    }

    public function test_a_custom_name_survives_a_rename_and_travels_with_the_live_name(): void
    {
        // A deliberate override is kept — but the live name rides along so
        // the UI can show "Short Label → Real Training Name" instead of
        // leaving a mystery like the one that started all this.
        $training = $this->training('Fall Protection');
        $req = $this->requirement();
        $this->postElement($req, $training, ['name' => 'FP (crew shorthand)']);

        $training->update(['name' => 'Fall Protection Competent Person']);

        $row = $this->indexRows($req)[0];
        $this->assertSame('FP (crew shorthand)', $row['name']);
        $this->assertSame('FP (crew shorthand)', $row['custom_name']);
        $this->assertSame('Fall Protection Competent Person', $row['module_name']);
        $this->assertSame('FP (crew shorthand)', $this->candidateRows($training)[0]['name']);
    }

    public function test_clearing_the_override_resumes_following_the_training(): void
    {
        $training = $this->training('Confined Space');
        $req = $this->requirement();
        $id = $this->postElement($req, $training, ['name' => 'Old Label']);

        $this->actingAs($this->admin)
            ->patchJson("/api/rqmt-elements/{$id}", [
                'name' => null,
                'description' => null,
                'initial_only' => true,
                'repeating' => false,
                'as_needed' => false,
                'std_freq_id' => null,
            ])
            ->assertOk();

        $row = $this->indexRows($req)[0];
        $this->assertSame('Confined Space', $row['name']);
        $this->assertNull($row['custom_name']);
    }

    public function test_a_blank_name_is_no_override(): void
    {
        // '' must not freeze the current name — only a real label overrides.
        $training = $this->training('Hearing Conservation');
        $req = $this->requirement();
        $this->postElement($req, $training, ['name' => '']);

        $training->update(['name' => 'Hearing Conservation (1910.95)']);

        $row = $this->indexRows($req)[0];
        $this->assertNull($row['custom_name']);
        $this->assertSame('Hearing Conservation (1910.95)', $row['name']);
    }

    public function test_candidates_sort_by_effective_name(): void
    {
        $training = $this->training('Apple Picking Safety');
        $reqA = $this->requirement();
        $reqB = $this->requirement();
        $this->postElement($reqA, $training, ['name' => 'Zebra Label']);
        $this->postElement($reqB, $training);

        $this->assertSame(
            ['Apple Picking Safety', 'Zebra Label'],
            collect($this->candidateRows($training))->pluck('name')->all(),
        );
    }

    public function test_a_trashed_trainings_elements_still_display_its_name(): void
    {
        // Soft-deleting a training must not blank the element labels that
        // point at it — same "hop over the dead node" spirit as the
        // hierarchy walker.
        $training = $this->training('Lockout/Tagout');
        $req = $this->requirement();
        $this->postElement($req, $training);

        $training->delete();

        $row = $this->indexRows($req)[0];
        $this->assertSame('Lockout/Tagout', $row['name']);
        $this->assertSame('Lockout/Tagout', $row['module_name']);
    }

    public function test_broadcasts_carry_the_effective_name(): void
    {
        // Peer tabs render straight from the broadcast payload; an override-
        // null element must not broadcast a null name.
        Event::fake([RqmtElementCreated::class, RqmtElementUpdated::class]);

        $training = $this->training('Traffic Control');
        $req = $this->requirement();
        $id = $this->postElement($req, $training);

        Event::assertDispatched(RqmtElementCreated::class, function (RqmtElementCreated $e) {
            $payload = $e->broadcastWith();

            return $payload['name'] === 'Traffic Control'
                && $payload['custom_name'] === null
                && $payload['module_name'] === 'Traffic Control';
        });

        $this->actingAs($this->admin)
            ->patchJson("/api/rqmt-elements/{$id}", [
                'name' => 'TC Label',
                'description' => null,
                'initial_only' => true,
                'repeating' => false,
                'as_needed' => false,
                'std_freq_id' => null,
            ])
            ->assertOk();

        Event::assertDispatched(RqmtElementUpdated::class, function (RqmtElementUpdated $e) {
            $payload = $e->broadcastWith();

            return $payload['name'] === 'TC Label'
                && $payload['custom_name'] === 'TC Label'
                && $payload['module_name'] === 'Traffic Control';
        });
    }
}
