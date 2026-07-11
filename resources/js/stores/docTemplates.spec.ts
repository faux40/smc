import axios from 'axios';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { useDocTemplatesStore } from '@/stores/docTemplates';
import type { DocTemplateRow } from '@/stores/docTemplates';

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

function row(overrides: Partial<DocTemplateRow> & { id: string }): DocTemplateRow {
    return {
        name: overrides.id,
        description: null,
        original_filename: 'x.docx',
        extension: 'docx',
        size: 1000,
        placeholders: ['agency'],
        version: 1,
        is_system: false,
        can_edit: true,
        can_delete: true,
        updated_at: null,
        ...overrides,
    };
}

describe('useDocTemplatesStore', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        vi.clearAllMocks();
        Object.keys(capturedBindings).forEach((k) => delete capturedBindings[k]);
    });

    it('upload posts multipart and appends the new template', async () => {
        const post = axios.post as ReturnType<typeof vi.fn>;
        post.mockResolvedValue({ data: row({ id: 't1', name: 'HazCom' }) });

        const store = useDocTemplatesStore();
        const file = new File(['zip'], 'HazCom.docx');
        await store.upload(file, 'HazCom', 'The master');

        const [url, body] = post.mock.calls[0];
        expect(url).toBe('/api/doc-templates');
        expect(body).toBeInstanceOf(FormData);
        expect((body as FormData).get('name')).toBe('HazCom');
        expect((body as FormData).get('file')).toBe(file);
        expect(store.library).toHaveLength(1);
    });

    it('replace swaps the row for the new version in place', async () => {
        const post = axios.post as ReturnType<typeof vi.fn>;
        post.mockResolvedValue({ data: row({ id: 't2', name: 'HazCom', version: 2 }) });

        const store = useDocTemplatesStore();
        store.library = [row({ id: 't1', name: 'HazCom' }), row({ id: 'other' })];

        await store.replace('t1', new File(['zip'], 'v2.docx'));

        expect(post.mock.calls[0][0]).toBe('/api/doc-templates/t1/replace');
        expect(store.library.map((t) => t.id)).toEqual(['t2', 'other']);
        expect(store.library[0].version).toBe(2);
    });

    it('destroy removes the row', async () => {
        (axios.delete as ReturnType<typeof vi.fn>).mockResolvedValue({ data: { ok: true } });

        const store = useDocTemplatesStore();
        store.library = [row({ id: 't1' })];

        await store.destroy('t1');

        expect(store.library).toHaveLength(0);
    });

    it('peer-tab changes reload; self-echo ignored', async () => {
        const get = axios.get as ReturnType<typeof vi.fn>;
        get.mockResolvedValue({ data: [] });

        const store = useDocTemplatesStore();
        store.subscribe('org-1');

        capturedBindings['DocTemplatesChanged']({ origin_tab: 'test-tab' });
        expect(get).not.toHaveBeenCalled();

        capturedBindings['DocTemplatesChanged']({ origin_tab: 'other' });
        await vi.waitFor(() => expect(get).toHaveBeenCalledTimes(1));
    });
});
