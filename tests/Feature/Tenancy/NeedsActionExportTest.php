<?php

namespace Tests\Feature\Tenancy;

use App\Models\Organization;
use App\Models\Training;
use App\Models\TrainingAssignment;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Spatie\LaravelPdf\Facades\Pdf;
use Tests\TestCase;

/**
 * PDF of the dashboard's needs-action list. The widget answers "who needs
 * chasing"; this is the copy you take into the room.
 *
 * It reuses the widget's own query so the sheet cannot disagree with the
 * screen — including the grouping toggle, which the widget applied purely
 * client-side and therefore never sent anywhere.
 */
class NeedsActionExportTest extends TestCase
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

    private function assignment(
        Organization $org,
        User $user,
        Training $training,
        string $status,
        ?string $expires = null,
    ): TrainingAssignment {
        return TrainingAssignment::factory()->for($org, 'organization')->create([
            'user_id' => $user->id,
            'training_id' => $training->id,
            'name' => $training->name,
            'status' => $status,
            'expires_at' => $expires,
        ]);
    }

    public function test_manager_can_export_the_needs_action_list(): void
    {
        $org = Organization::factory()->create();
        $manager = $this->manager($org);
        $student = User::factory()->for($org, 'organization')->create([
            'f_name' => 'Sam', 'l_name' => 'Lee',
        ]);
        $training = Training::factory()->for($org, 'organization')->create(['name' => 'CPR']);
        $this->assignment($org, $student, $training, 'overdue', '2026-01-01');

        $this->actingAs($manager)
            ->get(route('dashboard.needs-action.export'))
            ->assertOk();

        Pdf::assertRespondedWithPdf(
            fn ($pdf) => $pdf->viewName === 'pdf.report'
                && $pdf->viewData['title'] === 'Needs action'
                && (new Collection($pdf->viewData['rows']))->contains(
                    fn (array $r) => $r['user'] === 'Lee, Sam'
                        && $r['training'] === 'CPR'
                        && $r['status'] === 'Overdue',
                ),
        );
    }

    public function test_rows_carry_the_days_overdue_or_until_due(): void
    {
        // The column the request was actually about: "days overdue → until
        // due". Overdue reads negative days, so the sheet says "N days
        // overdue" rather than making the reader interpret a minus sign.
        $org = Organization::factory()->create();
        $manager = $this->manager($org);
        $student = User::factory()->for($org, 'organization')->create();
        $training = Training::factory()->for($org, 'organization')->create();
        $this->assignment($org, $student, $training, 'overdue', now()->subDays(10)->toDateString());
        $other = User::factory()->for($org, 'organization')->create();
        $this->assignment($org, $other, $training, 'due_soon', now()->addDays(5)->toDateString());

        $this->actingAs($manager)->get(route('dashboard.needs-action.export'))->assertOk();

        Pdf::assertRespondedWithPdf(function ($pdf) {
            $due = (new Collection($pdf->viewData['rows']))->pluck('due');

            return $due->contains(fn ($v) => str_contains((string) $v, 'overdue'))
                && $due->contains(fn ($v) => str_contains((string) $v, 'due in'));
        });
    }

    public function test_status_filter_narrows_the_sheet_like_the_widget(): void
    {
        $org = Organization::factory()->create();
        $manager = $this->manager($org);
        $a = User::factory()->for($org, 'organization')->create(['f_name' => 'Ann', 'l_name' => 'Over']);
        $b = User::factory()->for($org, 'organization')->create(['f_name' => 'Ben', 'l_name' => 'Soon']);
        $training = Training::factory()->for($org, 'organization')->create();
        $this->assignment($org, $a, $training, 'overdue', '2026-01-01');
        $this->assignment($org, $b, $training, 'due_soon', '2099-01-01');

        $this->actingAs($manager)
            ->get(route('dashboard.needs-action.export', ['status' => 'overdue']))
            ->assertOk();

        Pdf::assertRespondedWithPdf(function ($pdf) {
            $users = (new Collection($pdf->viewData['rows']))->pluck('user');

            return $users->contains('Over, Ann') && ! $users->contains('Soon, Ben');
        });
    }

    public function test_search_narrows_the_sheet(): void
    {
        $org = Organization::factory()->create();
        $manager = $this->manager($org);
        $student = User::factory()->for($org, 'organization')->create();
        $cpr = Training::factory()->for($org, 'organization')->create(['name' => 'CPR']);
        $forklift = Training::factory()->for($org, 'organization')->create(['name' => 'Forklift']);
        $this->assignment($org, $student, $cpr, 'overdue', '2026-01-01');
        $this->assignment($org, $student, $forklift, 'overdue', '2026-01-01');

        $this->actingAs($manager)
            ->get(route('dashboard.needs-action.export', ['q' => 'forklift']))
            ->assertOk();

        Pdf::assertRespondedWithPdf(function ($pdf) {
            $names = (new Collection($pdf->viewData['rows']))->pluck('training');

            return $names->contains('Forklift') && ! $names->contains('CPR');
        });
    }

    public function test_group_by_reaches_the_server_instead_of_staying_on_screen(): void
    {
        // The widget's user|training toggle was applied client-side over the
        // fetched page and never sent anywhere, so a PDF that ignored it would
        // silently disagree with the screen it was printed from.
        $org = Organization::factory()->create();
        $manager = $this->manager($org);
        $student = User::factory()->for($org, 'organization')->create();
        $training = Training::factory()->for($org, 'organization')->create(['name' => 'CPR']);
        $this->assignment($org, $student, $training, 'overdue', '2026-01-01');

        $this->actingAs($manager)
            ->get(route('dashboard.needs-action.export', ['group_by' => ['training']]))
            ->assertOk();

        Pdf::assertRespondedWithPdf(
            fn ($pdf) => $pdf->viewData['groups'] !== [],
        );
    }

    public function test_the_sheet_states_the_filters_it_was_run_with(): void
    {
        $org = Organization::factory()->create();
        $manager = $this->manager($org);

        $this->actingAs($manager)
            ->get(route('dashboard.needs-action.export', ['status' => 'overdue', 'q' => 'cpr']))
            ->assertOk();

        Pdf::assertRespondedWithPdf(
            fn ($pdf) => str_contains((string) $pdf->viewData['filters'], 'Overdue')
                && str_contains((string) $pdf->viewData['filters'], 'cpr'),
        );
    }

    public function test_it_is_scoped_to_the_actors_org(): void
    {
        $org = Organization::factory()->create();
        $otherOrg = Organization::factory()->create();
        $manager = $this->manager($org);
        $foreignUser = User::factory()->for($otherOrg, 'organization')->create([
            'f_name' => 'Foreign', 'l_name' => 'Person',
        ]);
        $foreignTraining = Training::factory()->for($otherOrg, 'organization')->create();
        $this->assignment($otherOrg, $foreignUser, $foreignTraining, 'overdue', '2026-01-01');

        $this->actingAs($manager)->get(route('dashboard.needs-action.export'))->assertOk();

        Pdf::assertRespondedWithPdf(
            fn ($pdf) => ! (new Collection($pdf->viewData['rows']))
                ->pluck('user')->contains('Person, Foreign'),
        );
    }

    public function test_a_self_only_user_cannot_export(): void
    {
        $org = Organization::factory()->create();
        $member = User::factory()->for($org, 'organization')->withRole('None')->create();

        $this->actingAs($member)
            ->getJson(route('dashboard.needs-action.export'))
            ->assertForbidden();
    }

    public function test_guest_is_redirected(): void
    {
        $this->get(route('dashboard.needs-action.export'))->assertRedirect(route('login'));
    }
}
