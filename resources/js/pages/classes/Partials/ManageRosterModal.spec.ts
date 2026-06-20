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
    start_time: null,
    end_time: null,
    location: null,
    address: null,
    instructor: null,
    show_signature: false,
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
            credited_training_ids: [],
        },
    ],
};

const users = [
    {
        id: 'u1',
        sort_name: 'Reed, Dana',
        email: 'dana@x.com',
        tag_ids: [],
    },
    {
        id: 'u2',
        sort_name: 'Lee, Sam',
        email: 'sam@x.com',
        tag_ids: ['t1'],
    },
];

async function openModal() {
    const wrapper = mount(ManageRosterModal, {
        props: { open: false, classId: 'c1', users },
        attachTo: document.body,
    });
    await wrapper.setProps({ open: true }); // triggers the init watch
    await flushPromises();

    return wrapper;
}

function findBtn(text: string) {
    return Array.from(
        document.body.querySelectorAll<HTMLButtonElement>('button'),
    ).find((b) => b.textContent?.trim() === text);
}

describe('ManageRosterModal', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        vi.clearAllMocks();
        useClassesStore().detail = { c1: detail };
        (axios.post as ReturnType<typeof vi.fn>).mockResolvedValue({
            data: detail,
        });
        (axios.delete as ReturnType<typeof vi.fn>).mockResolvedValue({
            data: detail,
        });
        // Tags library (loaded onMounted via /api/tags).
        (axios.get as ReturnType<typeof vi.fn>).mockResolvedValue({
            data: [
                {
                    id: 't1',
                    name: 'Field Crew',
                    color: '#16a34a',
                    font_color: null,
                },
            ],
        });
    });

    afterEach(() => {
        document.body.innerHTML = '';
    });

    it("shows a person's tags and filters by tag name", async () => {
        await openModal();

        // Sam (u2) carries the 'Field Crew' tag → its pill renders.
        expect(document.body.textContent).toContain('Field Crew');

        // The Available search box filters by tag name too.
        const search = document.body.querySelectorAll<HTMLInputElement>(
            'input[type="search"]',
        )[1];
        search.value = 'field';
        search.dispatchEvent(new Event('input', { bubbles: true }));
        await flushPromises();

        const availRows = document.body
            .querySelectorAll('table')[1]
            .querySelectorAll('tbody tr');
        expect(availRows).toHaveLength(1);
        expect(availRows[0].textContent).toContain('Lee, Sam');
    });

    it('opens with both lists shown (no reveal needed)', async () => {
        await openModal();
        // Two tables = both Assigned and Available are visible immediately.
        expect(document.body.querySelectorAll('table')).toHaveLength(2);
    });

    it('queues a move locally without hitting the server, then bulk-saves on close', async () => {
        await openModal();

        // u2 is the only Available row → click its + button.
        const add = document.body.querySelector<HTMLButtonElement>(
            'button[aria-label="Add"]',
        );
        add!.click();
        await flushPromises();

        // No server round-trip yet — purely local.
        expect(axios.post).not.toHaveBeenCalled();

        // Closing (Done) commits the queued change in one bulk request.
        findBtn('Done')!.click();
        await flushPromises();

        expect(axios.post).toHaveBeenCalledWith(
            '/api/classes/c1/enrollments/bulk',
            { enroll: ['u2'], unenroll: [] },
            expect.anything(),
        );
    });

    it('removing an enrolled student bulk-unenrolls on close', async () => {
        await openModal();

        // u1 (enrolled) is the only Assigned row → click its × button.
        const remove = document.body.querySelector<HTMLButtonElement>(
            'button[aria-label="Remove"]',
        );
        remove!.click();
        await flushPromises();
        expect(axios.post).not.toHaveBeenCalled();

        findBtn('Done')!.click();
        await flushPromises();

        expect(axios.post).toHaveBeenCalledWith(
            '/api/classes/c1/enrollments/bulk',
            { enroll: [], unenroll: ['e1'] },
            expect.anything(),
        );
    });

    it('filters the available list by department', async () => {
        const deptUsers = [
            // u1 is the enrolled student (in detail.enrollments).
            {
                id: 'u1',
                sort_name: 'Reed, Dana',
                email: 'd@x.com',
                department: 'Ops',
                tag_ids: [],
            },
            {
                id: 'u2',
                sort_name: 'Lee, Sam',
                email: 's@x.com',
                department: 'Field',
                tag_ids: [],
            },
            {
                id: 'u3',
                sort_name: 'Kim, Pat',
                email: 'p@x.com',
                department: 'Ops',
                tag_ids: [],
            },
        ];
        const wrapper = mount(ManageRosterModal, {
            props: { open: false, classId: 'c1', users: deptUsers },
            attachTo: document.body,
        });
        await wrapper.setProps({ open: true });
        await flushPromises();

        // Available before filtering: u2 (Field) + u3 (Ops).
        let availRows = document.body
            .querySelectorAll('table')[1]
            .querySelectorAll('tbody tr');
        expect(availRows).toHaveLength(2);

        const sel =
            document.body.querySelector<HTMLSelectElement>('#roster_dept');
        sel!.value = 'Field';
        sel!.dispatchEvent(new Event('change', { bubbles: true }));
        await flushPromises();

        availRows = document.body
            .querySelectorAll('table')[1]
            .querySelectorAll('tbody tr');
        expect(availRows).toHaveLength(1);
        expect(availRows[0].textContent).toContain('Lee, Sam');
    });
});
