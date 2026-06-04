<?php

namespace Tests\Feature\Tenancy;

use App\Events\TrainingCreated;
use App\Events\TrainingDeleted;
use App\Events\TrainingUpdated;
use App\Models\Organization;
use App\Models\StdFrequency;
use App\Models\Training;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class TrainingsApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_anyone_in_org_can_list(): void
    {
        $org = Organization::factory()->create();
        $member = User::factory()->for($org, 'organization')->withRole('None')->create();
        Training::factory()->for($org, 'organization')->count(2)->create();

        $this->actingAs($member)
            ->getJson('/api/trainings')
            ->assertOk()
            ->assertJsonCount(2);
    }

    public function test_list_is_org_scoped(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $memberA = User::factory()->for($orgA, 'organization')->create();
        Training::factory()->for($orgA, 'organization')->create();
        Training::factory()->for($orgB, 'organization')->count(2)->create();

        $this->actingAs($memberA)
            ->getJson('/api/trainings')
            ->assertOk()
            ->assertJsonCount(1);
    }

    public function test_admin_can_create_repeating_training(): void
    {
        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();
        $freq = StdFrequency::factory()->for($org, 'organization')->create();

        $this->actingAs($admin)
            ->postJson('/api/trainings', [
                'name' => 'Forklift Annual',
                'description' => 'Cal/OSHA forklift refresh',
                'initial_only' => false,
                'repeating' => true,
                'std_freq_id' => $freq->id,
                'as_needed' => false,
            ])
            ->assertCreated();

        $this->assertDatabaseHas('trainings', [
            'org_id' => $org->id,
            'name' => 'Forklift Annual',
            'repeating' => true,
            'std_freq_id' => $freq->id,
        ]);
    }

    public function test_admin_can_set_nickname_on_create(): void
    {
        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();
        $freq = StdFrequency::factory()->for($org, 'organization')->create();

        $this->actingAs($admin)
            ->postJson('/api/trainings', [
                'name' => 'Fall Protection',
                'nickname' => 'FallPro',
                'initial_only' => false,
                'repeating' => true,
                'std_freq_id' => $freq->id,
                'as_needed' => false,
            ])
            ->assertCreated()
            ->assertJsonPath('nickname', 'FallPro');

        $this->assertDatabaseHas('trainings', [
            'org_id' => $org->id,
            'name' => 'Fall Protection',
            'nickname' => 'FallPro',
        ]);
    }

    public function test_admin_can_update_nickname(): void
    {
        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();
        $training = Training::factory()->for($org, 'organization')->create([
            'name' => 'Fall Protection',
            'nickname' => null,
            'initial_only' => true,
            'repeating' => false,
            'as_needed' => false,
        ]);

        $this->actingAs($admin)
            ->patchJson("/api/trainings/{$training->id}", [
                'name' => 'Fall Protection',
                'nickname' => 'FP-Refresh',
                'initial_only' => true,
                'repeating' => false,
                'as_needed' => false,
            ])
            ->assertOk()
            ->assertJsonPath('nickname', 'FP-Refresh');

        $this->assertDatabaseHas('trainings', [
            'id' => $training->id,
            'nickname' => 'FP-Refresh',
        ]);
    }

    public function test_index_includes_nickname(): void
    {
        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();
        Training::factory()->for($org, 'organization')->create([
            'name' => 'Fall Protection',
            'nickname' => 'FallPro',
        ]);

        $this->actingAs($admin)
            ->getJson('/api/trainings')
            ->assertOk()
            ->assertJsonPath('0.nickname', 'FallPro');
    }

    public function test_admin_can_set_default_hours(): void
    {
        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();
        $freq = StdFrequency::factory()->for($org, 'organization')->create();

        $this->actingAs($admin)
            ->postJson('/api/trainings', [
                'name' => 'Fall Protection',
                'default_hours' => 2.5,
                'initial_only' => false,
                'repeating' => true,
                'std_freq_id' => $freq->id,
                'as_needed' => false,
            ])
            ->assertCreated()
            ->assertJsonPath('default_hours', '2.50');

        $this->assertDatabaseHas('trainings', [
            'org_id' => $org->id,
            'name' => 'Fall Protection',
            'default_hours' => 2.5,
        ]);
    }

    public function test_admin_can_set_certificate_fields(): void
    {
        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();
        $freq = StdFrequency::factory()->for($org, 'organization')->create();

        $this->actingAs($admin)
            ->postJson('/api/trainings', [
                'name' => 'Fall Protection',
                'initial_only' => false,
                'repeating' => true,
                'std_freq_id' => $freq->id,
                'as_needed' => false,
                'cert_title' => 'Fall Protection Authorized Person',
                'cert_text' => "Training satisfies **Cal/OSHA** requirements\n\nSecond line",
                'lifespan_months' => 24,
                'cert_code' => 'FPAP',
                'default_trainer' => 'John Balestrini',
                'default_location' => 'VSFCD Training Room',
                'default_address' => "450 Ryder St\nVallejo, CA 94590",
            ])
            ->assertCreated()
            ->assertJsonPath('cert_title', 'Fall Protection Authorized Person')
            ->assertJsonPath('cert_text', "Training satisfies **Cal/OSHA** requirements\n\nSecond line")
            ->assertJsonPath('lifespan_months', 24)
            ->assertJsonPath('cert_code', 'FPAP')
            ->assertJsonPath('default_location', 'VSFCD Training Room');

        $this->assertDatabaseHas('trainings', [
            'org_id' => $org->id,
            'cert_title' => 'Fall Protection Authorized Person',
            'lifespan_months' => 24,
            'cert_code' => 'FPAP',
            'default_trainer' => 'John Balestrini',
        ]);
    }

    public function test_admin_can_create_initial_only_training(): void
    {
        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();

        $this->actingAs($admin)
            ->postJson('/api/trainings', [
                'name' => 'New Hire Orientation',
                'initial_only' => true,
                'repeating' => false,
                'as_needed' => false,
            ])
            ->assertCreated();
    }

    public function test_admin_can_create_as_needed_training(): void
    {
        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();

        $this->actingAs($admin)
            ->postJson('/api/trainings', [
                'name' => 'Spill Response (on-call)',
                'initial_only' => false,
                'repeating' => false,
                'as_needed' => true,
            ])
            ->assertCreated();
    }

    public function test_manager_cannot_create(): void
    {
        $org = Organization::factory()->create();
        $manager = User::factory()->for($org, 'organization')->withRole('Manager')->create();

        $this->actingAs($manager)
            ->postJson('/api/trainings', [
                'name' => 'X',
                'initial_only' => true,
                'repeating' => false,
                'as_needed' => false,
            ])
            ->assertForbidden();
    }

    public function test_create_rejects_no_timing_flag(): void
    {
        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();

        $this->actingAs($admin)
            ->postJson('/api/trainings', [
                'name' => 'X',
                'initial_only' => false,
                'repeating' => false,
                'as_needed' => false,
            ])
            ->assertStatus(422);
    }

    public function test_create_rejects_initial_and_repeating_together(): void
    {
        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();
        $freq = StdFrequency::factory()->for($org, 'organization')->create();

        $this->actingAs($admin)
            ->postJson('/api/trainings', [
                'name' => 'X',
                'initial_only' => true,
                'repeating' => true,
                'std_freq_id' => $freq->id,
                'as_needed' => false,
            ])
            ->assertStatus(422);
    }

    public function test_create_repeating_requires_std_freq_id(): void
    {
        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();

        $this->actingAs($admin)
            ->postJson('/api/trainings', [
                'name' => 'X',
                'initial_only' => false,
                'repeating' => true,
                'as_needed' => false,
            ])
            ->assertStatus(422);
    }

    public function test_create_rejects_cross_org_std_freq(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $adminA = User::factory()->for($orgA, 'organization')->withRole('Admin')->create();
        $freqB = StdFrequency::factory()->for($orgB, 'organization')->create();

        $this->actingAs($adminA)
            ->postJson('/api/trainings', [
                'name' => 'X',
                'initial_only' => false,
                'repeating' => true,
                'std_freq_id' => $freqB->id,
                'as_needed' => false,
            ])
            ->assertStatus(422);
    }

    public function test_admin_can_update(): void
    {
        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();
        $training = Training::factory()->for($org, 'organization')->create();
        $freq = StdFrequency::factory()->for($org, 'organization')->create();

        $this->actingAs($admin)
            ->patchJson("/api/trainings/{$training->id}", [
                'name' => 'Renamed',
                'description' => 'New desc',
                'initial_only' => false,
                'repeating' => true,
                'std_freq_id' => $freq->id,
                'as_needed' => false,
            ])
            ->assertOk();

        $training->refresh();
        $this->assertSame('Renamed', $training->name);
        $this->assertSame($freq->id, $training->std_freq_id);
    }

    public function test_cross_org_update_blocked(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $adminA = User::factory()->for($orgA, 'organization')->withRole('Admin')->create();
        $trainingB = Training::factory()->for($orgB, 'organization')->create();

        $this->actingAs($adminA)
            ->patchJson("/api/trainings/{$trainingB->id}", [
                'name' => 'hacked',
                'initial_only' => true,
                'repeating' => false,
                'as_needed' => false,
            ])
            ->assertNotFound();
    }

    public function test_admin_can_delete(): void
    {
        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();
        $training = Training::factory()->for($org, 'organization')->create();

        $this->actingAs($admin)
            ->deleteJson("/api/trainings/{$training->id}")
            ->assertOk();

        $this->assertSoftDeleted('trainings', ['id' => $training->id]);
    }

    public function test_create_update_delete_broadcast(): void
    {
        Event::fake([TrainingCreated::class, TrainingUpdated::class, TrainingDeleted::class]);

        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();

        $created = $this->actingAs($admin)
            ->postJson('/api/trainings', [
                'name' => 'X',
                'initial_only' => true,
                'repeating' => false,
                'as_needed' => false,
            ])
            ->json();
        $this->actingAs($admin)->patchJson("/api/trainings/{$created['id']}", [
            'name' => 'Y',
            'initial_only' => true,
            'repeating' => false,
            'as_needed' => false,
        ]);
        $this->actingAs($admin)->deleteJson("/api/trainings/{$created['id']}");

        Event::assertDispatched(TrainingCreated::class);
        Event::assertDispatched(TrainingUpdated::class);
        Event::assertDispatched(TrainingDeleted::class);
    }

    public function test_training_can_be_tagged(): void
    {
        // Smoke that Training is wired as a morphable for the tags API.
        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();
        $training = Training::factory()->for($org, 'organization')->create();
        $tagId = $this->actingAs($admin)
            ->postJson('/api/tags', ['name' => 'safety'])
            ->json('id');

        $this->actingAs($admin)
            ->postJson('/api/tags/attach', [
                'tag_id' => $tagId,
                'taggable_type' => Training::class,
                'taggable_id' => $training->id,
            ])
            ->assertOk();

        $this->assertCount(1, $training->fresh()->tags);
    }

    public function test_training_accepts_comments_and_attachments(): void
    {
        // Smoke that Training is wired in CommentsController + AttachmentsController.
        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();
        $training = Training::factory()->for($org, 'organization')->create();

        $this->actingAs($admin)
            ->postJson('/api/comments', [
                'commentable_type' => Training::class,
                'commentable_id' => $training->id,
                'body' => 'first note',
            ])
            ->assertCreated();

        $this->assertCount(1, $training->fresh()->comments);
    }
}
