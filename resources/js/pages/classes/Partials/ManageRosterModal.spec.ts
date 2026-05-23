import { flushPromises, mount } from '@vue/test-utils';
import axios from 'axios';
import { createPinia, setActivePinia } from 'pinia';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import ManageRosterModal from '@/pages/classes/Partials/ManageRosterModal.vue';
import type { ClassDetail } from '@/stores/classes';
import { useClassesStore } from '@/stores/classes';

vi.mock('axios');

const detail: ClassDetail = {
    id: 'c1',
    name: 'Class A',
    scheduled_date: '2026-06-01',
    location: null,
    training_location: null,
    training_address: null,
    instructor: null,
    total_hours: null,
    notes: null,
    status: 'scheduled',
    completion_date: null,
    can_edit: true,
    trainings: [],
    enrollments: [
        {
            id: 'e1',
            user_id: 'u1',
            user_name: 'Dana Reed',
            user_email: 'dana@x.com',
            status: 'enrolled',
            notes: null,
        },
    ],
};

const users = [
    { id: 'u1', f_name: 'Dana', l_name: 'Reed', email: 'dana@x.com' },
    { id: 'u2', f_name: 'Sam', l_name: 'Lee', email: 'sam@x.com' },
];

describe('ManageRosterModal', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        vi.clearAllMocks();
        useClassesStore().detail = { c1: detail };
        (axios.post as ReturnType<typeof vi.fn>).mockResolvedValue({ data: detail });
    });

    afterEach(() => {
        document.body.innerHTML = '';
    });

    it('enrolls an available student via the + button', async () => {
        mount(ManageRosterModal, {
            props: { open: true, classId: 'c1', users },
            attachTo: document.body,
        });
        await flushPromises();

        // Reveal the Available list (button text = the addLabel).
        const reveal = Array.from(
            document.body.querySelectorAll<HTMLButtonElement>('button'),
        ).find((b) => b.textContent?.includes('Enroll students'));
        reveal!.click();
        await flushPromises();

        // Only u2 is available (u1 already enrolled) → its + button.
        const add = document.body.querySelector<HTMLButtonElement>(
            'button[aria-label="Add"]',
        );
        add!.click();
        await flushPromises();

        expect(axios.post).toHaveBeenCalledWith(
            '/api/classes/c1/enrollments',
            { user_id: 'u2' },
            expect.anything(),
        );
    });
});
