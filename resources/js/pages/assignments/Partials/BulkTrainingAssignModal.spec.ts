import axios from 'axios';
import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import BulkTrainingAssignModal from '@/pages/assignments/Partials/BulkTrainingAssignModal.vue';

vi.mock('axios');
vi.mock('@/echo', () => ({ realtimeTabId: () => 'test-tab' }));
vi.mock('@/composables/useRealtime', () => ({
    useRealtime: vi.fn(() => ({ bind: vi.fn(), leave: vi.fn() })),
}));

const STUBS = {
    Dialog: { template: '<div v-if="open"><slot /></div>', props: ['open'] },
    DialogContent: { template: '<div><slot /></div>' },
    DialogHeader: { template: '<div><slot /></div>' },
    DialogTitle: { template: '<div><slot /></div>' },
    DialogDescription: { template: '<div><slot /></div>' },
    DialogFooter: { template: '<div><slot /></div>' },
    ErrorBanner: true,
    InputError: true,
};

async function mountModal(userIds: string[] = ['u1', 'u2']) {
    (axios.get as ReturnType<typeof vi.fn>).mockImplementation((url: string) => {
        if (url === '/api/trainings')
            return Promise.resolve({ data: [{ id: 't1', name: 'Fall Protection' }] });
        if (url === '/api/requirements')
            return Promise.resolve({ data: [{ id: 'r1', name: 'Forklift Safety Package' }] });
        return Promise.resolve({ data: [] });
    });
    const wrapper = mount(BulkTrainingAssignModal, {
        props: { open: true, userIds },
        global: { stubs: STUBS },
    });
    await flushPromises();
    return wrapper;
}

describe('BulkTrainingAssignModal', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        vi.clearAllMocks();
    });

    it('shows the user count in the title', async () => {
        const wrapper = await mountModal(['u1', 'u2', 'u3']);
        expect(wrapper.text()).toContain('3 users');
    });

    it('shows the training picker when source_type is direct', async () => {
        const wrapper = await mountModal();
        expect(wrapper.text()).toContain('Fall Protection');
    });

    it('shows the requirement picker when source_type is requirement', async () => {
        const wrapper = await mountModal();
        await wrapper.find('[data-testid="source-type-select"]').setValue('requirement');
        await flushPromises();
        expect(wrapper.text()).toContain('Forklift Safety Package');
    });

    it('POSTs to /api/bulk-training-assignments with correct payload', async () => {
        (axios.post as ReturnType<typeof vi.fn>).mockResolvedValue({
            data: { created_count: 2, skipped_count: 0 },
        });

        const wrapper = await mountModal(['u1', 'u2']);
        await wrapper.find('[data-testid="training-select"]').setValue('t1');
        await wrapper.find('form').trigger('submit');
        await flushPromises();

        expect(axios.post).toHaveBeenCalledWith(
            '/api/bulk-training-assignments',
            { user_ids: ['u1', 'u2'], source_type: 'direct', training_id: 't1' },
            expect.any(Object),
        );
    });

    it('emits applied with the result after success', async () => {
        (axios.post as ReturnType<typeof vi.fn>).mockResolvedValue({
            data: { created_count: 2, skipped_count: 0 },
        });

        const wrapper = await mountModal(['u1', 'u2']);
        await wrapper.find('[data-testid="training-select"]').setValue('t1');
        await wrapper.find('form').trigger('submit');
        await flushPromises();

        expect(wrapper.emitted('applied')).toEqual([[{ created_count: 2, skipped_count: 0 }]]);
    });

    it('emits update:open=false after success', async () => {
        (axios.post as ReturnType<typeof vi.fn>).mockResolvedValue({
            data: { created_count: 2, skipped_count: 0 },
        });

        const wrapper = await mountModal(['u1', 'u2']);
        await wrapper.find('[data-testid="training-select"]').setValue('t1');
        await wrapper.find('form').trigger('submit');
        await flushPromises();

        expect(wrapper.emitted('update:open')).toEqual([[false]]);
    });

    it('POSTs requirement_id when source_type is requirement', async () => {
        (axios.post as ReturnType<typeof vi.fn>).mockResolvedValue({
            data: { created_count: 4, skipped_count: 0 },
        });

        const wrapper = await mountModal(['u1', 'u2']);
        await wrapper.find('[data-testid="source-type-select"]').setValue('requirement');
        await flushPromises();
        await wrapper.find('[data-testid="requirement-select"]').setValue('r1');
        await wrapper.find('form').trigger('submit');
        await flushPromises();

        expect(axios.post).toHaveBeenCalledWith(
            '/api/bulk-training-assignments',
            { user_ids: ['u1', 'u2'], source_type: 'requirement', requirement_id: 'r1' },
            expect.any(Object),
        );
    });
});
