import axios from 'axios';
import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import TrainingAssignmentFormModal from '@/pages/assignments/Partials/TrainingAssignmentFormModal.vue';

vi.mock('axios');
import type { TrainingAssignmentRow } from '@/stores/trainingAssignments';

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

function ta(overrides: Partial<TrainingAssignmentRow> = {}): TrainingAssignmentRow {
    return {
        id: 'ta-1',
        user_id: 'u1',
        training_id: 't1',
        name: 'Fall Protection',
        expires_at: null,
        last_completed_at: null,
        active_sources: [],
        can_delete: true,
        ...overrides,
    };
}

async function mountModal(props: Record<string, unknown> = {}) {
    const wrapper = mount(TrainingAssignmentFormModal, {
        props: { open: true, mode: 'create', ...props },
        global: { stubs: STUBS },
    });
    await flushPromises();
    return wrapper;
}

describe('TrainingAssignmentFormModal', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        vi.clearAllMocks();
        // Silence the stores' network calls from onMounted load().
        (axios.get as ReturnType<typeof vi.fn>).mockResolvedValue({ data: [] });
    });

    // -- create mode ---------------------------------------------------

    it('shows the training picker when source_type is direct', async () => {
        (axios.get as ReturnType<typeof vi.fn>).mockImplementation((url: string) =>
            url === '/api/trainings'
                ? Promise.resolve({ data: [{ id: 't1', name: 'Fall Protection' }] })
                : Promise.resolve({ data: [] }),
        );

        const wrapper = await mountModal();
        expect(wrapper.text()).toContain('Fall Protection');
    });

    it('shows the requirement picker when source_type is requirement', async () => {
        (axios.get as ReturnType<typeof vi.fn>).mockImplementation((url: string) =>
            url === '/api/requirements'
                ? Promise.resolve({ data: [{ id: 'r1', name: 'Forklift Safety Package' }] })
                : Promise.resolve({ data: [] }),
        );

        const wrapper = await mountModal();
        const select = wrapper.find('[data-testid="source-type-select"]');
        await select.setValue('requirement');
        await flushPromises();
        expect(wrapper.text()).toContain('Forklift Safety Package');
    });

    it('calls assignDirect when submitting a direct assignment', async () => {
        (axios.get as ReturnType<typeof vi.fn>).mockImplementation((url: string) =>
            url === '/api/trainings'
                ? Promise.resolve({ data: [{ id: 't1', name: 'Fall Protection' }] })
                : Promise.resolve({ data: [] }),
        );
        (axios.post as ReturnType<typeof vi.fn>).mockResolvedValue({ data: [ta()] });

        const wrapper = await mountModal({ initialUserId: 'u1' });
        await wrapper.find('[data-testid="training-select"]').setValue('t1');
        await wrapper.find('form').trigger('submit');
        await flushPromises();

        expect(axios.post).toHaveBeenCalledWith(
            '/api/training-assignments',
            { source_type: 'direct', user_id: 'u1', training_id: 't1' },
            expect.any(Object),
        );
    });

    it('calls assignFromRequirement when submitting a requirement assignment', async () => {
        (axios.get as ReturnType<typeof vi.fn>).mockImplementation((url: string) =>
            url === '/api/requirements'
                ? Promise.resolve({ data: [{ id: 'r1', name: 'Forklift Safety Package' }] })
                : Promise.resolve({ data: [] }),
        );
        (axios.post as ReturnType<typeof vi.fn>).mockResolvedValue({ data: [ta()] });

        const wrapper = await mountModal({ initialUserId: 'u1' });
        const sourceSelect = wrapper.find('[data-testid="source-type-select"]');
        await sourceSelect.setValue('requirement');
        await flushPromises();

        await wrapper.find('[data-testid="requirement-select"]').setValue('r1');
        await wrapper.find('form').trigger('submit');
        await flushPromises();

        expect(axios.post).toHaveBeenCalledWith(
            '/api/training-assignments',
            { source_type: 'requirement', user_id: 'u1', requirement_id: 'r1' },
            expect.any(Object),
        );
    });

    it('emits update:open=false after successful create', async () => {
        (axios.get as ReturnType<typeof vi.fn>).mockImplementation((url: string) =>
            url === '/api/trainings'
                ? Promise.resolve({ data: [{ id: 't1', name: 'Fall Protection' }] })
                : Promise.resolve({ data: [] }),
        );
        (axios.post as ReturnType<typeof vi.fn>).mockResolvedValue({ data: [ta()] });

        const wrapper = await mountModal({ initialUserId: 'u1' });
        await wrapper.find('[data-testid="training-select"]').setValue('t1');
        await wrapper.find('form').trigger('submit');
        await flushPromises();

        expect(wrapper.emitted('update:open')).toEqual([[false]]);
    });

    // -- view mode -----------------------------------------------------

    it('shows the training name in view mode', async () => {
        const wrapper = await mountModal({
            mode: 'view',
            target: ta({ name: 'Confined Space Entry' }),
        });
        expect(wrapper.text()).toContain('Confined Space Entry');
    });

    it('shows a delete button when can_delete is true in view mode', async () => {
        const wrapper = await mountModal({
            mode: 'view',
            target: ta({ can_delete: true }),
        });
        expect(wrapper.find('[data-testid="delete-btn"]').exists()).toBe(true);
    });

    it('does not show a delete button when can_delete is false', async () => {
        const wrapper = await mountModal({
            mode: 'view',
            target: ta({ can_delete: false }),
        });
        expect(wrapper.find('[data-testid="delete-btn"]').exists()).toBe(false);
    });

    it('calls destroy and closes when delete is confirmed', async () => {
        (axios.delete as ReturnType<typeof vi.fn>).mockResolvedValue({ data: { ok: true } });
        vi.stubGlobal('confirm', vi.fn().mockReturnValue(true));

        const wrapper = await mountModal({
            mode: 'view',
            target: ta({ id: 'ta-1' }),
        });

        await wrapper.find('[data-testid="delete-btn"]').trigger('click');
        await flushPromises();

        expect(axios.delete).toHaveBeenCalledWith(
            '/api/training-assignments/ta-1',
            expect.any(Object),
        );
        expect(wrapper.emitted('update:open')).toEqual([[false]]);
    });

    it('does not call destroy when delete is cancelled', async () => {
        vi.stubGlobal('confirm', vi.fn().mockReturnValue(false));

        const wrapper = await mountModal({
            mode: 'view',
            target: ta({ id: 'ta-1' }),
        });

        await wrapper.find('[data-testid="delete-btn"]').trigger('click');
        await flushPromises();

        expect(axios.delete).not.toHaveBeenCalled();
    });
});
