import axios from 'axios';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { useClassesStore } from '@/stores/classes';
import type { ClassDetail } from '@/stores/classes';

vi.mock('axios');

const detailA: ClassDetail = {
    id: 'c1',
    name: 'Class A',
    scheduled_date: '2026-06-01',
    location: null,
    instructor: null,
    total_hours: null,
    notes: null,
    status: 'scheduled',
    completion_date: null,
    can_edit: true,
    trainings: [],
    enrollments: [],
};

describe('useClassesStore', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        vi.clearAllMocks();
    });

    it('load() hydrates the library and is idempotent until forced', async () => {
        const get = axios.get as ReturnType<typeof vi.fn>;
        get.mockResolvedValue({ data: [{ id: 'c1', name: 'Class A' }] });
        const store = useClassesStore();

        await store.load();
        await store.load(); // cached — no second call

        expect(store.library).toHaveLength(1);
        expect(get).toHaveBeenCalledTimes(1);

        await store.load(true); // forced refetch
        expect(get).toHaveBeenCalledTimes(2);
    });

    it('loadDetail() caches the detail keyed by id', async () => {
        (axios.get as ReturnType<typeof vi.fn>).mockResolvedValue({
            data: detailA,
        });
        const store = useClassesStore();

        await store.loadDetail('c1');

        expect(store.detail.c1.name).toBe('Class A');
    });

    it('destroy() drops the row from library and detail cache', async () => {
        (axios.get as ReturnType<typeof vi.fn>).mockResolvedValue({
            data: [{ id: 'c1' }],
        });
        (axios.delete as ReturnType<typeof vi.fn>).mockResolvedValue({
            data: { ok: true },
        });
        const store = useClassesStore();
        await store.load();
        await store.loadDetail('c1'); // populates detail.c1 via the same mock

        await store.destroy('c1');

        expect(store.library.find((c) => c.id === 'c1')).toBeUndefined();
        expect(store.detail.c1).toBeUndefined();
    });

    it('create() posts the payload (incl. training_ids) and caches the detail', async () => {
        const post = axios.post as ReturnType<typeof vi.fn>;
        post.mockResolvedValue({ data: detailA });
        (axios.get as ReturnType<typeof vi.fn>).mockResolvedValue({ data: [] });
        const store = useClassesStore();

        await store.create({
            name: 'Class A',
            scheduled_date: '2026-06-01',
            location: null,
            instructor: null,
            total_hours: null,
            notes: null,
            training_ids: ['t1', 't2'],
        });

        expect(post).toHaveBeenCalledWith(
            '/api/classes',
            expect.objectContaining({ training_ids: ['t1', 't2'] }),
            expect.anything(),
        );
        expect(store.detail.c1.name).toBe('Class A');
    });

    it('complete() posts the close-out and caches the returned detail', async () => {
        const post = axios.post as ReturnType<typeof vi.fn>;
        post.mockResolvedValue({ data: { ...detailA, status: 'completed' } });
        (axios.get as ReturnType<typeof vi.fn>).mockResolvedValue({ data: [] });
        const store = useClassesStore();

        await store.complete('c1', {
            completion_date: '2026-06-01',
            enrollments: [{ id: 'e1', status: 'passed', notes: null }],
        });

        expect(post).toHaveBeenCalledWith(
            '/api/classes/c1/complete',
            expect.objectContaining({ completion_date: '2026-06-01' }),
            expect.anything(),
        );
        expect(store.detail.c1.status).toBe('completed');
    });
});
