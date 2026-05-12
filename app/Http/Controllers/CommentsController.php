<?php

namespace App\Http\Controllers;

use App\Events\CommentCreated;
use App\Events\CommentDeleted;
use App\Events\CommentUpdated;
use App\Models\Comment;
use App\Models\Training;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class CommentsController extends Controller
{
    /**
     * Morphable whitelist. New HasComments consumers append here.
     */
    private const ALLOWED_COMMENTABLE_TYPES = [
        User::class,
        Training::class,
    ];

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'commentable_type' => ['required', 'string', Rule::in(self::ALLOWED_COMMENTABLE_TYPES)],
            'commentable_id' => ['required', 'string'],
        ]);

        $this->authorizeSameOrgMorphable($data['commentable_type'], $data['commentable_id']);

        $comments = Comment::query()
            ->where('commentable_type', $data['commentable_type'])
            ->where('commentable_id', $data['commentable_id'])
            ->with('author:id,f_name,l_name')
            ->orderBy('created_at')
            ->get(['id', 'commentable_type', 'commentable_id', 'author_id', 'parent_id', 'body', 'created_at']);

        return response()->json($comments->map(fn (Comment $c) => [
            'id' => $c->id,
            'commentable_type' => $c->commentable_type,
            'commentable_id' => $c->commentable_id,
            'author_id' => $c->author_id,
            'author_name' => $c->author?->name,
            'parent_id' => $c->parent_id,
            'body' => $c->body,
            'created_at' => $c->created_at?->toDateTimeString(),
            'can_edit' => Gate::check('update', $c),
            'can_delete' => Gate::check('delete', $c),
        ]));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'commentable_type' => ['required', 'string', Rule::in(self::ALLOWED_COMMENTABLE_TYPES)],
            'commentable_id' => ['required', 'string'],
            'parent_id' => ['nullable', 'string', 'exists:comments,id'],
            'body' => ['required', 'string', 'max:10000'],
        ]);

        $this->authorizeSameOrgMorphable($data['commentable_type'], $data['commentable_id']);

        $comment = Comment::create([
            'org_id' => Auth::user()->org_id,
            'commentable_type' => $data['commentable_type'],
            'commentable_id' => $data['commentable_id'],
            'author_id' => Auth::id(),
            'parent_id' => $data['parent_id'] ?? null,
            'body' => $data['body'],
        ]);

        event(new CommentCreated($comment));

        return response()->json([
            'id' => $comment->id,
            'body' => $comment->body,
        ], 201);
    }

    public function update(Request $request, Comment $comment): JsonResponse
    {
        Gate::authorize('update', $comment);

        $data = $request->validate([
            'body' => ['required', 'string', 'max:10000'],
        ]);

        $comment->update($data);

        event(new CommentUpdated($comment->fresh()));

        return response()->json(['id' => $comment->id, 'body' => $comment->body]);
    }

    public function destroy(Comment $comment): JsonResponse
    {
        Gate::authorize('delete', $comment);

        $id = $comment->id;
        $orgId = $comment->org_id;
        $comment->delete();

        event(new CommentDeleted($id, $orgId));

        return response()->json(['ok' => true]);
    }

    /**
     * Both ends (actor + morphable) must be in the same org. The
     * commentable model is fetched un-scoped so the same-org check is
     * authoritative — otherwise the global scope would convert an auth
     * failure into a 404.
     */
    private function authorizeSameOrgMorphable(string $type, string $id): void
    {
        /** @var class-string<Model> $type */
        $morphable = $type::query()->withoutGlobalScope('organization')->find($id);
        abort_if($morphable === null, 404, 'Commentable not found.');

        abort_unless($morphable->org_id === Auth::user()->org_id, 403);
    }
}
