<?php

namespace Tests\Feature\Tenancy;

use App\Models\Organization;
use App\Models\Requirement;
use App\Models\RqmtElement;
use App\Models\Training;
use App\Models\User;
use App\Notifications\AssignmentCreatedForYou;
use App\Notifications\CompletionRecordedForYou;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Phase 15.1 dispatch coverage, repointed to the TA engine (J5):
 * AssignmentCreatedForYou fires when a training assignment is genuinely
 * new for the user (one per requirement set, none for extra sources,
 * none for self-actions); CompletionRecordedForYou is unchanged.
 *
 * Uses Notification::fake() to inspect dispatch shape; pairs with a
 * non-fake assertion that database rows actually persist via the
 * Notifiable trait's database channel.
 */
class NotificationDispatchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private function scaffoldOrg(): array
    {
        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();
        $member = User::factory()->for($org, 'organization')->create();
        $training = Training::factory()->for($org, 'organization')->create();
        $req = Requirement::factory()->for($org, 'organization')->create();
        $element = RqmtElement::factory()
            ->for($org, 'organization')
            ->for($req, 'requirement')
            ->state(['module_type' => Training::class, 'module_id' => $training->id])
            ->create();

        return [$org, $admin, $member, $req, $training, $element];
    }

    public function test_requirement_assignment_notifies_the_assigned_user_once(): void
    {
        Notification::fake();
        [, $admin, $member, $req] = $this->scaffoldOrg();

        $this->actingAs($admin)
            ->postJson('/api/training-assignments', [
                'source_type' => 'requirement',
                'user_id' => $member->id,
                'requirement_id' => $req->id,
            ])
            ->assertCreated();

        // One nudge per requirement set, named after the requirement.
        Notification::assertSentToTimes($member, AssignmentCreatedForYou::class, 1);
        Notification::assertSentTo($member, AssignmentCreatedForYou::class, function ($n) use ($req) {
            return $n->name === $req->name && $n->requirementId === $req->id;
        });
        Notification::assertNotSentTo($admin, AssignmentCreatedForYou::class);
    }

    public function test_direct_assignment_notifies_with_the_training_name(): void
    {
        Notification::fake();
        [, $admin, $member, , $training] = $this->scaffoldOrg();

        $this->actingAs($admin)
            ->postJson('/api/training-assignments', [
                'source_type' => 'direct',
                'user_id' => $member->id,
                'training_id' => $training->id,
            ])
            ->assertCreated();

        Notification::assertSentTo($member, AssignmentCreatedForYou::class, function ($n) use ($training) {
            return $n->name === $training->name && $n->trainingId === $training->id;
        });
    }

    public function test_adding_a_second_source_does_not_renotify(): void
    {
        Notification::fake();
        [, $admin, $member, $req, $training] = $this->scaffoldOrg();

        // First assignment creates the TA and notifies.
        $this->actingAs($admin)
            ->postJson('/api/training-assignments', [
                'source_type' => 'direct',
                'user_id' => $member->id,
                'training_id' => $training->id,
            ])
            ->assertCreated();

        // The requirement covers the same training — TA already exists, so
        // the user gains a source but no new obligation: stay silent.
        $this->actingAs($admin)
            ->postJson('/api/training-assignments', [
                'source_type' => 'requirement',
                'user_id' => $member->id,
                'requirement_id' => $req->id,
            ])
            ->assertCreated();

        Notification::assertSentToTimes($member, AssignmentCreatedForYou::class, 1);
    }

    public function test_bulk_assignment_notifies_each_user_once(): void
    {
        // J5 semantics change: the legacy tag-matrix bulk suppressed
        // notifications (50 pairs per user); the TA bulk assigns one
        // training/requirement to many users — one nudge each is signal,
        // not noise.
        Notification::fake();
        [$org, $admin, $member, $req] = $this->scaffoldOrg();
        $second = User::factory()->for($org, 'organization')->create();

        $this->actingAs($admin)
            ->postJson('/api/bulk-training-assignments', [
                'source_type' => 'requirement',
                'user_ids' => [$member->id, $second->id],
                'requirement_id' => $req->id,
            ])
            ->assertCreated();

        Notification::assertSentToTimes($member, AssignmentCreatedForYou::class, 1);
        Notification::assertSentToTimes($second, AssignmentCreatedForYou::class, 1);
    }

    public function test_assignment_created_for_self_does_not_notify(): void
    {
        // Self-action: admin assigning a training to themselves doesn't
        // generate a notification (they just clicked Save).
        Notification::fake();
        [, $admin, , , $training] = $this->scaffoldOrg();

        $this->actingAs($admin)
            ->postJson('/api/training-assignments', [
                'source_type' => 'direct',
                'user_id' => $admin->id,
                'training_id' => $training->id,
            ])
            ->assertCreated();

        Notification::assertNotSentTo($admin, AssignmentCreatedForYou::class);
    }

    public function test_completion_recorded_notifies_the_user(): void
    {
        Notification::fake();
        [, $admin, $member, , $training, $element] = $this->scaffoldOrg();

        $this->actingAs($admin)
            ->postJson('/api/completions', [
                'user_id' => $member->id,
                'module_type' => Training::class,
                'module_id' => $training->id,
                'completion_date' => '2026-05-10',
                'rqmt_element_ids' => [$element->id],
            ])
            ->assertCreated();

        Notification::assertSentTo($member, CompletionRecordedForYou::class);
        Notification::assertNotSentTo($admin, CompletionRecordedForYou::class);
    }

    public function test_completion_recorded_for_self_does_not_notify(): void
    {
        Notification::fake();
        [, $admin, , , $training, $element] = $this->scaffoldOrg();

        $this->actingAs($admin)
            ->postJson('/api/completions', [
                'user_id' => $admin->id,
                'module_type' => Training::class,
                'module_id' => $training->id,
                'completion_date' => '2026-05-10',
                'rqmt_element_ids' => [$element->id],
            ])
            ->assertCreated();

        Notification::assertNotSentTo($admin, CompletionRecordedForYou::class);
    }

    public function test_assignment_notification_persists_database_row(): void
    {
        // Without faking, the database channel should write a real row
        // to notifications. Smoke-checks the migration + Notifiable
        // wiring end-to-end.
        [, $admin, $member, $req] = $this->scaffoldOrg();

        $this->actingAs($admin)
            ->postJson('/api/training-assignments', [
                'source_type' => 'requirement',
                'user_id' => $member->id,
                'requirement_id' => $req->id,
            ])
            ->assertCreated();

        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $member->id,
            'notifiable_type' => User::class,
            'type' => AssignmentCreatedForYou::class,
        ]);
        // Sanity: nothing landed for the admin (self/no-notify path).
        $this->assertDatabaseMissing('notifications', [
            'notifiable_id' => $admin->id,
            'type' => AssignmentCreatedForYou::class,
        ]);
    }

    public function test_completion_notification_payload_includes_element_ids(): void
    {
        // Important detail: the listener eager-loads rqmtElements so the
        // payload's rqmt_element_ids[] isn't empty. Asserting on the
        // persisted JSON keeps that path covered.
        [, $admin, $member, , $training, $element] = $this->scaffoldOrg();

        $this->actingAs($admin)
            ->postJson('/api/completions', [
                'user_id' => $member->id,
                'module_type' => Training::class,
                'module_id' => $training->id,
                'completion_date' => '2026-05-10',
                'rqmt_element_ids' => [$element->id],
            ])
            ->assertCreated();

        $row = \DB::table('notifications')
            ->where('notifiable_id', $member->id)
            ->where('type', CompletionRecordedForYou::class)
            ->first();

        $this->assertNotNull($row);
        $data = json_decode($row->data, true);
        $this->assertSame([$element->id], $data['rqmt_element_ids']);
    }
}
