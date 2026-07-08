import axios from 'axios';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { useTrainingAssignmentsStore } from '@/stores/trainingAssignments';
import type {
    AssignmentSourceRow,
    TrainingAssignmentRow,
} from '@/stores/trainingAssignments';

vi.mock('axios');
vi.mock('@/echo', () => ({ realtimeTabId: () => 'test-tab' }));

// Capture bind callbacks so handler behavior can be tested synchronously.
const capturedBindings: Record<string, (payload: unknown) => void> = {};
const mockBind = vi.fn((event: string, cb: (p: unknown) => void) => {
    capturedBindings[event] = cb;
});

vi.mock('@/composables/useRealtime', () => ({
    useRealtime: vi.fn(() => ({ bind: mockBind, leave: vi.fn() })),
}));

function source(overrides: Partial<AssignmentSourceRow> = {}): AssignmentSourceRow {
    return {
        id: 'src-1',
        sourceable_type: null,
        sourceable_id: null,
        added_at: '2026-01-01T00:00:00.000Z',
        ...overrides,
    };
}

function ta(overrides: Partial<TrainingAssignmentRow> & { id: string }): TrainingAssignmentRow {
    return {
        user_id: 'u1',
        training_id: 't1',
        name: 'Safety Training',
        expires_at: null,
        last_completed_at: null,
        active_sources: [source()],
        can_delete: true,
        ...overrides,
    };
}

describe('useTrainingAssignmentsStore', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        vi.clearAllMocks();
        Object.keys(capturedBindings).forEach((k) => delete capturedBindings[k]);
    });

    // ----------------------------------------------------------------
    // cache — pure logic, no network
    // ----------------------------------------------------------------

    it('rows is empty initially', () => {
        expect(useTrainingAssignmentsStore().rows).toHaveLength(0);
    });

    it('upsert adds a new row', () => {
        const store = useTrainingAssignmentsStore();
        store.upsert(ta({ id: 'ta-1' }));
        expect(store.rows).toHaveLength(1);
        expect(store.rows[0].id).toBe('ta-1');
    });

    it('upsert patches an existing row without adding a duplicate', () => {
        const store = useTrainingAssignmentsStore();
        store.upsert(ta({ id: 'ta-1', name: 'Old' }));
        store.upsert(ta({ id: 'ta-1', name: 'New', expires_at: '2027-01-01' }));

        expect(store.rows).toHaveLength(1);
        expect(store.rows[0].name).toBe('New');
        expect(store.rows[0].expires_at).toBe('2027-01-01');
    });

    it('forUser returns only rows belonging to that user', () => {
        const store = useTrainingAssignmentsStore();
        store.upsert(ta({ id: 'ta-1', user_id: 'u1', training_id: 't1' }));
        store.upsert(ta({ id: 'ta-2', user_id: 'u2', training_id: 't2' }));
        store.upsert(ta({ id: 'ta-3', user_id: 'u1', training_id: 't3' }));

        expect(store.forUser('u1').map((r) => r.id)).toEqual(['ta-1', 'ta-3']);
        expect(store.forUser('u2').map((r) => r.id)).toEqual(['ta-2']);
        expect(store.forUser('u-ghost')).toHaveLength(0);
    });

    it('forTraining returns only rows for that training', () => {
        const store = useTrainingAssignmentsStore();
        store.upsert(ta({ id: 'ta-1', user_id: 'u1', training_id: 't1' }));
        store.upsert(ta({ id: 'ta-2', user_id: 'u2', training_id: 't1' }));
        store.upsert(ta({ id: 'ta-3', user_id: 'u1', training_id: 't2' }));

        expect(store.forTraining('t1').map((r) => r.id)).toEqual(['ta-1', 'ta-2']);
        expect(store.forTraining('t2').map((r) => r.id)).toEqual(['ta-3']);
    });

    // ----------------------------------------------------------------
    // network — axios mocked
    // ----------------------------------------------------------------

    it('loadFor fetches and upserts rows', async () => {
        const get = axios.get as ReturnType<typeof vi.fn>;
        get.mockResolvedValue({ data: [ta({ id: 'ta-1' }), ta({ id: 'ta-2' })] });

        const store = useTrainingAssignmentsStore();
        await store.loadFor({ user_id: 'u1' });

        expect(get).toHaveBeenCalledWith(
            '/api/training-assignments',
            expect.objectContaining({ params: { user_id: 'u1' } }),
        );
        expect(store.rows).toHaveLength(2);
    });

    it('loadFor is a no-op on repeated call with the same filter', async () => {
        const get = axios.get as ReturnType<typeof vi.fn>;
        get.mockResolvedValue({ data: [] });

        const store = useTrainingAssignmentsStore();
        await store.loadFor({ user_id: 'u1' });
        await store.loadFor({ user_id: 'u1' });

        expect(get).toHaveBeenCalledTimes(1);
    });

    // F7: recording a completion recalculates status/expires_at server-side
    // with no broadcast — callers that just mutated data for a filter they
    // already loaded need a way to bypass the cache and re-pull it.
    it('loadFor({ force: true }) refetches even when the filter was already loaded', async () => {
        const get = axios.get as ReturnType<typeof vi.fn>;
        get.mockResolvedValueOnce({ data: [ta({ id: 'ta-1', last_completed_at: null })] });
        get.mockResolvedValueOnce({ data: [ta({ id: 'ta-1', last_completed_at: '2026-07-01' })] });

        const store = useTrainingAssignmentsStore();
        await store.loadFor({ user_id: 'u1' });
        await store.loadFor({ user_id: 'u1' }, { force: true });

        expect(get).toHaveBeenCalledTimes(2);
        expect(store.rows.find((r) => r.id === 'ta-1')?.last_completed_at).toBe('2026-07-01');
    });

    it('assignDirect POSTs with source_type=direct and upserts the response', async () => {
        const post = axios.post as ReturnType<typeof vi.fn>;
        post.mockResolvedValue({ data: [ta({ id: 'ta-1', user_id: 'u1', training_id: 't1' })] });

        const store = useTrainingAssignmentsStore();
        const result = await store.assignDirect('u1', 't1');

        expect(post).toHaveBeenCalledWith(
            '/api/training-assignments',
            { source_type: 'direct', user_id: 'u1', training_id: 't1' },
            expect.any(Object),
        );
        expect(result).toHaveLength(1);
        expect(result[0].id).toBe('ta-1');
        expect(store.rows).toHaveLength(1);
    });

    it('assignFromRequirement POSTs with source_type=requirement and upserts all results', async () => {
        const post = axios.post as ReturnType<typeof vi.fn>;
        post.mockResolvedValue({
            data: [
                ta({ id: 'ta-1', user_id: 'u1', training_id: 't1' }),
                ta({ id: 'ta-2', user_id: 'u1', training_id: 't2' }),
            ],
        });

        const store = useTrainingAssignmentsStore();
        const result = await store.assignFromRequirement('u1', 'req-1');

        expect(post).toHaveBeenCalledWith(
            '/api/training-assignments',
            { source_type: 'requirement', user_id: 'u1', requirement_id: 'req-1' },
            expect.any(Object),
        );
        expect(result).toHaveLength(2);
        expect(store.rows).toHaveLength(2);
    });

    it('destroy DELETEs the row and removes it from cache', async () => {
        (axios.delete as ReturnType<typeof vi.fn>).mockResolvedValue({ data: { ok: true } });

        const store = useTrainingAssignmentsStore();
        store.upsert(ta({ id: 'ta-1' }));

        await store.destroy('ta-1');

        expect(axios.delete).toHaveBeenCalledWith(
            '/api/training-assignments/ta-1',
            expect.any(Object),
        );
        expect(store.rows).toHaveLength(0);
    });

    // ----------------------------------------------------------------
    // remind / remindBulk (F10)
    // ----------------------------------------------------------------

    it('remind POSTs to the assignment remind endpoint and returns the result', async () => {
        const post = axios.post as ReturnType<typeof vi.fn>;
        post.mockResolvedValue({
            data: { sent: true, status: 'overdue', supervisor_notified: true },
        });

        const store = useTrainingAssignmentsStore();
        const result = await store.remind('ta-9');

        expect(post).toHaveBeenCalledWith(
            '/api/assignments/ta-9/remind',
            {},
            expect.any(Object),
        );
        expect(result).toEqual({
            sent: true,
            status: 'overdue',
            supervisor_notified: true,
        });
    });

    it('remindBulk POSTs the ids array and returns the tallies', async () => {
        const post = axios.post as ReturnType<typeof vi.fn>;
        post.mockResolvedValue({
            data: {
                reminded_count: 3,
                skipped_count: 1,
                supervisors_notified_count: 2,
            },
        });

        const store = useTrainingAssignmentsStore();
        const result = await store.remindBulk(['ta-1', 'ta-2', 'ta-3', 'ta-4']);

        expect(post).toHaveBeenCalledWith(
            '/api/assignments/remind-bulk',
            { training_assignment_ids: ['ta-1', 'ta-2', 'ta-3', 'ta-4'] },
            expect.any(Object),
        );
        expect(result.reminded_count).toBe(3);
        expect(result.supervisors_notified_count).toBe(2);
    });

    // ----------------------------------------------------------------
    // Reverb — subscribe + handlers
    // ----------------------------------------------------------------

    it('subscribe binds to the Created, Deleted, and BulkChanged events', () => {
        const store = useTrainingAssignmentsStore();
        store.subscribe('org-1');

        expect(mockBind).toHaveBeenCalledWith('TrainingAssignmentCreated', expect.any(Function));
        expect(mockBind).toHaveBeenCalledWith('TrainingAssignmentDeleted', expect.any(Function));
        expect(mockBind).toHaveBeenCalledWith('TrainingAssignmentsBulkChanged', expect.any(Function));
    });

    it('subscribe is idempotent — does not re-bind for the same orgId', () => {
        const store = useTrainingAssignmentsStore();
        store.subscribe('org-1');
        store.subscribe('org-1');

        expect(mockBind).toHaveBeenCalledTimes(3); // Created + Deleted + BulkChanged
    });

    it('TrainingAssignmentsBulkChanged handler bumps the revision so watchers refetch', () => {
        const store = useTrainingAssignmentsStore();
        store.subscribe('org-1');

        const before = store.revision;
        capturedBindings['TrainingAssignmentsBulkChanged']({ org_id: 'org-1', origin_tab: 'other-tab' });

        expect(store.revision).toBe(before + 1);
    });

    it('TrainingAssignmentCreated handler upserts the row', () => {
        const store = useTrainingAssignmentsStore();
        store.subscribe('org-1');

        capturedBindings['TrainingAssignmentCreated']({
            id: 'ta-new',
            user_id: 'u1',
            training_id: 't1',
            name: 'Fire Safety',
            expires_at: null,
            last_completed_at: null,
            origin_tab: 'other-tab',
        });

        expect(store.rows).toHaveLength(1);
        expect(store.rows[0].id).toBe('ta-new');
    });

    it('TrainingAssignmentCreated handler maps active_sources from payload', () => {
        const store = useTrainingAssignmentsStore();
        store.subscribe('org-1');

        capturedBindings['TrainingAssignmentCreated']({
            id: 'ta-new',
            user_id: 'u1',
            training_id: 't1',
            name: 'Fire Safety',
            expires_at: null,
            last_completed_at: null,
            active_sources: [
                {
                    id: 'src-1',
                    sourceable_type: 'App\\Models\\Requirement',
                    sourceable_id: 'r1',
                    added_at: '2026-01-01T00:00:00.000Z',
                },
            ],
            origin_tab: 'other-tab',
        });

        expect(store.rows[0].active_sources).toHaveLength(1);
        expect(store.rows[0].active_sources[0].sourceable_id).toBe('r1');
    });

    it('TrainingAssignmentCreated handler preserves existing can_delete when patching a peer-tab update', () => {
        const store = useTrainingAssignmentsStore();
        store.upsert(ta({ id: 'ta-1', can_delete: true }));
        store.subscribe('org-1');

        capturedBindings['TrainingAssignmentCreated']({
            id: 'ta-1',
            user_id: 'u1',
            training_id: 't1',
            name: 'Updated Name',
            expires_at: null,
            last_completed_at: null,
            active_sources: [],
            origin_tab: 'other-tab',
        });

        expect(store.rows[0].can_delete).toBe(true);
    });

    it('TrainingAssignmentDeleted handler removes the row', () => {
        const store = useTrainingAssignmentsStore();
        store.upsert(ta({ id: 'ta-1' }));
        store.subscribe('org-1');

        capturedBindings['TrainingAssignmentDeleted']({ id: 'ta-1', origin_tab: 'other-tab' });

        expect(store.rows).toHaveLength(0);
    });

    // ----------------------------------------------------------------
    // breakFromRequirement
    // ----------------------------------------------------------------

    it('breakFromRequirement calls DELETE on the correct URL with requirement_id', async () => {
        (axios.delete as ReturnType<typeof vi.fn>).mockResolvedValue({
            data: { deleted_id: 'ta-1', updated_ids: [] },
        });

        const store = useTrainingAssignmentsStore();
        store.upsert(ta({ id: 'ta-1' }));
        await store.breakFromRequirement('ta-1', 'r1');

        expect(axios.delete).toHaveBeenCalledWith(
            '/api/training-assignments/ta-1/from-requirement',
            expect.objectContaining({ data: { requirement_id: 'r1' } }),
        );
    });

    it('breakFromRequirement removes the deleted TA from rows', async () => {
        (axios.delete as ReturnType<typeof vi.fn>).mockResolvedValue({
            data: { deleted_id: 'ta-1', updated_ids: [] },
        });

        const store = useTrainingAssignmentsStore();
        store.upsert(ta({ id: 'ta-1' }));
        store.upsert(ta({ id: 'ta-2' }));
        await store.breakFromRequirement('ta-1', 'r1');

        expect(store.rows.map((r) => r.id)).toEqual(['ta-2']);
    });

    it('breakFromRequirement strips the requirement source from updated TAs', async () => {
        (axios.delete as ReturnType<typeof vi.fn>).mockResolvedValue({
            data: { deleted_id: 'ta-1', updated_ids: ['ta-2', 'ta-3'] },
        });

        const store = useTrainingAssignmentsStore();
        store.upsert(ta({ id: 'ta-1' }));
        store.upsert(ta({
            id: 'ta-2',
            active_sources: [
                source({ id: 'src-req', sourceable_type: 'App\\Models\\Requirement', sourceable_id: 'r1' }),
                source({ id: 'src-direct', sourceable_type: null }),
            ],
        }));
        store.upsert(ta({
            id: 'ta-3',
            active_sources: [
                source({ id: 'src-req2', sourceable_type: 'App\\Models\\Requirement', sourceable_id: 'r1' }),
            ],
        }));

        await store.breakFromRequirement('ta-1', 'r1');

        // ta-2 keeps its direct source, loses the requirement source
        expect(store.rows.find((r) => r.id === 'ta-2')!.active_sources).toHaveLength(1);
        expect(store.rows.find((r) => r.id === 'ta-2')!.active_sources[0].id).toBe('src-direct');

        // ta-3 has no remaining sources
        expect(store.rows.find((r) => r.id === 'ta-3')!.active_sources).toHaveLength(0);
    });

    it('breakFromRequirement returns the server response', async () => {
        (axios.delete as ReturnType<typeof vi.fn>).mockResolvedValue({
            data: { deleted_id: 'ta-1', updated_ids: ['ta-2'] },
        });

        const store = useTrainingAssignmentsStore();
        store.upsert(ta({ id: 'ta-1' }));
        store.upsert(ta({ id: 'ta-2' }));
        const result = await store.breakFromRequirement('ta-1', 'r1');

        expect(result).toEqual({ deleted_id: 'ta-1', updated_ids: ['ta-2'] });
    });
});
