import axios from 'axios';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { useAttachmentsStore } from '@/stores/attachments';
import type { AttachmentRow } from '@/stores/attachments';

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

const TYPE = 'App\\Models\\TrainingClass';
const KEY = `${TYPE}::c1`;

function row(overrides: Partial<AttachmentRow> & { id: string }): AttachmentRow {
    return {
        attachable_type: TYPE,
        attachable_id: 'c1',
        filename: 'sheet.pdf',
        type: null,
        description: null,
        mime: 'application/pdf',
        size: 1024,
        uploaded_by_user_id: 'u1',
        uploaded_by_name: 'Dana Reed',
        created_at: '2026-08-01 08:28:00',
        can_delete: true,
        can_edit: true,
        ...overrides,
    };
}

/** The broadcast payload — deliberately carries no permission fields. */
function created(id: string, attachableId = 'c1'): void {
    capturedBindings.AttachmentCreated?.({
        id,
        attachable_type: TYPE,
        attachable_id: attachableId,
        filename: 'Cards_Front_First_Aid.pdf',
        type: null,
        description: null,
        mime: 'application/pdf',
        size: 2048,
        uploaded_by_user_id: 'u1',
    });
}

/** Count index fetches for the class list. */
const indexGets = () =>
    (axios.get as ReturnType<typeof vi.fn>).mock.calls.filter(
        ([url]) => url === '/api/attachments',
    ).length;

describe('attachments store — AttachmentCreated broadcast', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        vi.clearAllMocks();
        Object.keys(capturedBindings).forEach(
            (k) => delete capturedBindings[k],
        );
        (axios.get as ReturnType<typeof vi.fn>).mockResolvedValue({ data: [] });
    });

    /*
     * The regression this file exists for: a file filed by a queue worker (a
     * card-sheet PDF) only ever reaches an open page through this broadcast,
     * never through index — which is the one path that evaluates
     * AttachmentPolicy per viewer. Synthesizing the row client-side meant
     * guessing at can_delete, and the guess was a hardcoded false: the owner
     * of the file was offered Download and nothing else until a reload.
     */
    it('refetches the list so the new row carries the viewer’s real permissions', async () => {
        const get = axios.get as ReturnType<typeof vi.fn>;
        get.mockResolvedValue({ data: [] });

        const store = useAttachmentsStore();
        store.subscribe('org1');
        await store.load({ type: TYPE, id: 'c1' });
        expect(indexGets()).toBe(1);

        get.mockResolvedValue({
            data: [row({ id: 'a-new', filename: 'Cards_Front_First_Aid.pdf' })],
        });
        created('a-new');
        await vi.waitFor(() => expect(indexGets()).toBe(2));

        const rows = store.listFor({ type: TYPE, id: 'c1' });
        expect(rows).toHaveLength(1);
        expect(rows[0].can_delete).toBe(true);
        // The same refetch is what makes these truthful rather than null.
        expect(rows[0].uploaded_by_name).toBe('Dana Reed');
        expect(rows[0].created_at).not.toBeNull();
    });

    it('ignores a broadcast for a morphable this tab never loaded', async () => {
        const store = useAttachmentsStore();
        store.subscribe('org1');
        await store.load({ type: TYPE, id: 'c1' });
        expect(indexGets()).toBe(1);

        created('a-new', 'other-class');
        await Promise.resolve();

        // No list is held for that class, so there is nothing to refresh and
        // no reason to spend a request on it.
        expect(indexGets()).toBe(1);
    });

    it('does not refetch for a row it already holds', async () => {
        const get = axios.get as ReturnType<typeof vi.fn>;
        get.mockResolvedValue({ data: [row({ id: 'a1' })] });

        const store = useAttachmentsStore();
        store.subscribe('org1');
        await store.load({ type: TYPE, id: 'c1' });
        expect(indexGets()).toBe(1);

        // The uploader's own tab already reloaded after its POST; its own
        // broadcast arriving afterwards must not cost a second round trip.
        created('a1');
        await Promise.resolve();

        expect(indexGets()).toBe(1);
    });

    it('survives a failed refetch without rejecting or emptying the list', async () => {
        const get = axios.get as ReturnType<typeof vi.fn>;
        get.mockResolvedValue({ data: [row({ id: 'a1' })] });

        const store = useAttachmentsStore();
        store.subscribe('org1');
        await store.load({ type: TYPE, id: 'c1' });

        get.mockRejectedValue(new Error('network'));
        created('a-new');
        await vi.waitFor(() => expect(indexGets()).toBe(2));

        // A broadcast handler has no caller to reject to; the list keeps what
        // it had rather than blanking on a network blip.
        expect(store.listFor({ type: TYPE, id: 'c1' })).toHaveLength(1);
        expect(store.lists[KEY][0].id).toBe('a1');
    });
});
