<?php

namespace Tests\Feature\Tenancy;

use App\Models\Assignment;
use App\Models\Completion;
use App\Models\Organization;
use App\Models\Requirement;
use App\Models\RqmtElement;
use App\Models\Tag;
use App\Models\Training;
use App\Models\User;
use App\Notifications\AssignmentCreatedForYou;
use App\Notifications\CompletionRecordedForYou;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Phase 15.1 dispatch coverage: the two listener-driven notifications
 * fire on Created events, persist DB rows for the recipient, and
 * respect both suppression rules (self-action and bulk-fanout).
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

    public function test_assignment_created_notifies_the_assigned_user(): void
    {
        Notification::fake();
        [, $admin, $member, $req] = $this->scaffoldOrg();

        $this->actingAs($admin)
            ->postJson('/api/assignments', [
                'user_id' => $member->id,
                'requirement_id' => $req->id,
                'name' => 'OSHA Test',
                'initial_only' => true,
                'repeating' => false,
                'as_needed' => false,
                'start_date' => '2026-05-12',
            ])
            ->assertCreated();

        Notification::assertSentTo($member, AssignmentCreatedForYou::class, function ($n) {
            return $n->assignment->name === 'OSHA Test';
        });
        Notification::assertNotSentTo($admin, AssignmentCreatedForYou::class);
    }

    public function test_assignment_created_via_bulk_does_not_notify(): void
    {
        Notification::fake();
        [$org, $admin, $member, $req] = $this->scaffoldOrg();
        $tag = Tag::factory()->for($org, 'organization')->create();
        $member->tags()->attach($tag->id);
        $req->tags()->attach($tag->id);

        $this->actingAs($admin)
            ->postJson('/api/bulk-assignments', [
                'pairs' => [
                    ['user_id' => $member->id, 'requirement_id' => $req->id],
                ],
                'initial_only' => true,
                'repeating' => false,
                'as_needed' => false,
                'start_date' => '2026-05-12',
            ])
            ->assertCreated();

        // The bulk path sets fromBulk=true; the listener must skip even
        // though the underlying AssignmentCreated event still fires for
        // the realtime store consumer.
        Notification::assertNotSentTo($member, AssignmentCreatedForYou::class);
    }

    public function test_assignment_created_for_self_does_not_notify(): void
    {
        // Self-action: admin assigning a requirement to themselves
        // doesn't generate a notification (they just clicked Save).
        Notification::fake();
        [$org, $admin] = $this->scaffoldOrg();
        $req = Requirement::factory()->for($org, 'organization')->create();

        $this->actingAs($admin)
            ->postJson('/api/assignments', [
                'user_id' => $admin->id,
                'requirement_id' => $req->id,
                'name' => 'Self assigned',
                'initial_only' => true,
                'repeating' => false,
                'as_needed' => false,
                'start_date' => '2026-05-12',
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
            ->postJson('/api/assignments', [
                'user_id' => $member->id,
                'requirement_id' => $req->id,
                'name' => 'OSHA Test',
                'initial_only' => true,
                'repeating' => false,
                'as_needed' => false,
                'start_date' => '2026-05-12',
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
