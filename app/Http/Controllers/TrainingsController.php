<?php

namespace App\Http\Controllers;

use App\Actions\RecalculateTrainingStatus;
use App\Events\TrainingCreated;
use App\Events\TrainingDeleted;
use App\Events\TrainingUpdated;
use App\Http\Requests\TrainingRequest;
use App\Models\Training;
use App\Models\TrainingAssignment;
use App\Support\RecalcContext;
use App\Support\TrainingLadder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class TrainingsController extends Controller
{
    /**
     * Full library array (no paging) — the trainings Pinia store loads this for
     * downstream rqmt-element pickers. The paginated table uses list() instead.
     */
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Training::class);

        $rows = Training::query()
            ->where('org_id', $request->user()->org_id)
            ->with('stdFrequency:id,name,repeat_days', 'satisfiers:trainings.id')
            ->orderBy('name')
            ->get();

        return response()->json($rows->map(fn (Training $t) => $this->trainingRow($t)));
    }

    /**
     * Server-paged JSON list backing the trainings table ({data, meta}). Search
     * + sort run in the DB; per-row gates are evaluated only for the page.
     */
    public function list(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Training::class);

        $query = Training::query()
            ->where('org_id', $request->user()->org_id)
            ->with('stdFrequency:id,name,repeat_days', 'satisfiers:trainings.id');

        if ($request->filled('q')) {
            $term = '%'.mb_strtolower((string) $request->query('q')).'%';
            $query->where(function ($w) use ($term) {
                $w->whereRaw('LOWER(name) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(nickname) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(description) LIKE ?', [$term]);
            });
        }

        $sortable = ['name', 'nickname', 'default_hours', 'created_at'];
        $sort = in_array($request->query('sort'), $sortable, true) ? $request->query('sort') : 'name';
        $dir = $request->query('dir') === 'desc' ? 'desc' : 'asc';
        $query->orderBy($sort, $dir)->orderBy('id');

        $perPage = max(1, min(100, (int) $request->query('per_page', 25)));
        $page = $query->paginate($perPage);

        return response()->json([
            'data' => collect($page->items())->map(fn (Training $t) => $this->trainingRow($t)),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    /**
     * Table/library row shape (shared by index() + list()).
     *
     * @return array<string, mixed>
     */
    private function trainingRow(Training $t): array
    {
        return [
            'id' => $t->id,
            'name' => $t->name,
            'nickname' => $t->nickname,
            'description' => $t->description,
            'initial_only' => $t->initial_only,
            'repeating' => $t->repeating,
            'std_freq_id' => $t->std_freq_id,
            'std_freq_name' => $t->stdFrequency?->name,
            // F9 — the trainings picker (completion form auto-fill) needs the
            // actual day count, not just the frequency's label.
            'std_freq_repeat_days' => $t->stdFrequency?->repeat_days,
            'as_needed' => $t->as_needed,
            'default_hours' => $t->default_hours,
            // Hierarchy edges — the picker needs them to exclude options that
            // would loop. ANY of these trainings' credentials satisfies this
            // one (OR-semantics).
            'satisfied_by_ids' => $t->satisfiers->pluck('id')->all(),
            ...$this->certOutput($t),
            'can_edit' => Gate::check('update', $t),
            'can_delete' => Gate::check('delete', $t),
        ];
    }

    public function store(TrainingRequest $request): JsonResponse
    {
        Gate::authorize('create', Training::class);

        $data = $request->validated();
        $training = Training::create([
            'org_id' => $request->user()->org_id,
            'name' => $data['name'],
            'nickname' => $data['nickname'] ?? null,
            'description' => $data['description'] ?? null,
            'default_hours' => $data['default_hours'] ?? null,
            'initial_only' => (bool) $data['initial_only'],
            'repeating' => (bool) $data['repeating'],
            // Null when not repeating; the validator already required it when repeating=true.
            'std_freq_id' => ((bool) $data['repeating']) ? $data['std_freq_id'] : null,
            'as_needed' => (bool) $data['as_needed'],
            ...$this->certPayload($data),
        ]);

        $training->satisfiers()->sync(
            $this->satisfierPivot($data['satisfied_by_ids'] ?? [], $training->org_id),
        );

        event(new TrainingCreated($training));

        return response()->json($this->serialize($training), 201);
    }

    public function update(TrainingRequest $request, Training $training): JsonResponse
    {
        Gate::authorize('update', $training);

        $data = $request->validated();
        $training->update([
            'name' => $data['name'],
            'nickname' => $data['nickname'] ?? null,
            'description' => $data['description'] ?? null,
            'default_hours' => $data['default_hours'] ?? null,
            'initial_only' => (bool) $data['initial_only'],
            'repeating' => (bool) $data['repeating'],
            'std_freq_id' => ((bool) $data['repeating']) ? $data['std_freq_id'] : null,
            'as_needed' => (bool) $data['as_needed'],
            ...$this->certPayload($data),
        ]);

        $changes = $training->satisfiers()->sync(
            $this->satisfierPivot($data['satisfied_by_ids'] ?? [], $training->org_id),
        );

        if ($changes['attached'] !== [] || $changes['detached'] !== []) {
            $this->resyncHierarchy($training);
        }

        event(new TrainingUpdated($training->fresh()));

        return response()->json($this->serialize($training->fresh()));
    }

    /**
     * sync() payload with the tenant stamp on every edge — the pivot carries
     * org_id so TrainingLadder can load an org's edges in one query.
     *
     * @param  list<string>  $ids
     * @return array<string, array{org_id: string}>
     */
    private function satisfierPivot(array $ids, string $orgId): array
    {
        return collect($ids)
            ->mapWithKeys(fn (string $id) => [$id => ['org_id' => $orgId]])
            ->all();
    }

    /**
     * Re-pointing a ladder changes which credentials satisfy this training —
     * and everything below it, since descendants chain through here. Resync
     * those assignments now: the admin wiring the hierarchy is looking at the
     * compliance page, not waiting for the nightly watchdog.
     */
    private function resyncHierarchy(Training $training): void
    {
        $ladder = TrainingLadder::forOrg($training->org_id);
        $affected = $ladder->descendantsOf($training->id)->push($training->id);

        $pairs = TrainingAssignment::where('org_id', $training->org_id)
            ->whereIn('training_id', $affected)
            ->select(['user_id', 'training_id'])
            ->distinct()
            ->get()
            ->map(fn ($p) => ['user_id' => $p->user_id, 'training_id' => $p->training_id]);

        if ($pairs->isEmpty()) {
            return;
        }

        app(RecalculateTrainingStatus::class)->handleMany(
            $pairs,
            RecalcContext::forOrg($training->org_id, $pairs->pluck('training_id')),
        );
    }

    public function destroy(Training $training): JsonResponse
    {
        Gate::authorize('delete', $training);

        $id = $training->id;
        $orgId = $training->org_id;
        $training->delete();

        event(new TrainingDeleted($id, $orgId));

        return response()->json(['ok' => true]);
    }

    private function serialize(Training $t): array
    {
        $t->loadMissing('satisfiers:trainings.id');

        return [
            'id' => $t->id,
            'name' => $t->name,
            'nickname' => $t->nickname,
            'description' => $t->description,
            'initial_only' => $t->initial_only,
            'repeating' => $t->repeating,
            'std_freq_id' => $t->std_freq_id,
            'as_needed' => $t->as_needed,
            'default_hours' => $t->default_hours,
            // Hierarchy: the higher trainings whose credentials satisfy this
            // one (any of them). The picker resolves names from the library.
            'satisfied_by_ids' => $t->satisfiers->pluck('id')->all(),
            ...$this->certOutput($t),
        ];
    }

    /**
     * Map validated cert/default fields onto model attributes (store/update).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function certPayload(array $data): array
    {
        return [
            'cert_title' => $data['cert_title'] ?? null,
            'cert_text' => $data['cert_text'] ?? null,
            'cert_code' => $data['cert_code'] ?? null,
            'card_template_id' => $data['card_template_id'] ?? null,
            'card_stock_id' => $data['card_stock_id'] ?? null,
            'default_trainer' => $data['default_trainer'] ?? null,
            'default_location' => $data['default_location'] ?? null,
            'default_address' => $data['default_address'] ?? null,
        ];
    }

    /** @return array<string, mixed> */
    private function certOutput(Training $t): array
    {
        return [
            'cert_title' => $t->cert_title,
            'cert_text' => $t->cert_text,
            'cert_code' => $t->cert_code,
            'card_template_id' => $t->card_template_id,
            'card_stock_id' => $t->card_stock_id,
            'default_trainer' => $t->default_trainer,
            'default_location' => $t->default_location,
            'default_address' => $t->default_address,
        ];
    }
}
