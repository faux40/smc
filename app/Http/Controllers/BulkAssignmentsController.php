<?php

namespace App\Http\Controllers;

use App\Events\AssignmentCreated;
use App\Http\Requests\BulkAssignmentRequest;
use App\Models\Assignment;
use App\Models\Requirement;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Tag-driven bulk-assignment endpoint (Phase 13.1, the "flagship" flow).
 *
 * preview() returns the user × requirement cross-product implied by a
 * tag plus the existing assignments inside that cross-product so the
 * frontend can pre-lock cells. store() takes a hand-picked pair[] list
 * and creates the missing assignments in one transaction, emitting one
 * AssignmentCreated broadcast per new row.
 */
class BulkAssignmentsController extends Controller
{
    public function preview(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Assignment::class);

        $data = $request->validate([
            'tag_id' => ['required', 'string'],
        ]);

        $orgId = Auth::user()->org_id;

        // Look up the tag un-scoped so cross-org access returns a clean
        // 403, not a 404 that leaks existence.
        $tag = Tag::query()
            ->withoutGlobalScope('organization')
            ->whereNull('deleted_at')
            ->find($data['tag_id']);
        abort_if($tag === null, 404, 'Tag not found.');
        abort_unless($tag->org_id === $orgId, 403);

        $users = User::query()
            ->whereHas('tags', fn ($q) => $q->where('tags.id', $tag->id))
            ->orderBy('l_name')
            ->orderBy('f_name')
            ->get(['id', 'f_name', 'l_name', 'email']);

        $requirements = Requirement::query()
            ->whereHas('tags', fn ($q) => $q->where('tags.id', $tag->id))
            ->orderBy('name')
            ->get(['id', 'name', 'description']);

        $existing = [];
        if ($users->isNotEmpty() && $requirements->isNotEmpty()) {
            $existing = Assignment::query()
                ->whereIn('user_id', $users->pluck('id'))
                ->whereIn('requirement_id', $requirements->pluck('id'))
                ->get(['user_id', 'requirement_id'])
                ->map(fn (Assignment $a) => [
                    'user_id' => $a->user_id,
                    'requirement_id' => $a->requirement_id,
                ])
                ->all();
        }

        return response()->json([
            'tag' => [
                'id' => $tag->id,
                'name' => $tag->name,
                'color' => $tag->color,
            ],
            'users' => $users->map(fn (User $u) => [
                'id' => $u->id,
                'f_name' => $u->f_name,
                'l_name' => $u->l_name,
                'email' => $u->email,
            ]),
            'requirements' => $requirements->map(fn (Requirement $r) => [
                'id' => $r->id,
                'name' => $r->name,
                'description' => $r->description,
            ]),
            'existing_pairs' => $existing,
        ]);
    }

    public function store(BulkAssignmentRequest $request): JsonResponse
    {
        // Authz ran in BulkAssignmentRequest::authorize().
        $data = $request->validated();
        $orgId = Auth::user()->org_id;

        // Dedupe within the request payload — same pair sent twice
        // should be created once, not throw later.
        $pairs = collect($data['pairs'])
            ->map(fn ($p) => ['user_id' => $p['user_id'], 'requirement_id' => $p['requirement_id']])
            ->unique(fn ($p) => $p['user_id'].'|'.$p['requirement_id']);

        // Skip pairs that already have an assignment. Single query
        // rather than per-pair existence checks.
        $existing = Assignment::query()
            ->whereIn('user_id', $pairs->pluck('user_id')->unique())
            ->whereIn('requirement_id', $pairs->pluck('requirement_id')->unique())
            ->get(['user_id', 'requirement_id'])
            ->map(fn (Assignment $a) => $a->user_id.'|'.$a->requirement_id)
            ->all();

        $toCreate = $pairs->reject(fn ($p) => in_array($p['user_id'].'|'.$p['requirement_id'], $existing, true));

        if ($toCreate->isEmpty()) {
            return response()->json([
                'created_count' => 0,
                'skipped_count' => $pairs->count(),
            ]);
        }

        // Cache requirement rows so name + description copy on each
        // assignment matches the source even if it churns later.
        $requirements = Requirement::query()
            ->whereIn('id', $toCreate->pluck('requirement_id')->unique())
            ->get()
            ->keyBy('id');

        $repeating = (bool) $data['repeating'];
        $newAssignments = [];

        DB::transaction(function () use ($orgId, $toCreate, $requirements, $data, $repeating, &$newAssignments) {
            foreach ($toCreate as $pair) {
                /** @var Requirement $req */
                $req = $requirements[$pair['requirement_id']];
                $newAssignments[] = Assignment::create([
                    'org_id' => $orgId,
                    'user_id' => $pair['user_id'],
                    'requirement_id' => $req->id,
                    'name' => $req->name,
                    'description' => $req->description,
                    'initial_only' => (bool) $data['initial_only'],
                    'repeating' => $repeating,
                    'std_freq_id' => $repeating ? $data['std_freq_id'] : null,
                    'as_needed' => (bool) $data['as_needed'],
                    'start_date' => $data['start_date'],
                    'end_date' => $data['end_date'] ?? null,
                ]);
            }
        });

        // Broadcast outside the transaction so subscribers don't get
        // a "phantom" assignment if the transaction rolls back.
        // fromBulk=true tells the notification listener to skip per-row
        // inbox entries (admin running bulk would otherwise spam each
        // user with N notifications).
        foreach ($newAssignments as $assignment) {
            event(new AssignmentCreated($assignment, actorId: Auth::id(), fromBulk: true));
        }

        return response()->json([
            'created_count' => count($newAssignments),
            'skipped_count' => $pairs->count() - count($newAssignments),
        ], 201);
    }
}
