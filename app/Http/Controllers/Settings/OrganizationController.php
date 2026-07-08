<?php

namespace App\Http\Controllers\Settings;

use App\Events\OrganizationDeleted;
use App\Events\OrganizationUpdated;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\OrganizationDeleteRequest;
use App\Http\Requests\Settings\OrganizationUpdateRequest;
use App\Jobs\ResyncOrgTrainingStatus;
use App\Models\Organization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class OrganizationController extends Controller
{
    public function edit(Request $request): Response
    {
        $user = $request->user();
        $org = Organization::findOrFail($user->org_id);

        return Inertia::render('settings/Organization', [
            'organization' => [
                'id' => $org->id,
                'name' => $org->name,
                'timezone' => $org->timezone,
                'due_soon_days' => $org->training_thresholds['due_soon_days'] ?? null,
                'expiring_soon_days' => $org->training_thresholds['expiring_soon_days'] ?? null,
                'overdue_reminder_interval_days' => $org->overdue_reminder_interval_days,
            ],
            'isOwner' => $user->hasRole('Owner'),
            // Full IANA identifier list for the timezone picker.
            'timezones' => \DateTimeZone::listIdentifiers(),
        ]);
    }

    public function update(OrganizationUpdateRequest $request): RedirectResponse
    {
        $org = Organization::findOrFail($request->user()->org_id);

        // The amber window feeds every materialized TA status, so remember it
        // before the write to decide whether a resync is needed (F1 follow-up).
        $oldWindow = $org->expiringSoonDays();

        $dueSoon = $request->validated('due_soon_days');
        $expiringSoon = $request->validated('expiring_soon_days');
        $thresholds = array_filter([
            'due_soon_days' => $dueSoon,
            'expiring_soon_days' => $expiringSoon,
        ], fn ($v) => $v !== null);

        // Blank / 0 both mean "disabled" — normalise to null so the accessor
        // and the watchdog only ever see null or a positive day count.
        $interval = $request->validated('overdue_reminder_interval_days');

        $org->update([
            'name' => $request->validated('name'),
            'timezone' => $request->validated('timezone'),
            'training_thresholds' => $thresholds ?: null,
            'overdue_reminder_interval_days' => $interval ?: null,
        ]);

        // A widened/narrowed expiring-soon window shifts due_soon⇄current
        // boundaries; re-materialize the org's statuses off-request so the
        // dashboard reflects it now instead of at the next nightly watchdog.
        if ($org->fresh()->expiringSoonDays() !== $oldWindow) {
            ResyncOrgTrainingStatus::dispatch($org->id);
        }

        event(new OrganizationUpdated($org->fresh()));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Organization updated.')]);

        return to_route('organization.edit');
    }

    public function destroy(OrganizationDeleteRequest $request): RedirectResponse
    {
        $org = Organization::findOrFail($request->user()->org_id);

        // Cascade soft-delete: every user in the org goes too. SoftDeletes
        // scope on User makes them auth-invisible on the next request, which
        // logs out any peer tabs without a special force-logout signal.
        DB::transaction(function () use ($org): void {
            $org->users()->each(fn ($u) => $u->delete());
            $org->delete();
        });

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        event(new OrganizationDeleted($org));

        return redirect('/');
    }
}
