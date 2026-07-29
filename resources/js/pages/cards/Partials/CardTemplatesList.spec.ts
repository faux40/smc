import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import CardTemplatesList from '@/pages/cards/Partials/CardTemplatesList.vue';
import { useCardTemplatesStore } from '@/stores/cardTemplates';
import type { CardTemplateRow } from '@/stores/cardTemplates';

vi.mock('axios');
vi.mock('@/echo', () => ({ realtimeTabId: () => 'test-tab' }));

function template(overrides: Partial<CardTemplateRow> = {}): CardTemplateRow {
    return {
        id: 't1',
        name: 'CPR wallet card',
        description: null,
        original_filename: 'cpr.pptx',
        extension: 'pptx',
        size: 4096,
        placeholders: ['user_name', 'expire_date'],
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

async function mountWith(rows: CardTemplateRow[], canDefine = true) {
    setActivePinia(createPinia());
    const store = useCardTemplatesStore();
    store.library = rows;

    const wrapper = mount(CardTemplatesList, {
        props: { canDefine },
        attachTo: document.body,
    });
    await flushPromises();

    return { wrapper, store };
}

describe('CardTemplatesList', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        vi.stubGlobal('confirm', vi.fn().mockReturnValue(true));
        document.body.innerHTML = '';
    });

    it('summarises the card the way the file described it', async () => {
        const { wrapper } = await mountWith([template()]);

        const text = wrapper.text();
        expect(text).toContain('CPR wallet card');
        // Card size read from the slide, shown in inches not points.
        expect(text).toContain('3.375');
        expect(text).toContain('2.125');
        expect(text).toContain('Single-sided');
        expect(text).toContain('2 merge fields');
    });

    it('calls out a two-slide template as front and back', async () => {
        const { wrapper } = await mountWith([
            template({ slide_count: 2, has_back: true }),
        ]);

        expect(wrapper.text()).toContain('Front and back');
    });

    it('warns when the converter cannot honour a font', async () => {
        // The card would re-flow at different metrics and misregister on
        // purchased stock — the one failure the user cannot see coming.
        const { wrapper } = await mountWith([
            template({ unsupported_fonts: ['Brush Script MT'] }),
        ]);

        const warning = wrapper.get('[data-testid="font-warning-t1"]');
        expect(warning.text()).toContain('Brush Script MT');
    });

    it('uploads a template with its name', async () => {
        const { wrapper, store } = await mountWith([]);
        const upload = vi.spyOn(store, 'upload').mockResolvedValue(template());

        await wrapper.get('[data-testid="new-template"]').trigger('click');
        await flushPromises();

        const nameInput =
            document.body.querySelector<HTMLInputElement>('#ct_name')!;
        nameInput.value = 'Forklift card';
        nameInput.dispatchEvent(new Event('input', { bubbles: true }));
        await flushPromises();

        const file = new File(['x'], 'forklift.pptx');
        const wrapperVm = wrapper.vm as unknown as {
            pickFile: (f: File) => void;
        };
        wrapperVm.pickFile(file);
        await flushPromises();

        document.body
            .querySelector('form')!
            .dispatchEvent(
                new Event('submit', { cancelable: true, bubbles: true }),
            );
        await flushPromises();

        expect(upload).toHaveBeenCalledWith(file, 'Forklift card', null);
    });

    it('offers no replace, rename or delete on a system template', async () => {
        const { wrapper } = await mountWith([
            template({
                id: 'sys',
                is_system: true,
                can_edit: false,
                can_delete: false,
            }),
        ]);

        expect(wrapper.text()).toContain('System');
        expect(wrapper.find('[data-testid="replace-sys"]').exists()).toBe(
            false,
        );
        expect(wrapper.find('[data-testid="rename-sys"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="delete-sys"]').exists()).toBe(false);
    });

    it('deletes only after a confirm', async () => {
        const { wrapper, store } = await mountWith([template()]);
        const destroy = vi.spyOn(store, 'destroy').mockResolvedValue();
        vi.stubGlobal('confirm', vi.fn().mockReturnValue(false));

        await wrapper.get('[data-testid="delete-t1"]').trigger('click');
        expect(destroy).not.toHaveBeenCalled();

        vi.stubGlobal('confirm', vi.fn().mockReturnValue(true));
        await wrapper.get('[data-testid="delete-t1"]').trigger('click');
        await flushPromises();

        expect(destroy).toHaveBeenCalledWith('t1');
    });

    it('hides the upload control from actors who cannot define', async () => {
        const { wrapper } = await mountWith([template()], false);

        expect(wrapper.find('[data-testid="new-template"]').exists()).toBe(
            false,
        );
    });
});
