import { flushPromises, mount } from '@vue/test-utils';
import axios from 'axios';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import AttachmentsList from '@/components/AttachmentsList.vue';
import Show from '@/pages/classes/Show.vue';
import type { ClassDetail } from '@/stores/classes';

vi.mock('axios');
const routerVisit = vi.fn();
vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<div><slot /></div>' },
    Link: { template: '<a :href="href"><slot /></a>', props: ['href'] },
    usePage: () => ({ props: { auth: { user: { org_id: 'org1' } } } }),
    router: { visit: (...args: unknown[]) => routerVisit(...args) },
}));
vi.mock('@/routes/classes', () => ({
    page: () => ({ url: '/classes' }),
    showPage: (id: string) => ({ url: `/classes/${id}` }),
}));

const detail: ClassDetail = {
    id: 'c1',
    name: 'Fall Protection',
    scheduled_date: '2026-06-01',
    start_time: null,
    end_time: null,
    location: 'Yard 3',
    address: null,
    instructor: 'J. Cole',
    show_signature: false,
    total_hours: '4.00',
    min_students: null,
    max_students: null,
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
            user_sort_name: 'Reed, Dana',
            user_email: 'dana.reed@demo.local',
            status: 'enrolled',
            notes: null,
            credited_training_ids: [],
            results: {},
        },
    ],
};

function mockGet(detailOverride: ClassDetail = detail) {
    (axios.get as ReturnType<typeof vi.fn>).mockImplementation(
        (url: string) => {
            if (url === '/api/classes/c1') {
                return Promise.resolve({ data: detailOverride });
            }

            if (
                url === '/api/trainings' ||
                url === '/api/tags' ||
                url === '/api/attachments'
            ) {
                return Promise.resolve({ data: [] });
            }

            if (url === '/api/users') {
                return Promise.resolve({
                    data: [
                        {
                            id: 'u1',
                            f_name: 'Dana',
                            l_name: 'Reed',
                            email: 'dana.reed@demo.local',
                        },
                        {
                            id: 'u2',
                            f_name: 'Sam',
                            l_name: 'Lee',
                            email: 'sam.lee@demo.local',
                        },
                    ],
                });
            }

            return Promise.reject(new Error(`unexpected GET ${url}`));
        },
    );
}

async function mountShow() {
    const wrapper = mount(Show, { props: { classId: 'c1' } });
    await flushPromises();

    return wrapper;
}

describe('classes/Show inline edit', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        vi.clearAllMocks();
        mockGet();
    });

    it('renders the editable details form prefilled, Save disabled until dirty', async () => {
        const wrapper = await mountShow();

        const name = wrapper.find<HTMLInputElement>('#edit_name');
        expect(name.exists()).toBe(true);
        expect(name.element.value).toBe('Fall Protection');

        const save = wrapper
            .findAll('button')
            .find((b) => b.text() === 'Save changes')!;
        expect(save.attributes('disabled')).toBeDefined();
    });

    it('enables Save when a field changes and PATCHes the payload', async () => {
        (axios.patch as ReturnType<typeof vi.fn>).mockResolvedValue({
            data: { ...detail, name: 'Renamed' },
        });
        const wrapper = await mountShow();

        await wrapper.find('#edit_name').setValue('Renamed');
        const save = wrapper
            .findAll('button')
            .find((b) => b.text() === 'Save changes')!;
        expect(save.attributes('disabled')).toBeUndefined();

        await wrapper.find('form').trigger('submit');
        await flushPromises();

        expect(axios.patch).toHaveBeenCalledWith(
            '/api/classes/c1',
            expect.objectContaining({ name: 'Renamed' }),
            expect.anything(),
        );
    });

    it('shows the sign-in sheet button but gates certificates/summary to completed', async () => {
        const wrapper = await mountShow();
        const labels = wrapper.findAll('button').map((b) => b.text());

        expect(labels).toContain('Sign-in sheet');
        // Scheduled class → no certificate / summary docs yet.
        expect(labels).not.toContain('Certificates');
        expect(labels).not.toContain('Class summary');
    });

    it('mounts AttachmentsList wired to this class as the morphable', async () => {
        const wrapper = await mountShow();

        const attachments = wrapper.findComponent(AttachmentsList);
        expect(attachments.exists()).toBe(true);
        expect(attachments.props('morphableType')).toBe(
            'App\\Models\\TrainingClass',
        );
        expect(attachments.props('morphableId')).toBe('c1');
    });

    it('lists the enrolled student name in the right-hand roster column', async () => {
        const wrapper = await mountShow();

        // Compact list shows the sortable name; email/picker lives in the modal.
        expect(wrapper.text()).toContain('Reed, Dana');
    });

    it('shows compact topic names with hours inside the details card', async () => {
        // Add a topic to the detail used by the mock.
        detail.trainings = [
            {
                id: 'ct1',
                training_id: 't1',
                training_name: 'Fall Protection',
                initial_only: false,
                repeating: true,
                as_needed: false,
                std_freq_name: 'Annual',
                repeat_days: 365,
                hours: '4.00',
                cert_title: null,
                cert_text: null,
                cert_code: null,
                credits: [],
            },
        ];
        const wrapper = await mountShow();
        expect(wrapper.text()).toContain('Fall Protection');
        expect(wrapper.text()).toContain('(4h)');
        detail.trainings = []; // restore for other tests
    });
});

// Reference student counts — shown, never enforced.
describe('classes/Show — student counts', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        vi.clearAllMocks();
    });

    it('shows "· max N" on the roster header when a max is set', async () => {
        mockGet({ ...detail, max_students: 20 });
        const wrapper = await mountShow();

        expect(wrapper.text()).toContain('Enrolled (1 · max 20)');
    });

    it('keeps the plain enrolled count when no max is set', async () => {
        mockGet();
        const wrapper = await mountShow();

        expect(wrapper.text()).toContain('Enrolled (1)');
        expect(wrapper.text()).not.toContain('· max');
    });

    it('lists the counts on the completed read-only view', async () => {
        mockGet({
            ...detail,
            status: 'completed',
            can_edit: false,
            min_students: 5,
            max_students: 20,
        });
        const wrapper = await mountShow();

        expect(wrapper.text()).toContain('Students (min / max)');
        expect(wrapper.text()).toContain('5 / 20');
    });
});

// Actions dropdown — duplicate class (available on scheduled AND completed
// classes; copying a finished class to run it again is the main use case).
describe('classes/Show — duplicate class', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        vi.clearAllMocks();
        mockGet();
    });

    it('shows the Actions dropdown trigger on a scheduled class', async () => {
        const wrapper = await mountShow();
        expect(
            wrapper.find('[data-testid="class-actions-trigger"]').exists(),
        ).toBe(true);
    });

    it('shows the Actions dropdown trigger on a completed class too', async () => {
        mockGet({ ...detail, status: 'completed', can_edit: false });
        const wrapper = await mountShow();
        expect(
            wrapper.find('[data-testid="class-actions-trigger"]').exists(),
        ).toBe(true);
    });

    it('openDuplicate seeds the class form modal from this class', async () => {
        const wrapper = await mountShow();

        (
            wrapper.vm as unknown as { openDuplicate: () => void }
        ).openDuplicate();
        await flushPromises();

        const modal = wrapper.findComponent({ name: 'ClassFormModal' });
        expect(modal.exists()).toBe(true);
        expect(modal.props('open')).toBe(true);
        expect((modal.props('copyFrom') as ClassDetail | null)?.id).toBe('c1');
    });

    it('navigates to the new class once the duplicate is saved', async () => {
        const wrapper = await mountShow();

        (
            wrapper.vm as unknown as { openDuplicate: () => void }
        ).openDuplicate();
        await flushPromises();

        const modal = wrapper.findComponent({ name: 'ClassFormModal' });
        modal.vm.$emit('saved', { ...detail, id: 'c2' });
        await flushPromises();

        expect(routerVisit).toHaveBeenCalledWith({ url: '/classes/c2' });
    });
});

// M3 — completed-class view: per-training credit lists + per-topic roster.
const completedDetail: ClassDetail = {
    ...detail,
    status: 'completed',
    completion_date: '2026-06-01',
    can_edit: false,
    trainings: [
        {
            id: 'ct1',
            training_id: 't1',
            training_name: 'Fall Protection Basics',
            initial_only: false,
            repeating: true,
            as_needed: false,
            std_freq_name: 'Annual',
            repeat_days: 365,
            hours: '4.00',
            cert_title: null,
            cert_text: null,
            cert_code: null,
            credits: [
                {
                    completion_id: 'cp1',
                    user_id: 'u1',
                    user_name: 'Dana Reed',
                    cert_id: 'CERT20260601-001',
                    expire_date: '2027-06-01',
                    hours: 4,
                },
            ],
        },
        {
            id: 'ct2',
            training_id: 't2',
            training_name: 'Harness Inspection',
            initial_only: true,
            repeating: false,
            as_needed: false,
            std_freq_name: null,
            repeat_days: null,
            hours: '2.00',
            cert_title: null,
            cert_text: null,
            cert_code: null,
            credits: [],
        },
    ],
    enrollments: [
        {
            id: 'e1',
            user_id: 'u1',
            user_name: 'Dana Reed',
            user_sort_name: 'Reed, Dana',
            user_email: 'dana.reed@demo.local',
            status: 'partial',
            notes: null,
            credited_training_ids: ['ct1'],
            results: { ct1: 'pass', ct2: 'fail' },
        },
        {
            id: 'e2',
            user_id: 'u2',
            user_name: 'Sam Lee',
            user_sort_name: 'Lee, Sam',
            user_email: 'sam.lee@demo.local',
            status: 'incomplete',
            notes: null,
            credited_training_ids: [],
            results: { ct1: 'fail', ct2: 'incomplete' },
        },
    ],
};

describe('classes/Show — completed class (M3)', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        vi.clearAllMocks();
        mockGet(completedDetail);
    });

    it('lists who earned each training credit, with cert and expiry', async () => {
        const wrapper = await mountShow();

        const credits = wrapper.find('[data-testid="credits-awarded"]');
        expect(credits.exists()).toBe(true);
        expect(credits.text()).toContain('Fall Protection Basics');
        expect(credits.text()).toContain('Dana Reed');
        expect(credits.text()).toContain('CERT20260601-001');
        expect(credits.text()).toContain('2027-06-01');
        // A topic nobody passed says so instead of rendering an empty list.
        expect(credits.text()).toContain('Harness Inspection');
        expect(credits.text()).toContain('No credit issued');
    });

    it('moves the roster below the credits and details per-topic status', async () => {
        const wrapper = await mountShow();

        const roster = wrapper.find('[data-testid="enrollee-roster"]');
        expect(roster.exists()).toBe(true);

        // Roster renders after the credit lists.
        const html = wrapper.html();
        expect(html.indexOf('data-testid="credits-awarded"')).toBeLessThan(
            html.indexOf('data-testid="enrollee-roster"'),
        );

        // Per-student per-topic detail, not just a roll-up badge.
        const rows = roster.findAll('[data-testid="roster-row"]');
        expect(rows).toHaveLength(2);
        const dana = rows[0].text();
        expect(dana).toContain('Reed, Dana');
        expect(dana).toContain('✓ Fall Protection Basics');
        expect(dana).toContain('✗ Harness Inspection');
        const sam = rows[1].text();
        expect(sam).toContain('✗ Fall Protection Basics');
        // Incomplete renders as a neutral dash, distinct from fail.
        expect(sam).toContain('— Harness Inspection');
    });

    it('hides the re-issue control on a completed (locked) class', async () => {
        const wrapper = await mountShow();
        const labels = wrapper.findAll('button').map((b) => b.text());
        expect(labels).not.toContain('Re-issue certificates');
    });

    it.each(['Certificates', 'Class summary', 'Sign-in sheet'])(
        'clicking %s opens the doc in the in-app viewer (with save-to-files)',
        async (label) => {
            // Clear any dialog teleported by a previous iteration.
            document.body.innerHTML = '';
            const wrapper = await mountShow();

            // No viewer until the document button is used.
            expect(
                document.body.querySelector(
                    '[data-testid="viewer-save-to-files"]',
                ),
            ).toBeNull();

            const btn = wrapper
                .findAll('button')
                .find((b) => b.text() === label);
            expect(btn).toBeTruthy();
            await btn!.trigger('click');
            await flushPromises();

            // The generated-doc viewer is open (preview + save-to-files).
            expect(
                document.body.querySelector(
                    '[data-testid="viewer-save-to-files"]',
                ),
            ).not.toBeNull();
            wrapper.unmount();
        },
    );
});

// Re-issue certificates — deliberate renumbering on a re-opened (scheduled)
// class that was previously completed (its topics still hold issued credit).
const reopenedDetail: ClassDetail = {
    ...completedDetail,
    status: 'scheduled',
    can_edit: true,
};

describe('classes/Show — re-issue certificates', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        vi.clearAllMocks();
        mockGet(reopenedDetail);
    });

    it('shows the re-issue control once the class has issued credit', async () => {
        const wrapper = await mountShow();
        const labels = wrapper.findAll('button').map((b) => b.text());
        expect(labels).toContain('Re-issue certificates');
    });

    it('confirming posts the whole-class re-issue and closes the dialog', async () => {
        (axios.post as ReturnType<typeof vi.fn>).mockResolvedValue({
            data: reopenedDetail,
        });
        const wrapper = await mountShow();

        const open = wrapper
            .findAll('button')
            .find((b) => b.text() === 'Re-issue certificates')!;
        await open.trigger('click');
        await flushPromises();

        const confirm = document.body.querySelector<HTMLButtonElement>(
            '[data-testid="reissue-confirm"]',
        );
        expect(confirm).not.toBeNull();
        confirm!.click();
        await flushPromises();

        expect(axios.post).toHaveBeenCalledWith(
            '/api/classes/c1/reissue-certificates',
            {},
            expect.anything(),
        );
    });

    it('hides the re-issue control when no credit was ever issued', async () => {
        mockGet({ ...reopenedDetail, trainings: [] });
        const wrapper = await mountShow();
        const labels = wrapper.findAll('button').map((b) => b.text());
        expect(labels).not.toContain('Re-issue certificates');
    });
});

// Single-cert corrections (Pass 2) — revoke an awarded credit + issue a missed
// person, only on a re-opened (previously completed) class.
describe('classes/Show — revoke a single certificate', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        vi.clearAllMocks();
        mockGet(reopenedDetail);
    });

    it('shows the credits with a Revoke action and the name-spelling hint', async () => {
        const wrapper = await mountShow();

        const credits = wrapper.find('[data-testid="credits-awarded"]');
        expect(credits.exists()).toBe(true);
        expect(credits.text()).toContain('Dana Reed');
        expect(credits.text()).toContain('Wrong spelling?');
        expect(wrapper.find('[data-testid="revoke-cert"]').exists()).toBe(true);
    });

    it('confirming a revoke posts to the completion revoke endpoint', async () => {
        (axios.post as ReturnType<typeof vi.fn>).mockResolvedValue({
            data: reopenedDetail,
        });
        const wrapper = await mountShow();

        await wrapper.find('[data-testid="revoke-cert"]').trigger('click');
        await flushPromises();

        const dialog = document.body.querySelector(
            '[data-testid="revoke-dialog"]',
        );
        expect(dialog).not.toBeNull();

        const reason = document.body.querySelector<HTMLTextAreaElement>(
            '[data-testid="revoke-reason"]',
        )!;
        reason.value = 'Attended the wrong session';
        reason.dispatchEvent(new Event('input', { bubbles: true }));
        await flushPromises();

        document.body
            .querySelector<HTMLButtonElement>('[data-testid="revoke-confirm"]')!
            .click();
        await flushPromises();

        // completion cp1 (Dana's Fall Protection Basics credit) is revoked.
        expect(axios.post).toHaveBeenCalledWith(
            '/api/classes/c1/completions/cp1/revoke',
            { reason: 'Attended the wrong session' },
            expect.anything(),
        );
    });

    it('hides Revoke on a completed (locked) class', async () => {
        mockGet(completedDetail);
        const wrapper = await mountShow();
        expect(wrapper.find('[data-testid="revoke-cert"]').exists()).toBe(
            false,
        );
    });
});

describe('classes/Show — issue a single certificate', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        vi.clearAllMocks();
        mockGet(reopenedDetail);
    });

    it('offers Issue certificate on a re-opened class and opens the modal', async () => {
        const wrapper = await mountShow();

        const open = wrapper
            .findAll('button')
            .find((b) => b.text() === 'Issue certificate');
        expect(open).toBeTruthy();

        await open!.trigger('click');
        await flushPromises();

        expect(
            document.body.querySelector('[data-testid="issue-dialog"]'),
        ).not.toBeNull();
        // The confirm is disabled until a person is chosen.
        expect(
            document.body
                .querySelector('[data-testid="issue-confirm"]')
                ?.getAttribute('disabled'),
        ).not.toBeNull();
    });

    it('hides Issue certificate on a never-completed scheduled class', async () => {
        mockGet({ ...reopenedDetail, completion_date: null });
        const wrapper = await mountShow();
        const labels = wrapper.findAll('button').map((b) => b.text());
        expect(labels).not.toContain('Issue certificate');
    });
});

// Re-close (keep as-is) — the lightweight re-lock, offered on the same
// re-opened + previously-completed condition as the single-cert controls
// (canManageCerts), and gone once the class is completed again.
describe('classes/Show — re-close (keep as-is)', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        vi.clearAllMocks();
    });

    it('shows the re-close button on a re-opened, previously-completed class', async () => {
        mockGet(reopenedDetail);
        const wrapper = await mountShow();
        const labels = wrapper.findAll('button').map((b) => b.text());
        expect(labels).toContain('Re-close (keep as-is)');
    });

    it('hides the re-close button on a never-completed scheduled class', async () => {
        mockGet({ ...reopenedDetail, completion_date: null });
        const wrapper = await mountShow();
        const labels = wrapper.findAll('button').map((b) => b.text());
        expect(labels).not.toContain('Re-close (keep as-is)');
    });

    it('hides the re-close button on a completed (locked) class', async () => {
        mockGet(completedDetail);
        const wrapper = await mountShow();
        const labels = wrapper.findAll('button').map((b) => b.text());
        expect(labels).not.toContain('Re-close (keep as-is)');
    });

    it('clicking Re-close posts to the reclose endpoint and does not open the marking modal', async () => {
        mockGet(reopenedDetail);
        (axios.post as ReturnType<typeof vi.fn>).mockResolvedValue({
            data: { ...reopenedDetail, status: 'completed' },
        });
        const wrapper = await mountShow();

        const btn = wrapper.find('[data-testid="reclose-btn"]');
        expect(btn.exists()).toBe(true);
        await btn.trigger('click');
        await flushPromises();

        expect(axios.post).toHaveBeenCalledWith(
            '/api/classes/c1/reclose',
            {},
            expect.anything(),
        );
        // The heavyweight marking grid (ClassCompleteModal) never opened.
        expect(document.body.textContent).not.toContain(
            'Mark each attendee Pass, Fail, or Incomplete',
        );
    });
});
