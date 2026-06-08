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

const rows = [
    {
        id: 'ta-1',
        user_id: 'u1',
        user_name: 'Alice Smith',
        training_name: 'Forklift Safety',
        expires_at: '2026-07-15',
    },
    {
        id: 'ta-2',
        user_id: 'u2',
        user_name: 'Bob Jones',
        training_name: 'Fall Protection',
        expires_at: '2026-08-01',
    },
];

async function mountWidget(mockRows: unknown[] = rows) {
    (axios.get as ReturnType<typeof vi.fn>).mockResolvedValue({ data: mockRows });
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

    it('renders a row per result', async () => {
        const wrapper = await mountWidget();
        const items = wrapper.findAll('li');
        expect(items).toHaveLength(2);
    });

    it('shows user name as a link to the user detail page', async () => {
        const wrapper = await mountWidget();
        const link = wrapper.find('a');
        expect(link.text()).toContain('Alice Smith');
        expect(link.attributes('href')).toBe('/users/u1');
    });

    it('shows training name and expires_at', async () => {
        const wrapper = await mountWidget();
        const text = wrapper.text();
        expect(text).toContain('Forklift Safety');
        expect(text).toContain('2026-07-15');
    });

    it('shows empty state when there are no rows', async () => {
        const wrapper = await mountWidget([]);
        expect(wrapper.find('ul').exists()).toBe(false);
        expect(wrapper.text()).toContain('No training assignments expiring soon.');
    });

    it('shows error message on fetch failure', async () => {
        (axios.get as ReturnType<typeof vi.fn>).mockRejectedValue(new Error('Network error'));
        const wrapper = mount(TrainingDueSoonWidget);
        await flushPromises();
        expect(wrapper.text()).toContain('Network error');
    });
});
