import { flushPromises, mount } from '@vue/test-utils';
import axios from 'axios';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import AttachmentsList from '@/components/AttachmentsList.vue';
import Show from '@/pages/classes/Show.vue';
import type { ClassDetail } from '@/stores/classes';

vi.mock('axios');
vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<div><slot /></div>' },
    Link: { template: '<a :href="href"><slot /></a>', props: ['href'] },
    usePage: () => ({ props: { auth: { user: { org_id: 'org1' } } } }),
}));
vi.mock('@/routes/classes', () => ({ page: () => ({ url: '/classes' }) }));

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

        // Compact list shows the name; the email/picker lives in the modal.
        expect(wrapper.text()).toContain('Dana Reed');
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
                cert_title: null, cert_text: null, cert_code: null, credits: [],
            },
        ];
        const wrapper = await mountShow();
        expect(wrapper.text()).toContain('Fall Protection');
        expect(wrapper.text()).toContain('(4h)');
        detail.trainings = []; // restore for other tests
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
            cert_title: null, cert_text: null, cert_code: null, credits: [
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
            cert_title: null, cert_text: null, cert_code: null, credits: [],
        },
    ],
    enrollments: [
        {
            id: 'e1',
            user_id: 'u1',
            user_name: 'Dana Reed',
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
        expect(dana).toContain('Dana Reed');
        expect(dana).toContain('✓ Fall Protection Basics');
        expect(dana).toContain('✗ Harness Inspection');
        const sam = rows[1].text();
        expect(sam).toContain('✗ Fall Protection Basics');
        // Incomplete renders as a neutral dash, distinct from fail.
        expect(sam).toContain('— Harness Inspection');
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
