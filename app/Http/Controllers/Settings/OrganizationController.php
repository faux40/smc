<?php

namespace App\Http\Controllers\Settings;

use App\Events\OrganizationDeleted;
use App\Events\OrganizationUpdated;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\OrganizationDeleteRequest;
use App\Http\Requests\Settings\OrganizationUpdateRequest;
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
            ],
            'isOwner' => $user->hasRole('Owner'),
            // Full IANA identifier list for the timezone picker.
            'timezones' => \DateTimeZone::listIdentifiers(),
        ]);
    }

    public function update(OrganizationUpdateRequest $request): RedirectResponse
    {
        $org = Organization::findOrFail($request->user()->org_id);
        $org->update($request->validated());

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
