import { flushPromises, mount } from '@vue/test-utils';
import axios from 'axios';
import { createPinia, setActivePinia } from 'pinia';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import ManageRosterModal from '@/pages/classes/Partials/ManageRosterModal.vue';
import UsersBulkAddGrid from '@/pages/users/Partials/UsersBulkAddGrid.vue';
import type { ClassDetail } from '@/stores/classes';
import { useClassesStore } from '@/stores/classes';
import { useUsersStore } from '@/stores/users';

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
            results: {},
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

    // ── Data-loss regression: a load race must never de-enroll the roster ──
    // A manager re-opens a completed class to fix a typo; the roster hadn't
    // finished loading when the modal opened. Closing with no user changes must
    // NOT unenroll everyone (the reported bug).
    it('does not unenroll the roster when detail loads after the modal opens', async () => {
        const store = useClassesStore();
        // The race: the modal opens before the roster detail has loaded.
        store.detail = {};

        const wrapper = mount(ManageRosterModal, {
            props: { open: false, classId: 'c1', users },
            attachTo: document.body,
        });
        await wrapper.setProps({ open: true }); // opens with no detail yet
        await flushPromises();

        // Roster arrives after the modal is already open.
        store.detail = { c1: detail };
        await flushPromises();

        // The user changed nothing → closing must not touch the server at all.
        findBtn('Done')!.click();
        await flushPromises();

        expect(axios.post).not.toHaveBeenCalled();
    });

    it('bails on close when detail never loaded during the open lifecycle', async () => {
        const store = useClassesStore();
        store.detail = {}; // never populates

        const wrapper = mount(ManageRosterModal, {
            props: { open: false, classId: 'c1', users },
            attachTo: document.body,
        });
        await wrapper.setProps({ open: true });
        await flushPromises();

        findBtn('Done')!.click();
        await flushPromises();

        expect(axios.post).not.toHaveBeenCalled();
    });

    it('unenrolls only the student the user actively removed', async () => {
        const store = useClassesStore();
        store.detail = {
            c1: {
                ...detail,
                enrollments: [
                    detail.enrollments[0], // u1 / e1
                    {
                        ...detail.enrollments[0],
                        id: 'e2',
                        user_id: 'u2',
                        user_name: 'Lee, Sam',
                        user_email: 'sam@x.com',
                    },
                ],
            },
        };

        const wrapper = mount(ManageRosterModal, {
            props: { open: false, classId: 'c1', users },
            attachTo: document.body,
        });
        await wrapper.setProps({ open: true });
        await flushPromises();

        // Remove the first enrolled student (u1 / e1) via its × button.
        const remove = document.body.querySelector<HTMLButtonElement>(
            'button[aria-label="Remove"]',
        );
        remove!.click();
        await flushPromises();

        findBtn('Done')!.click();
        await flushPromises();

        expect(axios.post).toHaveBeenCalledWith(
            '/api/classes/c1/enrollments/bulk',
            { enroll: [], unenroll: ['e1'] },
            expect.anything(),
        );
    });

    it('flags a full multi-person clear with confirm_clear', async () => {
        const store = useClassesStore();
        store.detail = {
            c1: {
                ...detail,
                enrollments: [
                    detail.enrollments[0], // u1 / e1
                    {
                        ...detail.enrollments[0],
                        id: 'e2',
                        user_id: 'u2',
                        user_name: 'Lee, Sam',
                        user_email: 'sam@x.com',
                    },
                ],
            },
        };

        const wrapper = mount(ManageRosterModal, {
            props: { open: false, classId: 'c1', users },
            attachTo: document.body,
        });
        await wrapper.setProps({ open: true });
        await flushPromises();

        // Remove both enrolled students.
        let remove = document.body.querySelector<HTMLButtonElement>(
            'button[aria-label="Remove"]',
        );
        remove!.click();
        await flushPromises();
        remove = document.body.querySelector<HTMLButtonElement>(
            'button[aria-label="Remove"]',
        );
        remove!.click();
        await flushPromises();

        findBtn('Done')!.click();
        await flushPromises();

        expect(axios.post).toHaveBeenCalledWith(
            '/api/classes/c1/enrollments/bulk',
            { enroll: [], unenroll: ['e1', 'e2'], confirm_clear: true },
            expect.anything(),
        );
    });

    it('offers inline add-user controls when the class is editable', async () => {
        await openModal();
        expect(document.body.textContent).toContain('Add a person');
        expect(document.body.textContent).toContain('Bulk add');
    });

    it('auto-enrolls a user created via the inline bulk grid on close', async () => {
        const wrapper = mount(ManageRosterModal, {
            props: { open: false, classId: 'c1', users },
            attachTo: document.body,
            global: { stubs: { UsersBulkAddGrid: true, UserFormModal: true } },
        });
        await wrapper.setProps({ open: true });
        await flushPromises();

        const usersStore = useUsersStore();
        vi.spyOn(usersStore, 'loadPicker').mockResolvedValue();

        // Reveal the bulk grid, then simulate it reporting a created user.
        const toggle = document.body.querySelector<HTMLButtonElement>(
            '[data-testid="roster-bulk-toggle"]',
        );
        toggle!.click();
        await flushPromises();

        wrapper.findComponent(UsersBulkAddGrid).vm.$emit('created', ['newU']);
        await flushPromises();

        // Closing commits the queued enrollment for the new user.
        findBtn('Done')!.click();
        await flushPromises();

        expect(usersStore.loadPicker).toHaveBeenCalledWith(true);
        expect(axios.post).toHaveBeenCalledWith(
            '/api/classes/c1/enrollments/bulk',
            { enroll: ['newU'], unenroll: [] },
            expect.anything(),
        );
    });

    // ── Disabled-user toggle ────────────────────────────────────────────────
    // A manager needs to enroll a disabled/inactive person occasionally (e.g.
    // recording history, someone on leave). Disabled people are hidden from
    // the Available pool by default; a toggle reveals them.
    const usersWithDisabled = [
        {
            id: 'u1',
            sort_name: 'Reed, Dana',
            email: 'dana@x.com',
            tag_ids: [],
            status: 'active' as const,
        },
        {
            id: 'u2',
            sort_name: 'Lee, Sam',
            email: 'sam@x.com',
            tag_ids: [],
            status: 'active' as const,
        },
        {
            id: 'u3',
            sort_name: 'Kim, Pat',
            email: 'pat@x.com',
            tag_ids: [],
            status: 'disabled' as const,
        },
    ];

    it('hides disabled users from the Available list by default', async () => {
        const wrapper = mount(ManageRosterModal, {
            props: { open: false, classId: 'c1', users: usersWithDisabled },
            attachTo: document.body,
        });
        await wrapper.setProps({ open: true });
        await flushPromises();

        // u1 is enrolled (baseline) → Available candidates are u2 (active)
        // and u3 (disabled). u3 must be hidden by default.
        const availRows = document.body
            .querySelectorAll('table')[1]
            .querySelectorAll('tbody tr');
        expect(availRows).toHaveLength(1);
        expect(availRows[0].textContent).toContain('Lee, Sam');
        expect(document.body.textContent).not.toContain('Kim, Pat');
    });

    it('reveals disabled users in Available when the toggle is switched on', async () => {
        const wrapper = mount(ManageRosterModal, {
            props: { open: false, classId: 'c1', users: usersWithDisabled },
            attachTo: document.body,
        });
        await wrapper.setProps({ open: true });
        await flushPromises();

        const toggle = document.body.querySelector<HTMLElement>(
            '[data-testid="roster-show-disabled"]',
        );
        toggle!.click();
        await flushPromises();

        const availRows = document.body
            .querySelectorAll('table')[1]
            .querySelectorAll('tbody tr');
        expect(availRows).toHaveLength(2);

        const patRow = Array.from(availRows).find((r) =>
            r.textContent?.includes('Kim, Pat'),
        );
        expect(patRow).toBeDefined();
        // Marked visually as disabled.
        expect(patRow!.textContent).toContain('Disabled');
    });

    it('always shows an enrolled disabled user on the Enrolled side, toggle or not', async () => {
        const store = useClassesStore();
        store.detail = {
            c1: {
                ...detail,
                enrollments: [
                    detail.enrollments[0], // u1 / e1
                    {
                        ...detail.enrollments[0],
                        id: 'e3',
                        user_id: 'u3',
                        user_name: 'Kim, Pat',
                        user_email: 'pat@x.com',
                    },
                ],
            },
        };

        const wrapper = mount(ManageRosterModal, {
            props: { open: false, classId: 'c1', users: usersWithDisabled },
            attachTo: document.body,
        });
        await wrapper.setProps({ open: true });
        await flushPromises();

        // Toggle stays off — the disabled-but-enrolled user still shows.
        const assignedRows = document.body
            .querySelectorAll('table')[0]
            .querySelectorAll('tbody tr');
        expect(
            Array.from(assignedRows).some((r) =>
                r.textContent?.includes('Kim, Pat'),
            ),
        ).toBe(true);
    });

    it('shuttles a disabled user in and commits the enrollment', async () => {
        const wrapper = mount(ManageRosterModal, {
            props: { open: false, classId: 'c1', users: usersWithDisabled },
            attachTo: document.body,
        });
        await wrapper.setProps({ open: true });
        await flushPromises();

        const toggle = document.body.querySelector<HTMLElement>(
            '[data-testid="roster-show-disabled"]',
        );
        toggle!.click();
        await flushPromises();

        const availRows = document.body
            .querySelectorAll('table')[1]
            .querySelectorAll('tbody tr');
        const patRow = Array.from(availRows).find((r) =>
            r.textContent?.includes('Kim, Pat'),
        );
        patRow!.querySelector<HTMLButtonElement>('button[aria-label="Add"]')!.click();
        await flushPromises();

        findBtn('Done')!.click();
        await flushPromises();

        expect(axios.post).toHaveBeenCalledWith(
            '/api/classes/c1/enrollments/bulk',
            { enroll: ['u3'], unenroll: [] },
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
