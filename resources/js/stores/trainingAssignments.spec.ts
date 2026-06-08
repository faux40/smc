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
    // Reverb — subscribe + handlers
    // ----------------------------------------------------------------

    it('subscribe binds to TrainingAssignmentCreated and TrainingAssignmentDeleted', () => {
        const store = useTrainingAssignmentsStore();
        store.subscribe('org-1');

        expect(mockBind).toHaveBeenCalledWith('TrainingAssignmentCreated', expect.any(Function));
        expect(mockBind).toHaveBeenCalledWith('TrainingAssignmentDeleted', expect.any(Function));
    });

    it('subscribe is idempotent — does not re-bind for the same orgId', () => {
        const store = useTrainingAssignmentsStore();
        store.subscribe('org-1');
        store.subscribe('org-1');

        expect(mockBind).toHaveBeenCalledTimes(2); // one Created + one Deleted
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

    it('TrainingAssignmentDeleted handler removes the row', () => {
        const store = useTrainingAssignmentsStore();
        store.upsert(ta({ id: 'ta-1' }));
        store.subscribe('org-1');

        capturedBindings['TrainingAssignmentDeleted']({ id: 'ta-1', origin_tab: 'other-tab' });

        expect(store.rows).toHaveLength(0);
    });
});
