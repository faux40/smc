<?php

namespace App\Http\Controllers;

use App\Events\UserRegistered;
use App\Events\UserSoftDeleted;
use App\Events\UserStatusChanged;
use App\Events\UserUpdated;
use App\Http\Requests\CreateUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Models\User;
use App\Services\UserComplianceCalculator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Redirect;
use Inertia\Inertia;
use Inertia\Response;

class UsersController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', User::class);

        $search = trim((string) $request->query('q', ''));
        $roleFilter = trim((string) $request->query('role', ''));
        $includeDisabled = filter_var($request->query('include_disabled', false), FILTER_VALIDATE_BOOLEAN);

        $users = User::query()
            ->with('roles:id,name')
            ->when(! $includeDisabled, fn ($q) => $q->where('status', 'active'))
            ->when($search !== '', fn ($q) => $q->where(function ($inner) use ($search) {
                $inner->where('f_name', 'like', "%{$search}%")
                    ->orWhere('m_name', 'like', "%{$search}%")
                    ->orWhere('l_name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            }))
            ->orderBy('l_name')
            ->orderBy('f_name')
            ->get(['id', 'org_id', 'f_name', 'm_name', 'l_name', 'prefix_name', 'suffix_name', 'email', 'status', 'created_at'])
            ->when($roleFilter !== '', fn ($collection) => $collection->filter(
                fn (User $u) => $u->roles->contains('name', $roleFilter),
            )->values())
            ->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'f_name' => $u->f_name,
                'm_name' => $u->m_name,
                'l_name' => $u->l_name,
                'prefix_name' => $u->prefix_name,
                'suffix_name' => $u->suffix_name,
                'email' => $u->email,
                'status' => $u->status,
                'role' => $u->roles->first()?->name,
                'created_at' => $u->created_at?->toDateTimeString(),
                'can_edit' => Gate::check('update', $u),
                'can_disable' => Gate::check('disable', $u),
                'can_delete' => Gate::check('delete', $u),
            ]);

        return Inertia::render('users/Index', [
            'users' => $users,
            'filters' => [
                'q' => $search,
                'role' => $roleFilter,
                'include_disabled' => $includeDisabled,
            ],
            'can_create' => Gate::check('create', User::class),
        ]);
    }

    /**
     * Lean JSON list of active org users for downstream picker UX
     * (assignment / completion form modals). Manager+ gate matches the
     * widened Assignment / Completion policies — the people who can
     * pick users in those workflows.
     */
    public function pickerList(Request $request): JsonResponse
    {
        abort_unless(
            $request->user()->hasAnyRole(['Owner', 'SuperAdmin', 'Admin', 'Manager']),
            403,
        );

        $users = User::query()
            ->where('status', 'active')
            ->orderBy('l_name')
            ->orderBy('f_name')
            ->get(['id', 'f_name', 'l_name', 'email']);

        return response()->json($users->map(fn (User $u) => [
            'id' => $u->id,
            'name' => $u->name,
            'f_name' => $u->f_name,
            'l_name' => $u->l_name,
            'email' => $u->email,
        ]));
    }

    /**
     * Per-user detail page (Phase 13.3). Renders the Inertia shell with
     * the basic user header; compliance + completion timelines stream
     * in via the JSON `compliance()` endpoint on mount.
     */
    public function show(User $user): Response
    {
        Gate::authorize('viewDetail', $user);

        return Inertia::render('users/Show', [
            'subject' => [
                'id' => $user->id,
                'name' => $user->name,
                'f_name' => $user->f_name,
                'm_name' => $user->m_name,
                'l_name' => $user->l_name,
                'prefix_name' => $user->prefix_name,
                'suffix_name' => $user->suffix_name,
                'email' => $user->email,
                'status' => $user->status,
                'role' => $user->roles->first()?->name,
            ],
        ]);
    }

    /**
     * JSON compliance payload for the user detail page. Groups
     * assignments by status (overdue / due_soon / current /
     * never_started / inactive) and returns the full completion
     * history (per the v15 "credit for unassigned" path stays visible).
     */
    public function compliance(User $user, UserComplianceCalculator $calculator): JsonResponse
    {
        Gate::authorize('viewDetail', $user);

        $payload = $calculator->compute($user);

        return response()->json($payload);
    }

    public function store(CreateUserRequest $request): RedirectResponse
    {
        $user = User::create([
            'org_id' => $request->user()->org_id,
            'f_name' => $request->validated('f_name'),
            'm_name' => $request->validated('m_name'),
            'l_name' => $request->validated('l_name'),
            'prefix_name' => $request->validated('prefix_name'),
            'suffix_name' => $request->validated('suffix_name'),
            'email' => $request->validated('email'),
            'password' => null,
        ]);

        $user->assignRole('None');

        event(new UserRegistered($user->fresh()));

        return Redirect::route('users.index');
    }

    public function update(UpdateUserRequest $request, User $user): RedirectResponse
    {
        $data = $request->validated();

        $user->update([
            'f_name' => $data['f_name'],
            'm_name' => $data['m_name'] ?? null,
            'l_name' => $data['l_name'],
            'prefix_name' => $data['prefix_name'] ?? null,
            'suffix_name' => $data['suffix_name'] ?? null,
            'email' => $data['email'] ?? null,
            'status' => $data['status'],
        ]);

        // role is absent from $data for Owner targets (the request
        // rule is `prohibited` then); skip syncRoles so the Owner
        // role stays intact. Otherwise syncRoles replaces all roles
        // atomically to match the one-role-per-user invariant.
        if (isset($data['role'])) {
            $user->syncRoles([$data['role']]);
        }

        event(new UserUpdated($user->fresh()->load('roles:id,name')));

        return Redirect::route('users.index');
    }

    public function disable(User $user): RedirectResponse
    {
        Gate::authorize('disable', $user);

        $user->update(['status' => 'disabled']);

        event(new UserStatusChanged($user->fresh()));

        return Redirect::route('users.index');
    }

    public function enable(User $user): RedirectResponse
    {
        Gate::authorize('enable', $user);

        $user->update(['status' => 'active']);

        event(new UserStatusChanged($user->fresh()));

        return Redirect::route('users.index');
    }

    public function destroy(User $user): RedirectResponse
    {
        Gate::authorize('delete', $user);

        $user->delete();

        event(new UserSoftDeleted($user));

        return Redirect::route('users.index');
    }
}
