import axios from 'axios';
import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import TrainingAssignmentFormModal from '@/pages/assignments/Partials/TrainingAssignmentFormModal.vue';
import { useRequirementsStore } from '@/stores/requirements';
import type { TrainingAssignmentRow } from '@/stores/trainingAssignments';

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
    (axios.get as ReturnType<typeof vi.fn>).mockImplementation((url: string) => {
        if (url === '/api/trainings')
            return Promise.resolve({ data: [{ id: 't1', name: 'Fall Protection' }] });
        if (url === '/api/requirements')
            return Promise.resolve({ data: [{ id: 'r1', name: 'Forklift Safety Package' }] });
        return Promise.resolve({ data: [] });
    });

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
    });

    // -- create mode ---------------------------------------------------

    it('shows both trainings and requirements in a single picker', async () => {
        const wrapper = await mountModal();
        const text = wrapper.text();
        expect(text).toContain('Fall Protection');
        expect(text).toContain('Forklift Safety Package');
    });

    it('has no source-type selector exposed to the user', async () => {
        const wrapper = await mountModal();
        expect(wrapper.find('[data-testid="source-type-select"]').exists()).toBe(false);
    });

    it('calls assignDirect when a training is selected', async () => {
        (axios.post as ReturnType<typeof vi.fn>).mockResolvedValue({ data: [ta()] });

        const wrapper = await mountModal({ initialUserId: 'u1' });
        await wrapper.find('[data-testid="item-select"]').setValue('training:t1');
        await wrapper.find('form').trigger('submit');
        await flushPromises();

        expect(axios.post).toHaveBeenCalledWith(
            '/api/training-assignments',
            { source_type: 'direct', user_id: 'u1', training_id: 't1' },
            expect.any(Object),
        );
    });

    it('calls assignFromRequirement when a requirement is selected', async () => {
        (axios.post as ReturnType<typeof vi.fn>).mockResolvedValue({ data: [ta()] });

        const wrapper = await mountModal({ initialUserId: 'u1' });
        await wrapper.find('[data-testid="item-select"]').setValue('requirement:r1');
        await wrapper.find('form').trigger('submit');
        await flushPromises();

        expect(axios.post).toHaveBeenCalledWith(
            '/api/training-assignments',
            { source_type: 'requirement', user_id: 'u1', requirement_id: 'r1' },
            expect.any(Object),
        );
    });

    it('emits update:open=false after successful create', async () => {
        (axios.post as ReturnType<typeof vi.fn>).mockResolvedValue({ data: [ta()] });

        const wrapper = await mountModal({ initialUserId: 'u1' });
        await wrapper.find('[data-testid="item-select"]').setValue('training:t1');
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

        const wrapper = await mountModal({ mode: 'view', target: ta({ id: 'ta-1' }) });
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

        const wrapper = await mountModal({ mode: 'view', target: ta({ id: 'ta-1' }) });
        await wrapper.find('[data-testid="delete-btn"]').trigger('click');
        await flushPromises();

        expect(axios.delete).not.toHaveBeenCalled();
    });

    // -- view mode: break from requirement --------------------------------

    const REQ_SOURCE = {
        id: 'src-1',
        sourceable_type: 'App\\Models\\Requirement',
        sourceable_id: 'r1',
        added_at: '2026-01-01T00:00:00.000Z',
    };

    it('shows "Remove from requirement" button when TA has a requirement source and can_delete', async () => {
        const wrapper = await mountModal({
            mode: 'view',
            target: ta({ can_delete: true, active_sources: [REQ_SOURCE] }),
        });
        expect(wrapper.find('[data-testid="break-from-requirement-btn"]').exists()).toBe(true);
    });

    it('does not show "Remove from requirement" when TA has only direct sources', async () => {
        const wrapper = await mountModal({
            mode: 'view',
            target: ta({
                can_delete: true,
                active_sources: [{ id: 'src-d', sourceable_type: null, sourceable_id: null, added_at: '' }],
            }),
        });
        expect(wrapper.find('[data-testid="break-from-requirement-btn"]').exists()).toBe(false);
    });

    it('does not show "Remove from requirement" when can_delete is false', async () => {
        const wrapper = await mountModal({
            mode: 'view',
            target: ta({ can_delete: false, active_sources: [REQ_SOURCE] }),
        });
        expect(wrapper.find('[data-testid="break-from-requirement-btn"]').exists()).toBe(false);
    });

    it('shows the requirement name in the explanation text', async () => {
        useRequirementsStore().library = [
            { id: 'r1', name: 'Forklift Safety', description: null, elements_count: 4, can_edit: true, can_delete: true },
        ];
        const wrapper = await mountModal({
            mode: 'view',
            target: ta({ can_delete: true, active_sources: [REQ_SOURCE] }),
        });
        expect(wrapper.text()).toContain('Forklift Safety');
    });

    it('calls breakFromRequirement and closes on success', async () => {
        (axios.delete as ReturnType<typeof vi.fn>).mockResolvedValue({
            data: { deleted_id: 'ta-1', updated_ids: [] },
        });

        const wrapper = await mountModal({
            mode: 'view',
            target: ta({ id: 'ta-1', can_delete: true, active_sources: [REQ_SOURCE] }),
        });
        await wrapper.find('[data-testid="break-from-requirement-btn"]').trigger('click');
        await flushPromises();

        expect(axios.delete).toHaveBeenCalledWith(
            '/api/training-assignments/ta-1/from-requirement',
            expect.objectContaining({ data: { requirement_id: 'r1' } }),
        );
        expect(wrapper.emitted('update:open')).toEqual([[false]]);
    });
});
