import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { useCardFontsStore } from '@/stores/cardFonts';
import type { CardFontRow } from '@/stores/cardFonts';
import CardFontsList from './CardFontsList.vue';

vi.mock('axios');
const toastSuccess = vi.fn();
vi.mock('vue-sonner', () => ({
    toast: {
        success: (...args: unknown[]) => toastSuccess(...args),
        error: vi.fn(),
    },
}));

function font(overrides: Partial<CardFontRow> & { id: string }): CardFontRow {
    return {
        family: 'Brush Script MT',
        original_filename: 'brushsc.ttf',
        format: 'ttf',
        size: 145_000,
        uploaded_at: '2026-08-01T10:00:00+00:00',
        can_delete: true,
        ...overrides,
    };
}

async function mountWith(fonts: CardFontRow[], canDefine = true) {
    setActivePinia(createPinia());
    const store = useCardFontsStore();
    store.library = fonts;
    store.loaded = true;
    vi.spyOn(store, 'load').mockResolvedValue();

    const wrapper = mount(CardFontsList, { props: { canDefine } });
    await flushPromises();

    return { wrapper, store };
}

describe('CardFontsList', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        vi.stubGlobal('confirm', vi.fn().mockReturnValue(true));
    });

    it('says the built-ins already work when nothing is uploaded', async () => {
        // An empty list must not read as "your cards have no fonts".
        const { wrapper } = await mountWith([]);

        expect(wrapper.text()).toContain('built-in');
        expect(wrapper.findAll('[data-testid="font-row"]')).toHaveLength(0);
    });

    it('lists the family a design has to ask for, with the file behind it', async () => {
        /*
         * The family is what a design must name to match; the filename is
         * only there to recognise which file was uploaded. Showing the
         * filename as the identity would invite naming a design after it.
         */
        const { wrapper } = await mountWith([font({ id: 'f1' })]);

        expect(wrapper.text()).toContain('Brush Script MT');
        expect(wrapper.text()).toContain('brushsc.ttf');
        expect(wrapper.text()).toContain('142 KB');
    });

    it('uploads a chosen file and says which family it added', async () => {
        const { wrapper, store } = await mountWith([]);
        const upload = vi
            .spyOn(store, 'upload')
            .mockResolvedValue(font({ id: 'f9', family: 'Gotham' }));

        const file = new File(['\x00\x01\x00\x00'], 'gotham.ttf');
        const input = wrapper.get('[data-testid="font-file"]');
        Object.defineProperty(input.element, 'files', { value: [file] });
        await input.trigger('change');
        await flushPromises();

        expect(upload).toHaveBeenCalledWith(file);
        // Named, because the family read from the file is often not what the
        // uploader expected from its filename.
        expect(toastSuccess).toHaveBeenCalledWith(
            expect.stringContaining('Gotham'),
        );
    });

    it('warns what removing a font means before doing it', async () => {
        // Removing silently would let a design go back to being substituted
        // with nothing on screen to explain the change.
        const { wrapper, store } = await mountWith([font({ id: 'f1' })]);
        const destroy = vi.spyOn(store, 'destroy').mockResolvedValue();

        await wrapper.get('[data-testid="delete-font-f1"]').trigger('click');

        expect(confirm).toHaveBeenCalledWith(
            expect.stringContaining('substituted'),
        );
        expect(destroy).toHaveBeenCalledWith('f1');
    });

    it('keeps the upload control away from actors who cannot define', async () => {
        const { wrapper } = await mountWith([font({ id: 'f1' })], false);

        expect(wrapper.find('[data-testid="upload-font"]').exists()).toBe(
            false,
        );
    });
});
