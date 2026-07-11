import axios from 'axios';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { useGeneratedDocumentsStore } from '@/stores/generatedDocuments';

vi.mock('axios');
vi.mock('@/echo', () => ({ realtimeTabId: () => 'test-tab' }));

const capturedBindings: Record<string, (payload: unknown) => void> = {};
vi.mock('@/composables/useRealtime', () => ({
    useRealtime: vi.fn(() => ({
        bind: vi.fn((event: string, cb: (p: unknown) => void) => {
            capturedBindings[event] = cb;
        }),
        leave: vi.fn(),
    })),
}));

describe('useGeneratedDocumentsStore', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        vi.clearAllMocks();
        Object.keys(capturedBindings).forEach((k) => delete capturedBindings[k]);
    });

    it('fetchPage passes the query through and returns {data, meta}', async () => {
        const get = axios.get as ReturnType<typeof vi.fn>;
        const page = {
            data: [],
            meta: { current_page: 1, last_page: 1, per_page: 25, total: 0 },
        };
        get.mockResolvedValue({ data: page });

        const store = useGeneratedDocumentsStore();
        const result = await store.fetchPage({ page: 2 });

        expect(get).toHaveBeenCalledWith(
            '/api/generated-documents',
            expect.objectContaining({ params: { page: 2 } }),
        );
        expect(result).toEqual(page);
    });

    it('generate posts the variation and bumps revision', async () => {
        const post = axios.post as ReturnType<typeof vi.fn>;
        post.mockResolvedValue({ data: { id: 'g1', status: 'queued' } });

        const store = useGeneratedDocumentsStore();
        await store.generate('t1', 'North Yard', '');

        expect(post).toHaveBeenCalledWith(
            '/api/generated-documents',
            { doc_template_id: 't1', location: 'North Yard', department: '' },
            expect.anything(),
        );
        expect(store.revision).toBe(1);
    });

    it('broadcast bumps revision even for self-echo (job completion signal)', () => {
        const store = useGeneratedDocumentsStore();
        store.subscribe('org-1');

        capturedBindings['GeneratedDocumentsChanged']({ origin_tab: 'test-tab' });
        capturedBindings['GeneratedDocumentsChanged']({ origin_tab: null });

        expect(store.revision).toBe(2);
    });

    it('downloadUrl builds the format-qualified link', () => {
        const store = useGeneratedDocumentsStore();

        expect(store.downloadUrl('g1', 'pdf')).toBe('/api/generated-documents/g1/download?format=pdf');
        expect(store.downloadUrl('g1', 'merged')).toBe('/api/generated-documents/g1/download?format=merged');
    });
});
