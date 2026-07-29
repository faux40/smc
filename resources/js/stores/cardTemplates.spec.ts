import axios from 'axios';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { useCardTemplatesStore } from '@/stores/cardTemplates';
import type { CardTemplateRow } from '@/stores/cardTemplates';

vi.mock('axios');
vi.mock('@/echo', () => ({ realtimeTabId: () => 'test-tab' }));

function row(
    overrides: Partial<CardTemplateRow> & { id: string },
): CardTemplateRow {
    return {
        name: overrides.id,
        description: null,
        original_filename: 'card.pptx',
        extension: 'pptx',
        size: 4096,
        placeholders: ['user_name'],
        fonts: ['Arial'],
        unsupported_fonts: [],
        slide_count: 1,
        has_back: false,
        card_width: 243,
        card_height: 153,
        version: 1,
        is_system: false,
        can_edit: true,
        can_delete: true,
        updated_at: null,
        ...overrides,
    };
}

describe('useCardTemplatesStore', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        vi.clearAllMocks();
    });

    it('loads once and caches', async () => {
        const get = axios.get as ReturnType<typeof vi.fn>;
        get.mockResolvedValue({ data: [row({ id: 't1' })] });
        const store = useCardTemplatesStore();

        await store.load();
        await store.load();

        expect(get).toHaveBeenCalledTimes(1);
        expect(store.library).toHaveLength(1);
    });

    it('upload posts multipart and appends the new template', async () => {
        const post = axios.post as ReturnType<typeof vi.fn>;
        post.mockResolvedValue({ data: row({ id: 't2', name: 'CPR card' }) });
        const store = useCardTemplatesStore();

        const file = new File(['x'], 'card.pptx');
        await store.upload(file, 'CPR card', 'Front only');

        const [url, form] = post.mock.calls[0];
        expect(url).toBe('/api/card-templates');
        expect(form).toBeInstanceOf(FormData);
        expect((form as FormData).get('name')).toBe('CPR card');
        expect((form as FormData).get('description')).toBe('Front only');
        expect(store.library.map((t) => t.id)).toEqual(['t2']);
    });

    it('replace swaps the row for the new version', async () => {
        const get = axios.get as ReturnType<typeof vi.fn>;
        get.mockResolvedValue({ data: [row({ id: 't1', version: 1 })] });
        const post = axios.post as ReturnType<typeof vi.fn>;
        post.mockResolvedValue({ data: row({ id: 't9', version: 2 }) });
        const store = useCardTemplatesStore();
        await store.load();

        await store.replace('t1', new File(['x'], 'card.pptx'));

        // The server soft-deletes the old row and returns the new one.
        expect(store.library.map((t) => t.id)).toEqual(['t9']);
        expect(store.library[0].version).toBe(2);
    });

    it('rename patches and keeps list order', async () => {
        const get = axios.get as ReturnType<typeof vi.fn>;
        get.mockResolvedValue({
            data: [row({ id: 't1' }), row({ id: 't2' }), row({ id: 't3' })],
        });
        const patch = axios.patch as ReturnType<typeof vi.fn>;
        patch.mockResolvedValue({ data: row({ id: 't2', name: 'Renamed' }) });
        const store = useCardTemplatesStore();
        await store.load();

        await store.rename('t2', 'Renamed', null);

        expect(store.library.map((t) => t.id)).toEqual(['t1', 't2', 't3']);
        expect(store.library[1].name).toBe('Renamed');
    });

    it('destroy drops the row', async () => {
        const get = axios.get as ReturnType<typeof vi.fn>;
        get.mockResolvedValue({ data: [row({ id: 't1' }), row({ id: 't2' })] });
        const store = useCardTemplatesStore();
        await store.load();

        await store.destroy('t1');

        expect(store.library.map((t) => t.id)).toEqual(['t2']);
    });

    it('flags templates whose fonts the converter cannot honour', async () => {
        const get = axios.get as ReturnType<typeof vi.fn>;
        get.mockResolvedValue({
            data: [
                row({ id: 'ok' }),
                row({ id: 'risky', unsupported_fonts: ['Brush Script MT'] }),
            ],
        });
        const store = useCardTemplatesStore();
        await store.load();

        // Substituted fonts re-flow the card, so the list surfaces them.
        expect(store.withFontWarnings.map((t) => t.id)).toEqual(['risky']);
    });
});
