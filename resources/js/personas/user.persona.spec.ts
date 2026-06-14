import { flushPromises, mount } from '@vue/test-utils';
import axios from 'axios';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import Dashboard from '@/pages/Dashboard.vue';
import UsersShow from '@/pages/users/Show.vue';

/*
 * Persona: the typical user (SelfView/SelfEdit). Sees their own
 * compliance posture and history; the org-wide dashboard is not theirs.
 * Backend half: tests/Feature/Personas/TypicalUserPersonaTest.php
 * (php artisan test --group=persona-user).
 */

vi.mock('axios');
vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<div />' },
    Link: { template: '<a><slot /></a>' },
    usePage: () => ({
        // A plain user: no role flags at all.
        props: { auth: { user: { id: 'u-me' } } },
    }),
}));
vi.mock('@/routes', () => ({ dashboard: () => '/dashboard' }));
vi.mock('@/routes/users', () => ({
    index: () => '/users',
    show: (id: string) => ({ url: `/users/${id}` }),
}));
vi.mock('@/composables/useRealtime', () => ({
    useRealtime: vi.fn(() => ({ bind: vi.fn(), leave: vi.fn() })),
}));

const subject = {
    id: 'u-me',
    name: 'Sam Self',
    f_name: 'Sam',
    m_name: null,
    l_name: 'Self',
    prefix_name: null,
    suffix_name: null,
    email: 'sam@demo.local',
    status: 'active' as const,
    role: 'SelfView',
    department: null,
    location: null,
    job_title: null,
    employee_number: null,
    supervisor_id: null,
    supervisor_name: null,
    start_date: null,
    end_date: null,
    can_edit: false,
};

const myCompliance = {
    groups: {
        overdue: [],
        due_soon: [
            {
                id: 'ta-1',
                training_id: 't1',
                training_name: 'Forklift',
                status: 'due_soon',
                expires_at: '2026-07-02',
                last_completed_at: '2025-07-02',
                days_until_due: 20,
                sources: [{ type: 'direct', id: null, name: null }],
            },
        ],
        current: [],
        not_started: [],
        as_needed: [],
    },
    completions: [
        {
            id: 'c1',
            module_type: 'App\\Models\\Training',
            module_id: 't1',
            training_name: 'Forklift',
            completion_date: '2025-07-02',
            certification_date: null,
            expire_date: '2026-07-02',
            cert_ident: 'FORK-2025-7',
            hours: 8,
            class_training_id: null,
            class_id: null,
            class_name: null,
            notes: null,
            rqmt_element_ids: [],
        },
    ],
};

const emptyCompliance = {
    groups: {
        overdue: [],
        due_soon: [],
        current: [],
        not_started: [],
        as_needed: [],
    },
    completions: [],
};

const SHOW_STUBS = {
    TagsField: true,
    TrainingAssignmentPill: true,
    TrainingAssignmentPillLegend: true,
    TrainingAssignmentFormModal: true,
};

async function mountOwnPage(compliance: unknown) {
    (axios.get as ReturnType<typeof vi.fn>).mockImplementation(
        (url: string) => {
            if (url.includes('/training-compliance')) {
                return Promise.resolve({ data: compliance });
            }

            return Promise.resolve({ data: [] });
        },
    );
    const wrapper = mount(UsersShow, {
        props: { subject, tagIds: [] },
        global: { stubs: SHOW_STUBS },
    });
    await flushPromises();

    return wrapper;
}

describe('persona: typical user', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        vi.clearAllMocks();
    });

    it('is pointed from the dashboard to their own page — no org widgets', async () => {
        const wrapper = mount(Dashboard);
        await flushPromises();

        expect(wrapper.text()).toContain(
            'The org dashboard is for Manager-or-higher roles',
        );
        expect(wrapper.find('a[href="/users/u-me"]').exists()).toBe(true);

        // None of the org-wide widgets mounted, so nothing was fetched.
        expect(axios.get).not.toHaveBeenCalled();
    });

    it('sees their own trainings, statuses, and due dates', async () => {
        const wrapper = await mountOwnPage(myCompliance);

        expect(axios.get).toHaveBeenCalledWith(
            '/api/users/u-me/training-compliance',
            expect.anything(),
        );
        const text = wrapper.text();
        expect(text).toContain('Forklift');
        expect(text).toContain('2026-07-02');
    });

    it('sees their completion history with cert details', async () => {
        const wrapper = await mountOwnPage(myCompliance);

        const history = wrapper.find('[data-testid="completion-history"]');
        expect(history.exists()).toBe(true);
        expect(history.text()).toContain('Forklift');
        expect(history.text()).toContain('FORK-2025-7');
    });

    it('as a brand-new hire, gets a readable empty state, not a broken page', async () => {
        const wrapper = await mountOwnPage(emptyCompliance);

        // The page renders (header present) without a single compliance row.
        expect(wrapper.text()).toContain('Sam Self');
        expect(
            wrapper.findAll(
                '[data-testid="completion-history"] tr, [data-testid="completion-history"] li',
            ).length,
        ).toBe(0);
    });
});
