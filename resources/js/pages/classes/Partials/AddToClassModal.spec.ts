import { flushPromises, mount } from '@vue/test-utils';
import axios from 'axios';
import { createPinia, setActivePinia } from 'pinia';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import AddToClassModal from '@/pages/classes/Partials/AddToClassModal.vue';

vi.mock('axios');
vi.mock('@/echo', () => ({ realtimeTabId: () => 'tab' }));
vi.mock('@/composables/useRealtime', () => ({ useRealtime: () => ({ bind: vi.fn() }) }));

const META = { current_page: 1, last_page: 1, per_page: 100, total: 1 };

function classRow() {
    return {
        id: 'c1',
        name: 'Spring Fall-Protection',
        scheduled_date: '2026-03-01',
        instructor: 'Pat',
        enrollments_count: 2,
        trainings_count: 1,
        status: 'scheduled',
    };
}

async function openModal(rows = [classRow()]) {
    (axios.get as ReturnType<typeof vi.fn>).mockResolvedValue({
        data: { data: rows, meta: { ...META, total: rows.length } },
    });
    (axios.post as ReturnType<typeof vi.fn>).mockResolvedValue({ data: { id: 'c1' } });

    const wrapper = mount(AddToClassModal, {
        props: { open: false, trainingId: 't1', trainingName: 'Fall Protection', userIds: ['u1', 'u2'] },
        attachTo: document.body,
    });
    await wrapper.setProps({ open: true });
    await flushPromises();

    return wrapper;
}

describe('AddToClassModal', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        vi.clearAllMocks();
    });
    afterEach(() => {
        document.body.innerHTML = '';
    });

    it('fetches scheduled classes for the training and lists them', async () => {
        await openModal();

        expect(axios.get).toHaveBeenCalledWith(
            '/api/classes',
            expect.objectContaining({
                params: expect.objectContaining({ training_id: 't1', status: 'scheduled' }),
            }),
        );
        expect(document.body.textContent).toContain('Spring Fall-Protection');
    });

    it('enrolls the selected users into the picked class and emits added', async () => {
        const wrapper = await openModal();

        document.body
            .querySelector<HTMLButtonElement>('[data-testid="add-to-class-c1"]')!
            .click();
        await flushPromises();

        expect(axios.post).toHaveBeenCalledWith(
            '/api/classes/c1/enrollments/bulk',
            { enroll: ['u1', 'u2'], unenroll: [] },
            expect.anything(),
        );
        expect(wrapper.emitted('added')?.[0]).toEqual(['c1']);
    });

    it('shows an empty state when no scheduled class includes the training', async () => {
        await openModal([]);
        expect(document.body.textContent).toContain('No scheduled classes include this training');
    });
});
