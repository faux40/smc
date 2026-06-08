import axios from 'axios';
import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import TrainingStatusResyncSection from '@/pages/settings/Partials/TrainingStatusResyncSection.vue';

vi.mock('axios');
vi.mock('@/echo', () => ({ realtimeTabId: () => 'test-tab' }));

const STUBS = {
    Heading: { template: '<div><slot /></div>' },
};

async function mountSection() {
    const wrapper = mount(TrainingStatusResyncSection, {
        global: { stubs: STUBS },
    });
    await flushPromises();
    return wrapper;
}

describe('TrainingStatusResyncSection', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        vi.clearAllMocks();
    });

    it('renders the Re-sync button', async () => {
        const wrapper = await mountSection();
        expect(wrapper.find('[data-testid="resync-btn"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="resync-btn"]').text()).toBe('Re-sync training statuses');
    });

    it('POSTs to /api/settings/training-status-resync on click', async () => {
        (axios.post as ReturnType<typeof vi.fn>).mockResolvedValue({ data: { processed: 5 } });
        const wrapper = await mountSection();

        await wrapper.find('[data-testid="resync-btn"]').trigger('click');
        await flushPromises();

        expect(axios.post).toHaveBeenCalledWith(
            '/api/settings/training-status-resync',
            {},
            expect.any(Object),
        );
    });

    it('shows success message with processed count after completion', async () => {
        (axios.post as ReturnType<typeof vi.fn>).mockResolvedValue({ data: { processed: 7 } });
        const wrapper = await mountSection();

        await wrapper.find('[data-testid="resync-btn"]').trigger('click');
        await flushPromises();

        expect(wrapper.find('[data-testid="resync-success"]').text()).toContain('7');
    });

    it('disables button while running', async () => {
        let resolve!: (v: unknown) => void;
        (axios.post as ReturnType<typeof vi.fn>).mockReturnValue(new Promise((r) => (resolve = r)));
        const wrapper = await mountSection();

        wrapper.find('[data-testid="resync-btn"]').trigger('click');
        await wrapper.vm.$nextTick();

        expect((wrapper.find('[data-testid="resync-btn"]').element as HTMLButtonElement).disabled).toBe(true);
        resolve({ data: { processed: 0 } });
        await flushPromises();
    });

    it('shows error message on failure', async () => {
        (axios.post as ReturnType<typeof vi.fn>).mockRejectedValue(new Error('Server error'));
        const wrapper = await mountSection();

        await wrapper.find('[data-testid="resync-btn"]').trigger('click');
        await flushPromises();

        expect(wrapper.find('[data-testid="resync-error"]').text()).toContain('Server error');
    });

    it('clears previous result before each new run', async () => {
        (axios.post as ReturnType<typeof vi.fn>)
            .mockResolvedValueOnce({ data: { processed: 3 } })
            .mockResolvedValueOnce({ data: { processed: 0 } });

        const wrapper = await mountSection();
        await wrapper.find('[data-testid="resync-btn"]').trigger('click');
        await flushPromises();
        expect(wrapper.find('[data-testid="resync-success"]').text()).toContain('3');

        await wrapper.find('[data-testid="resync-btn"]').trigger('click');
        await flushPromises();
        expect(wrapper.find('[data-testid="resync-success"]').text()).toContain('0');
        expect(wrapper.find('[data-testid="resync-error"]').exists()).toBe(false);
    });
});
