import { flushPromises, mount } from '@vue/test-utils';
import axios from 'axios';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import TrainingDueSoonWidget from '@/components/dashboard/TrainingDueSoonWidget.vue';

vi.mock('axios');
vi.mock('@inertiajs/vue3', () => ({
    Link: { template: '<a :href="href"><slot /></a>', props: ['href'] },
}));
vi.mock('@/routes/users', () => ({
    show: (id: string) => ({ url: `/users/${id}` }),
}));
vi.mock('@/echo', () => ({ realtimeTabId: () => 'test-tab' }));

const OVERDUE_ROW = {
    id: 'ta-3',
    user_id: 'u3',
    user_name: 'Carol White',
    training_name: 'Fire Safety',
    expires_at: null,
};

const DUE_SOON_ROW = {
    id: 'ta-1',
    user_id: 'u1',
    user_name: 'Alice Smith',
    training_name: 'Forklift Safety',
    expires_at: '2026-07-15',
};

const EMPTY_RESPONSE = { overdue: [], due_soon: [] };

async function mountWidget(response = { overdue: [OVERDUE_ROW], due_soon: [DUE_SOON_ROW] }) {
    (axios.get as ReturnType<typeof vi.fn>).mockResolvedValue({ data: response });
    const wrapper = mount(TrainingDueSoonWidget);
    await flushPromises();
    return wrapper;
}

describe('TrainingDueSoonWidget', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        vi.clearAllMocks();
    });

    it('fetches /api/dashboard/training-due-soon on mount', async () => {
        await mountWidget();
        expect(axios.get).toHaveBeenCalledWith(
            '/api/dashboard/training-due-soon',
            expect.any(Object),
        );
    });

    it('renders an "Overdue" section heading when overdue rows exist', async () => {
        const wrapper = await mountWidget({ overdue: [OVERDUE_ROW], due_soon: [] });
        expect(wrapper.text()).toMatch(/overdue/i);
    });

    it('renders an "Expiring soon" section heading when due_soon rows exist', async () => {
        const wrapper = await mountWidget({ overdue: [], due_soon: [DUE_SOON_ROW] });
        expect(wrapper.text()).toMatch(/expiring soon/i);
    });

    it('renders overdue row with user name link and training name', async () => {
        const wrapper = await mountWidget({ overdue: [OVERDUE_ROW], due_soon: [] });
        expect(wrapper.text()).toContain('Carol White');
        expect(wrapper.text()).toContain('Fire Safety');
    });

    it('shows "Never completed" label for overdue rows with no expires_at', async () => {
        const wrapper = await mountWidget({ overdue: [OVERDUE_ROW], due_soon: [] });
        expect(wrapper.text()).toContain('Never completed');
    });

    it('renders due_soon row with user name and expires_at', async () => {
        const wrapper = await mountWidget({ overdue: [], due_soon: [DUE_SOON_ROW] });
        expect(wrapper.text()).toContain('Alice Smith');
        expect(wrapper.text()).toContain('Forklift Safety');
        expect(wrapper.text()).toContain('2026-07-15');
    });

    it('shows empty state when both sections are empty', async () => {
        const wrapper = await mountWidget(EMPTY_RESPONSE);
        expect(wrapper.find('ul').exists()).toBe(false);
        expect(wrapper.text()).toContain('No overdue or expiring');
    });

    it('shows error message on fetch failure', async () => {
        (axios.get as ReturnType<typeof vi.fn>).mockRejectedValue(new Error('Network error'));
        const wrapper = mount(TrainingDueSoonWidget);
        await flushPromises();
        expect(wrapper.text()).toContain('Network error');
    });
});
