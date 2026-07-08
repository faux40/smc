import axios from 'axios';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { useCompletionsStore } from '@/stores/completions';
import type { CompletionBulkCreatePayload } from '@/stores/completions';

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

function bulkPayload(
    overrides: Partial<CompletionBulkCreatePayload> = {},
): CompletionBulkCreatePayload {
    return {
        user_ids: ['u1', 'u2'],
        training_id: 't1',
        completion_date: '2026-07-01',
        certification_date: null,
        expire_date: null,
        cert_ident: null,
        hours: null,
        notes: null,
        rqmt_element_ids: ['e1'],
        ...overrides,
    };
}

describe('useCompletionsStore', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        vi.clearAllMocks();
        Object.keys(capturedBindings).forEach((k) => delete capturedBindings[k]);
    });

    // ----------------------------------------------------------------
    // bulkCreate (F8)
    // ----------------------------------------------------------------

    it('bulkCreate posts the payload to the bulk endpoint and returns the tallies', async () => {
        const post = axios.post as ReturnType<typeof vi.fn>;
        post.mockResolvedValue({ data: { created_count: 2, skipped_count: 1 } });

        const store = useCompletionsStore();
        const payload = bulkPayload();
        const result = await store.bulkCreate(payload);

        expect(post).toHaveBeenCalledWith(
            '/api/completions/bulk',
            payload,
            expect.objectContaining({ headers: expect.any(Object) }),
        );
        expect(result).toEqual({ created_count: 2, skipped_count: 1 });
    });

    it('create still posts a single completion to the flat endpoint', async () => {
        const post = axios.post as ReturnType<typeof vi.fn>;
        post.mockResolvedValue({ data: { id: 'c1' } });

        const store = useCompletionsStore();
        await store.create({
            user_id: 'u1',
            module_type: 'App\\Models\\Training',
            module_id: 't1',
            completion_date: '2026-07-01',
            certification_date: null,
            expire_date: null,
            cert_ident: null,
            hours: null,
            notes: null,
            rqmt_element_ids: ['e1'],
        });

        expect(post).toHaveBeenCalledWith(
            '/api/completions',
            expect.objectContaining({ user_id: 'u1' }),
            expect.any(Object),
        );
    });

    // ----------------------------------------------------------------
    // Reverb — subscribe + handlers
    // ----------------------------------------------------------------

    it('subscribe binds to the completion + bulk events', () => {
        useCompletionsStore().subscribe('org-1');

        expect(mockBind).toHaveBeenCalledWith('CompletionCreated', expect.any(Function));
        expect(mockBind).toHaveBeenCalledWith('CompletionUpdated', expect.any(Function));
        expect(mockBind).toHaveBeenCalledWith('CompletionDeleted', expect.any(Function));
        expect(mockBind).toHaveBeenCalledWith('CompletionsBulkChanged', expect.any(Function));
    });

    it('subscribe is idempotent for the same orgId', () => {
        const store = useCompletionsStore();
        store.subscribe('org-1');
        store.subscribe('org-1');

        expect(mockBind).toHaveBeenCalledTimes(4); // one per event, once
    });

    it('CompletionsBulkChanged handler bumps the revision so the paged table refetches', () => {
        const store = useCompletionsStore();
        store.subscribe('org-1');

        const before = store.revision;
        capturedBindings['CompletionsBulkChanged']({ org_id: 'org-1', origin_tab: 'other-tab' });

        expect(store.revision).toBe(before + 1);
    });
});
