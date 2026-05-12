<?php

namespace App\Http\Controllers;

use App\Events\TagAttached;
use App\Events\TagCreated;
use App\Events\TagDeleted;
use App\Events\TagDetached;
use App\Events\TagUpdated;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class TagsController extends Controller
{
    /**
     * Morphable whitelist. New HasTags consumers append here as their
     * consumer phases land.
     */
    private const ALLOWED_TAGGABLE_TYPES = [
        User::class,
    ];

    /**
     * Optional 7-char #RRGGBB hex. Nullable for plain tags.
     */
    private const COLOR_RULES = ['nullable', 'string', 'max:7', 'regex:/^#[0-9a-fA-F]{6}$/'];

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Tag::class);

        $tags = Tag::query()
            ->where('org_id', $request->user()->org_id)
            ->orderBy('name')
            ->get(['id', 'name', 'color']);

        return response()->json($tags);
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('create', Tag::class);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'color' => self::COLOR_RULES,
        ]);

        $tag = Tag::create([
            'org_id' => $request->user()->org_id,
            'name' => $data['name'],
            'color' => $data['color'] ?? null,
        ]);

        event(new TagCreated($tag));

        return response()->json($tag->only(['id', 'name', 'color']), 201);
    }

    public function update(Request $request, Tag $tag): JsonResponse
    {
        Gate::authorize('update', $tag);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'color' => self::COLOR_RULES,
        ]);

        $tag->update($data);

        event(new TagUpdated($tag->fresh()));

        return response()->json($tag->only(['id', 'name', 'color']));
    }

    public function destroy(Tag $tag): JsonResponse
    {
        Gate::authorize('delete', $tag);

        // Soft-delete on tags means the FK cascade on taggables.tag_id won't
        // fire (the row sticks around with deleted_at set). Explicitly clear
        // the pivot so the tag's attachments disappear from morphables now;
        // restoring the tag later (if/when we add restore tooling) would not
        // bring the old attachments back, which is the desired UX.
        $orgId = $tag->org_id;
        $id = $tag->id;
        DB::table('taggables')->where('tag_id', $id)->delete();
        $tag->delete();

        event(new TagDeleted($id, $orgId));

        return response()->json(['ok' => true]);
    }

    /**
     * Attach an existing tag to a morphable. Same-org check on both ends.
     * Tags are descriptive, not access-control — no role gate beyond
     * being an authenticated org member.
     */
    public function attach(Request $request): JsonResponse
    {
        $data = $this->validateAttachPayload($request);

        [$tag, $morphable] = $this->resolveAndAuthorize($data);

        $morphable->tags()->syncWithoutDetaching([$tag->id]);

        event(new TagAttached(
            orgId: $tag->org_id,
            tagId: $tag->id,
            taggableType: $data['taggable_type'],
            taggableId: $data['taggable_id'],
        ));

        return response()->json(['ok' => true]);
    }

    public function detach(Request $request): JsonResponse
    {
        $data = $this->validateAttachPayload($request);

        [$tag, $morphable] = $this->resolveAndAuthorize($data);

        $morphable->tags()->detach($tag->id);

        event(new TagDetached(
            orgId: $tag->org_id,
            tagId: $tag->id,
            taggableType: $data['taggable_type'],
            taggableId: $data['taggable_id'],
        ));

        return response()->json(['ok' => true]);
    }

    /**
     * @return array{tag_id: string, taggable_type: string, taggable_id: string}
     */
    private function validateAttachPayload(Request $request): array
    {
        return $request->validate([
            'tag_id' => ['required', 'string'],
            'taggable_type' => ['required', 'string', Rule::in(self::ALLOWED_TAGGABLE_TYPES)],
            'taggable_id' => ['required', 'string'],
        ]);
    }

    /**
     * @param  array{tag_id: string, taggable_type: string, taggable_id: string}  $data
     * @return array{0: Tag, 1: Model}
     */
    private function resolveAndAuthorize(array $data): array
    {
        // Look up un-scoped so the same-org check below is the authoritative
        // gate. The global org scope would otherwise hide cross-org rows and
        // turn an authorization failure into a 404.
        $tag = Tag::query()->withoutGlobalScope('organization')->find($data['tag_id']);
        abort_if($tag === null, 404, 'Tag not found.');

        /** @var class-string<Model> $type */
        $type = $data['taggable_type'];
        $morphable = $type::query()->withoutGlobalScope('organization')->find($data['taggable_id']);
        abort_if($morphable === null, 404, 'Taggable not found.');

        $orgId = Auth::user()->org_id;
        abort_unless($tag->org_id === $orgId, 403);
        abort_unless($morphable->org_id === $orgId, 403);

        return [$tag, $morphable];
    }
}
