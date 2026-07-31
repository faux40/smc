import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import MergeKeysPanel from '@/pages/cards/Partials/MergeKeysPanel.vue';
import { useCardStocksStore } from '@/stores/cardStocks';
import { useCardTemplatesStore } from '@/stores/cardTemplates';
import Index from './Index.vue';

vi.mock('axios');
vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<div><slot /></div>' },
    usePage: () => ({ props: { auth: { user: { isAdmin: true } } } }),
}));
vi.mock('@/routes/cards', () => ({ page: () => ({ url: '/cards' }) }));

async function mountPage() {
    setActivePinia(createPinia());

    const templates = useCardTemplatesStore();
    templates.loaded = true;
    vi.spyOn(templates, 'load').mockResolvedValue();

    const stocks = useCardStocksStore();
    stocks.loaded = true;
    vi.spyOn(stocks, 'load').mockResolvedValue();

    const wrapper = mount(Index);
    await flushPromises();

    return wrapper;
}

describe('cards/Index', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('opens on the template library', async () => {
        const wrapper = await mountPage();

        expect(wrapper.findComponent(MergeKeysPanel).exists()).toBe(false);
    });

    it('shows the merge-key catalogue on its own tab', async () => {
        // The keys are what someone needs while laying out a slide, so they
        // live with the designs rather than buried in a training's settings.
        const wrapper = await mountPage();

        await wrapper.get('[data-testid="tab-keys"]').trigger('click');

        expect(wrapper.findComponent(MergeKeysPanel).exists()).toBe(true);
    });
});
