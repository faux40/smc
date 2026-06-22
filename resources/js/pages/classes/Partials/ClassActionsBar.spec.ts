import { flushPromises, mount } from '@vue/test-utils';
import axios from 'axios';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import AddToClassModal from '@/pages/classes/Partials/AddToClassModal.vue';
import ClassFormModal from '@/pages/classes/Partials/ClassFormModal.vue';
import ClassActionsBar from '@/pages/classes/Partials/ClassActionsBar.vue';

vi.mock('axios');
vi.mock('@/echo', () => ({ realtimeTabId: () => 'tab' }));
const { routerVisit } = vi.hoisted(() => ({ routerVisit: vi.fn() }));
vi.mock('@inertiajs/vue3', () => ({ router: { visit: routerVisit } }));

function mountBar(props = {}) {
    return mount(ClassActionsBar, {
        props: {
            selectedUserIds: ['u1', 'u2'],
            createTrainingIds: ['t1'],
            presetName: 'Fall Protection',
            addTrainingId: 't1',
            addTrainingName: 'Fall Protection',
            ...props,
        },
        global: { stubs: { ClassFormModal: true, AddToClassModal: true } },
    });
}

describe('ClassActionsBar', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        vi.clearAllMocks();
        (axios.post as ReturnType<typeof vi.fn>).mockResolvedValue({ data: {} });
    });

    it('enrolls the selection into a newly created class, then navigates', async () => {
        const wrapper = mountBar();

        wrapper.findComponent(ClassFormModal).vm.$emit('saved', { id: 'c1' });
        await flushPromises();

        expect(axios.post).toHaveBeenCalledWith(
            '/api/classes/c1/enrollments/bulk',
            { enroll: ['u1', 'u2'], unenroll: [] },
            expect.anything(),
        );
        expect(routerVisit).toHaveBeenCalledWith(expect.objectContaining({ url: '/classes/c1' }));
    });

    it('navigates after adding to an existing class', async () => {
        const wrapper = mountBar();

        wrapper.findComponent(AddToClassModal).vm.$emit('added', 'c9');
        await flushPromises();

        expect(routerVisit).toHaveBeenCalledWith(expect.objectContaining({ url: '/classes/c9' }));
    });

    it('hides "add to existing class" when no single training is given', () => {
        const wrapper = mountBar({ addTrainingId: undefined });
        expect(wrapper.find('[data-testid="add-to-class"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="assemble-class"]').exists()).toBe(true);
    });

    it('disables both actions when nothing is selected', () => {
        const wrapper = mountBar({ selectedUserIds: [] });
        expect(wrapper.find('[data-testid="add-to-class"]').attributes('disabled')).toBeDefined();
        expect(wrapper.find('[data-testid="assemble-class"]').attributes('disabled')).toBeDefined();
    });
});
