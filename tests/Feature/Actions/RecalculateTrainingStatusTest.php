<?php

namespace Tests\Feature\Actions;

use App\Actions\RecalculateTrainingStatus;
use App\Models\AssignmentSource;
use App\Models\Completion;
use App\Models\Organization;
use App\Models\Requirement;
use App\Models\RqmtElement;
use App\Models\StdFrequency;
use App\Models\Training;
use App\Models\TrainingAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecalculateTrainingStatusTest extends TestCase
{
    use RefreshDatabase;

    private function makeContext(array $trainingAttrs = []): array
    {
        $org = Organization::factory()->create();
        $user = User::factory()->for($org, 'organization')->create();
        $training = Training::factory()->for($org, 'organization')->create($trainingAttrs);
        $assignment = TrainingAssignment::factory()->create([
            'org_id' => $org->id,
            'user_id' => $user->id,
            'training_id' => $training->id,
        ]);

        return compact('org', 'user', 'training', 'assignment');
    }

    private function freq(Organization $org, int $days): StdFrequency
    {
        return StdFrequency::factory()->for($org, 'organization')->create(['repeat_days' => $days]);
    }

    private function addDirectSource(TrainingAssignment $ta): AssignmentSource
    {
        return AssignmentSource::create([
            'training_assignment_id' => $ta->id,
            'sourceable_type' => null,
            'sourceable_id' => null,
            'added_at' => now(),
        ]);
    }

    /**
     * Creates a requirement + a Training-typed element with the given timing
     * and attaches the requirement as a source on the TA. Returns the element.
     */
    private function addRequirementSource(
        TrainingAssignment $ta,
        Training $training,
        array $elementTiming,
    ): RqmtElement {
        $requirement = Requirement::factory()->create(['org_id' => $ta->org_id]);

        $element = RqmtElement::factory()->create(array_merge([
            'org_id' => $ta->org_id,
            'requirement_id' => $requirement->id,
            'module_type' => Training::class,
            'module_id' => $training->id,
            'initial_only' => false,
            'repeating' => false,
            'std_freq_id' => null,
            'as_needed' => false,
        ], $elementTiming));

        AssignmentSource::create([
            'training_assignment_id' => $ta->id,
            'sourceable_type' => Requirement::class,
            'sourceable_id' => $requirement->id,
            'added_at' => now(),
        ]);

        return $element;
    }

    private function complete(TrainingAssignment $ta, string $date, ?string $expireDate = null): Completion
    {
        return Completion::factory()->create([
            'org_id' => $ta->org_id,
            'user_id' => $ta->user_id,
            'module_type' => Training::class,
            'module_id' => $ta->training_id,
            'completion_date' => $date,
            'expire_date' => $expireDate,
        ]);
    }

    public function test_requirement_source_uses_element_timing_over_training_timing(): void
    {
        ['org' => $org, 'user' => $user, 'training' => $training, 'assignment' => $ta] = $this->makeContext();
        $training->update(['repeating' => true, 'std_freq_id' => $this->freq($org, 365)->id]);
        $this->addRequirementSource($ta, $training, [
            'repeating' => true,
            'std_freq_id' => $this->freq($org, 182)->id,
        ]);
        $this->complete($ta, '2026-01-01');

        (new RecalculateTrainingStatus)->handle($user->id, $training->id);

        // Only source is the requirement → its element's 182-day cycle, not the
        // training template's 365 days.
        $ta->refresh();
        $this->assertEquals('2026-07-02', $ta->expires_at->toDateString());
    }

    public function test_strictest_source_wins_across_requirements_and_direct(): void
    {
        ['org' => $org, 'user' => $user, 'training' => $training, 'assignment' => $ta] = $this->makeContext();
        $training->update(['repeating' => true, 'std_freq_id' => $this->freq($org, 365)->id]);
        $this->addDirectSource($ta); // training template: 365d
        $this->addRequirementSource($ta, $training, [
            'repeating' => true,
            'std_freq_id' => $this->freq($org, 730)->id,
        ]);
        $this->addRequirementSource($ta, $training, [
            'repeating' => true,
            'std_freq_id' => $this->freq($org, 90)->id,
        ]);
        $this->complete($ta, '2026-01-01');

        (new RecalculateTrainingStatus)->handle($user->id, $training->id);

        // Earliest expiry across direct(365) / req(730) / req(90) wins.
        $ta->refresh();
        $this->assertEquals('2026-04-01', $ta->expires_at->toDateString());
    }

    public function test_initial_only_element_contributes_no_expiry(): void
    {
        ['org' => $org, 'user' => $user, 'training' => $training, 'assignment' => $ta] = $this->makeContext();
        $training->update(['repeating' => true, 'std_freq_id' => $this->freq($org, 365)->id]);
        $this->addRequirementSource($ta, $training, ['initial_only' => true]);
        $this->complete($ta, '2026-01-01');

        (new RecalculateTrainingStatus)->handle($user->id, $training->id);

        // The only source's element is initial-only → satisfied forever, even
        // though the training template repeats annually.
        $ta->refresh();
        $this->assertNull($ta->expires_at);
        $this->assertEquals('2026-01-01', $ta->last_completed_at->toDateString());
    }

    public function test_as_needed_element_contributes_no_expiry(): void
    {
        ['org' => $org, 'user' => $user, 'training' => $training, 'assignment' => $ta] = $this->makeContext();
        $training->update(['repeating' => true, 'std_freq_id' => $this->freq($org, 365)->id]);
        $this->addRequirementSource($ta, $training, ['as_needed' => true]);
        $this->complete($ta, '2026-01-01');

        (new RecalculateTrainingStatus)->handle($user->id, $training->id);

        $ta->refresh();
        $this->assertNull($ta->expires_at);
    }

    public function test_removed_sources_are_ignored_for_timing(): void
    {
        ['org' => $org, 'user' => $user, 'training' => $training, 'assignment' => $ta] = $this->makeContext();
        $training->update(['repeating' => true, 'std_freq_id' => $this->freq($org, 365)->id]);
        $this->addDirectSource($ta);
        $this->addRequirementSource($ta, $training, [
            'repeating' => true,
            'std_freq_id' => $this->freq($org, 90)->id,
        ]);
        AssignmentSource::where('sourceable_type', Requirement::class)
            ->where('training_assignment_id', $ta->id)
            ->update(['removed_at' => now()]);
        $this->complete($ta, '2026-01-01');

        (new RecalculateTrainingStatus)->handle($user->id, $training->id);

        // The stricter 90-day requirement source is removed → direct 365 applies.
        $ta->refresh();
        $this->assertEquals('2027-01-01', $ta->expires_at->toDateString());
    }

    public function test_soft_deleted_element_falls_back_to_training_timing(): void
    {
        ['org' => $org, 'user' => $user, 'training' => $training, 'assignment' => $ta] = $this->makeContext();
        $training->update(['repeating' => true, 'std_freq_id' => $this->freq($org, 365)->id]);
        $element = $this->addRequirementSource($ta, $training, [
            'repeating' => true,
            'std_freq_id' => $this->freq($org, 90)->id,
        ]);
        $element->delete();
        $this->complete($ta, '2026-01-01');

        (new RecalculateTrainingStatus)->handle($user->id, $training->id);

        // Element gone from the requirement → source falls back to template.
        $ta->refresh();
        $this->assertEquals('2027-01-01', $ta->expires_at->toDateString());
    }

    public function test_element_with_trashed_frequency_contributes_no_expiry(): void
    {
        ['org' => $org, 'user' => $user, 'training' => $training, 'assignment' => $ta] = $this->makeContext();
        $elementFreq = $this->freq($org, 90);
        $this->addRequirementSource($ta, $training, [
            'repeating' => true,
            'std_freq_id' => $elementFreq->id,
        ]);
        $elementFreq->delete();
        $this->complete($ta, '2026-01-01');

        (new RecalculateTrainingStatus)->handle($user->id, $training->id);

        $ta->refresh();
        $this->assertNull($ta->expires_at);
    }

    public function test_completion_expire_date_overrides_source_timing(): void
    {
        ['org' => $org, 'user' => $user, 'training' => $training, 'assignment' => $ta] = $this->makeContext();
        $this->addRequirementSource($ta, $training, [
            'repeating' => true,
            'std_freq_id' => $this->freq($org, 90)->id,
        ]);
        $this->complete($ta, '2026-01-01', '2028-12-31');

        (new RecalculateTrainingStatus)->handle($user->id, $training->id);

        // Explicit cert expiry beats every computed source cycle.
        $ta->refresh();
        $this->assertEquals('2028-12-31', $ta->expires_at->toDateString());
    }

    public function test_leaves_both_null_when_no_completions_exist(): void
    {
        ['user' => $user, 'training' => $training, 'assignment' => $assignment] = $this->makeContext();

        (new RecalculateTrainingStatus)->handle($user->id, $training->id);

        $assignment->refresh();
        $this->assertNull($assignment->expires_at);
        $this->assertNull($assignment->last_completed_at);
    }

    public function test_sets_last_completed_at_and_uses_explicit_expire_date(): void
    {
        ['org' => $org, 'user' => $user, 'training' => $training, 'assignment' => $assignment]
            = $this->makeContext();

        Completion::factory()->create([
            'org_id' => $org->id,
            'user_id' => $user->id,
            'module_type' => Training::class,
            'module_id' => $training->id,
            'completion_date' => '2026-01-10',
            'expire_date' => '2027-06-30',
        ]);

        (new RecalculateTrainingStatus)->handle($user->id, $training->id);

        $assignment->refresh();
        $this->assertEquals('2026-01-10', $assignment->last_completed_at->toDateString());
        $this->assertEquals('2027-06-30', $assignment->expires_at->toDateString());
    }

    public function test_computes_expires_at_from_std_frequency_when_no_expire_date(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->for($org, 'organization')->create();
        $freq = StdFrequency::factory()->for($org, 'organization')->create(['repeat_days' => 365]);
        $training = Training::factory()->for($org, 'organization')->create([
            'repeating' => true,
            'std_freq_id' => $freq->id,
        ]);
        $assignment = TrainingAssignment::factory()->create([
            'org_id' => $org->id,
            'user_id' => $user->id,
            'training_id' => $training->id,
        ]);

        Completion::factory()->create([
            'org_id' => $org->id,
            'user_id' => $user->id,
            'module_type' => Training::class,
            'module_id' => $training->id,
            'completion_date' => '2026-01-01',
            'expire_date' => null,
        ]);

        (new RecalculateTrainingStatus)->handle($user->id, $training->id);

        $assignment->refresh();
        $this->assertEquals('2026-01-01', $assignment->last_completed_at->toDateString());
        $this->assertEquals('2027-01-01', $assignment->expires_at->toDateString());
    }

    public function test_expires_at_is_null_for_initial_only_training_after_completion(): void
    {
        ['org' => $org, 'user' => $user, 'training' => $training, 'assignment' => $assignment]
            = $this->makeContext(['initial_only' => true]);

        Completion::factory()->create([
            'org_id' => $org->id,
            'user_id' => $user->id,
            'module_type' => Training::class,
            'module_id' => $training->id,
            'completion_date' => '2026-03-01',
            'expire_date' => null,
        ]);

        (new RecalculateTrainingStatus)->handle($user->id, $training->id);

        $assignment->refresh();
        $this->assertEquals('2026-03-01', $assignment->last_completed_at->toDateString());
        $this->assertNull($assignment->expires_at);
    }

    public function test_trashed_std_frequency_falls_back_to_no_expiry(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->for($org, 'organization')->create();
        $freq = StdFrequency::factory()->for($org, 'organization')->create(['repeat_days' => 365]);
        $training = Training::factory()->for($org, 'organization')->create([
            'repeating' => true,
            'std_freq_id' => $freq->id,
        ]);
        $assignment = TrainingAssignment::factory()->create([
            'org_id' => $org->id,
            'user_id' => $user->id,
            'training_id' => $training->id,
        ]);

        Completion::factory()->create([
            'org_id' => $org->id,
            'user_id' => $user->id,
            'module_type' => Training::class,
            'module_id' => $training->id,
            'completion_date' => '2026-01-01',
            'expire_date' => null,
        ]);

        $freq->delete();

        (new RecalculateTrainingStatus)->handle($user->id, $training->id);

        $assignment->refresh();
        $this->assertEquals('2026-01-01', $assignment->last_completed_at->toDateString());
        $this->assertNull($assignment->expires_at);
    }

    public function test_uses_most_recent_completion_when_multiple_exist(): void
    {
        ['org' => $org, 'user' => $user, 'training' => $training, 'assignment' => $assignment]
            = $this->makeContext();

        Completion::factory()->create([
            'org_id' => $org->id,
            'user_id' => $user->id,
            'module_type' => Training::class,
            'module_id' => $training->id,
            'completion_date' => '2025-01-01',
            'expire_date' => '2026-01-01',
        ]);
        Completion::factory()->create([
            'org_id' => $org->id,
            'user_id' => $user->id,
            'module_type' => Training::class,
            'module_id' => $training->id,
            'completion_date' => '2026-06-01',
            'expire_date' => '2027-06-01',
        ]);

        (new RecalculateTrainingStatus)->handle($user->id, $training->id);

        $assignment->refresh();
        $this->assertEquals('2026-06-01', $assignment->last_completed_at->toDateString());
        $this->assertEquals('2027-06-01', $assignment->expires_at->toDateString());
    }

    public function test_resets_to_null_after_completion_is_soft_deleted(): void
    {
        ['org' => $org, 'user' => $user, 'training' => $training, 'assignment' => $assignment]
            = $this->makeContext();

        $completion = Completion::factory()->create([
            'org_id' => $org->id,
            'user_id' => $user->id,
            'module_type' => Training::class,
            'module_id' => $training->id,
            'completion_date' => '2026-01-01',
            'expire_date' => '2027-01-01',
        ]);

        (new RecalculateTrainingStatus)->handle($user->id, $training->id);
        $completion->delete();
        (new RecalculateTrainingStatus)->handle($user->id, $training->id);

        $assignment->refresh();
        $this->assertNull($assignment->expires_at);
        $this->assertNull($assignment->last_completed_at);
    }

    public function test_only_updates_assignments_for_the_given_user(): void
    {
        ['org' => $org, 'training' => $training] = $this->makeContext();
        $otherUser = User::factory()->for($org, 'organization')->create();
        $otherAssignment = TrainingAssignment::factory()->create([
            'org_id' => $org->id,
            'user_id' => $otherUser->id,
            'training_id' => $training->id,
            'last_completed_at' => null,
        ]);

        // Completion only for otherUser
        Completion::factory()->create([
            'org_id' => $org->id,
            'user_id' => $otherUser->id,
            'module_type' => Training::class,
            'module_id' => $training->id,
            'completion_date' => '2026-05-01',
            'expire_date' => '2027-05-01',
        ]);

        $user = User::factory()->for($org, 'organization')->create();
        $userAssignment = TrainingAssignment::factory()->create([
            'org_id' => $org->id,
            'user_id' => $user->id,
            'training_id' => $training->id,
            'last_completed_at' => null,
        ]);

        // Only recalculate for otherUser — userAssignment must stay untouched
        (new RecalculateTrainingStatus)->handle($otherUser->id, $training->id);

        $userAssignment->refresh();
        $this->assertNull($userAssignment->last_completed_at);

        $otherAssignment->refresh();
        $this->assertEquals('2026-05-01', $otherAssignment->last_completed_at->toDateString());
    }
}
