<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
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
                $inner->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            }))
            ->orderBy('name')
            ->get(['id', 'org_id', 'name', 'email', 'status', 'created_at'])
            ->when($roleFilter !== '', fn ($collection) => $collection->filter(
                fn (User $u) => $u->roles->contains('name', $roleFilter),
            )->values())
            ->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
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
}
