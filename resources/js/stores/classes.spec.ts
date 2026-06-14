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
    start_time: null,
    end_time: null,
    location: null,
    address: null,
    instructor: null,
    show_signature: false,
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

    it('fetchPage() requests the paged endpoint and returns {data, meta}', async () => {
        const get = axios.get as ReturnType<typeof vi.fn>;
        const meta = {
            current_page: 2,
            last_page: 3,
            per_page: 25,
            total: 60,
        };
        get.mockResolvedValue({ data: { data: [{ id: 'c1' }], meta } });
        const store = useClassesStore();

        const res = await store.fetchPage({
            page: 2,
            per_page: 25,
            dir: 'asc',
            sort: 'name',
            q: 'fire',
        });

        expect(get).toHaveBeenCalledWith(
            '/api/classes',
            expect.objectContaining({
                params: expect.objectContaining({
                    page: 2,
                    per_page: 25,
                    dir: 'asc',
                    sort: 'name',
                    q: 'fire',
                }),
            }),
        );
        expect(res.data).toHaveLength(1);
        expect(res.meta.total).toBe(60);
    });

    it('loadDetail() caches the detail keyed by id', async () => {
        (axios.get as ReturnType<typeof vi.fn>).mockResolvedValue({
            data: detailA,
        });
        const store = useClassesStore();

        await store.loadDetail('c1');

        expect(store.detail.c1.name).toBe('Class A');
    });

    it('destroy() drops the detail cache entry', async () => {
        (axios.get as ReturnType<typeof vi.fn>).mockResolvedValue({
            data: detailA,
        });
        (axios.delete as ReturnType<typeof vi.fn>).mockResolvedValue({
            data: { ok: true },
        });
        const store = useClassesStore();
        await store.loadDetail('c1'); // populates detail.c1

        await store.destroy('c1');

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
            start_time: null,
            end_time: null,
            location: null,
            address: null,
            instructor: null,
            show_signature: false,
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

    it('updateTrainingHours() PATCHes the class_training hours and caches', async () => {
        const patch = axios.patch as ReturnType<typeof vi.fn>;
        patch.mockResolvedValue({ data: detailA });
        const store = useClassesStore();

        await store.updateTrainingHours('c1', 'ct1', 3.5);

        expect(patch).toHaveBeenCalledWith(
            '/api/classes/c1/trainings/ct1',
            { hours: 3.5 },
            expect.anything(),
        );
        expect(store.detail.c1.id).toBe('c1');
    });

    it('complete() posts the close-out and caches the returned detail', async () => {
        const post = axios.post as ReturnType<typeof vi.fn>;
        post.mockResolvedValue({ data: { ...detailA, status: 'completed' } });
        (axios.get as ReturnType<typeof vi.fn>).mockResolvedValue({ data: [] });
        const store = useClassesStore();

        await store.complete('c1', {
            completion_date: '2026-06-01',
            enrollments: [
                {
                    id: 'e1',
                    notes: null,
                    results: [{ class_training_id: 'ct1', passed: true }],
                },
            ],
        });

        expect(post).toHaveBeenCalledWith(
            '/api/classes/c1/complete',
            expect.objectContaining({ completion_date: '2026-06-01' }),
            expect.anything(),
        );
        expect(store.detail.c1.status).toBe('completed');
    });

    it('reopen() posts and caches the unlocked (scheduled) detail', async () => {
        const post = axios.post as ReturnType<typeof vi.fn>;
        post.mockResolvedValue({ data: { ...detailA, status: 'scheduled' } });
        (axios.get as ReturnType<typeof vi.fn>).mockResolvedValue({ data: [] });
        const store = useClassesStore();

        await store.reopen('c1');

        expect(post).toHaveBeenCalledWith(
            '/api/classes/c1/reopen',
            {},
            expect.anything(),
        );
        expect(store.detail.c1.status).toBe('scheduled');
    });
});
