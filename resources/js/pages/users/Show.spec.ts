import { flushPromises, mount } from '@vue/test-utils';
import axios from 'axios';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import RequirementAssignmentChip from '@/components/RequirementAssignmentChip.vue';
import UsersShow from '@/pages/users/Show.vue';
import { useRequirementAssignmentsStore } from '@/stores/requirementAssignments';

vi.mock('axios');
vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<div />' },
    usePage: () => ({ props: { auth: { user: { id: 'me', isAdmin: true } } } }),
}));
vi.mock('@/routes/users', () => ({ index: () => '/users' }));
vi.mock('@/echo', () => ({ realtimeTabId: () => 'test-tab' }));
vi.mock('@/composables/useRealtime', () => ({
    useRealtime: vi.fn(() => ({ bind: vi.fn(), leave: vi.fn() })),
}));

const subject = {
    id: 'u1',
    name: 'Pat Lee',
    f_name: 'Pat',
    m_name: null,
    l_name: 'Lee',
    prefix_name: null,
    suffix_name: null,
    email: 'pat@example.com',
    status: 'active' as const,
    role: 'Member',
    department: null,
    location: null,
    job_title: null,
    supervisor_name: null,
    start_date: null,
    end_date: null,
};

const emptyCompliance = {
    groups: { overdue: [], due_soon: [], current: [], not_started: [], as_needed: [] },
    completions: [],
};

const richCompliance = {
    groups: {
        overdue: [
            {
                id: 'ta-1',
                training_id: 't1',
                training_name: 'Fall Protection',
                status: 'overdue',
                expires_at: '2026-06-01',
                last_completed_at: '2025-06-01',
                days_until_due: -9,
                sources: [
                    { type: 'requirement', id: 'r1', name: 'OSHA General' },
                ],
            },
        ],
        due_soon: [],
        current: [
            {
                id: 'ta-2',
                training_id: 't2',
                training_name: 'First Aid',
                status: 'current',
                expires_at: '2027-01-01',
                last_completed_at: '2026-01-01',
                days_until_due: 200,
                sources: [{ type: 'direct', id: null, name: null }],
            },
        ],
        not_started: [],
        as_needed: [],
    },
    completions: [
        {
            id: 'c1',
            module_type: 'App\\Models\\Training',
            module_id: 't1',
            training_name: 'Fall Protection',
            completion_date: '2025-06-01',
            certification_date: null,
            expire_date: '2026-06-01',
            cert_ident: 'CERT-1',
            class_training_id: 'ct1',
            class_id: 'cl1',
            class_name: 'June Safety Day',
            notes: null,
            rqmt_element_ids: [],
        },
    ],
};

const STUBS = {
    TagsField: true,
    TrainingAssignmentPill: true,
    TrainingAssignmentPillLegend: true,
    TrainingAssignmentFormModal: true,
    ComplianceStatusBadge: true,
    Heading: true,
};

async function mountShow(
    mockTas: unknown[] = [],
    compliance: unknown = emptyCompliance,
) {
    (axios.get as ReturnType<typeof vi.fn>).mockImplementation((url: string) => {
        if (url.includes('/training-compliance'))
            return Promise.resolve({ data: compliance });
        if (url === '/api/training-assignments')
            return Promise.resolve({ data: mockTas });
        return Promise.resolve({ data: [] });
    });
    const wrapper = mount(UsersShow, {
        props: { subject, tagIds: [] },
        global: { stubs: STUBS },
    });
    await flushPromises();
    return wrapper;
}

describe('users/Show — requirement assignment chips', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        vi.clearAllMocks();
    });

    it('renders a chip for each requirement-sourced TA', async () => {
        const taWithReq = {
            id: 'ta-1',
            user_id: 'u1',
            training_id: 't1',
            name: 'Safety Training',
            expires_at: null,
            last_completed_at: null,
            can_delete: true,
            active_sources: [
                {
                    id: 'src-1',
                    sourceable_type: 'App\\Models\\Requirement',
                    sourceable_id: 'r1',
                    added_at: '2026-01-01T00:00:00.000Z',
                },
            ],
        };
        const wrapper = await mountShow([taWithReq]);
        expect(wrapper.findAllComponents(RequirementAssignmentChip)).toHaveLength(1);
    });

    it('renders no chips when TAs have only direct sources', async () => {
        const taDirect = {
            id: 'ta-1',
            user_id: 'u1',
            training_id: 't1',
            name: 'Safety Training',
            expires_at: null,
            last_completed_at: null,
            can_delete: true,
            active_sources: [
                {
                    id: 'src-1',
                    sourceable_type: null,
                    sourceable_id: null,
                    added_at: '2026-01-01T00:00:00.000Z',
                },
            ],
        };
        const wrapper = await mountShow([taDirect]);
        expect(wrapper.findAllComponents(RequirementAssignmentChip)).toHaveLength(0);
    });

    it('calls destroyByRequirement when a chip emits remove', async () => {
        const taWithReq = {
            id: 'ta-1',
            user_id: 'u1',
            training_id: 't1',
            name: 'Safety Training',
            expires_at: null,
            last_completed_at: null,
            can_delete: true,
            active_sources: [
                {
                    id: 'src-1',
                    sourceable_type: 'App\\Models\\Requirement',
                    sourceable_id: 'r1',
                    added_at: '2026-01-01T00:00:00.000Z',
                },
            ],
        };
        const wrapper = await mountShow([taWithReq]);
        const raStore = useRequirementAssignmentsStore();
        const spy = vi
            .spyOn(raStore, 'destroyByRequirement')
            .mockResolvedValue({ deleted_ids: [], updated_ids: [] });

        await wrapper.find('button[aria-label^="Remove"]').trigger('click');

        expect(spy).toHaveBeenCalledWith('u1', 'r1');
    });
});

describe('users/Show — status lists + completion history (J3 payload)', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        vi.clearAllMocks();
    });

    it('fetches the training-compliance endpoint', async () => {
        await mountShow([], richCompliance);

        expect(axios.get).toHaveBeenCalledWith(
            '/api/users/u1/training-compliance',
            expect.anything(),
        );
    });

    it('renders each training in its status list with dates and source chips', async () => {
        const wrapper = await mountShow([], richCompliance);

        const text = wrapper.text();
        expect(text).toContain('Fall Protection');
        expect(text).toContain('OSHA General');
        expect(text).toContain('2026-06-01 (9d overdue)');
        expect(text).toContain('First Aid');
        expect(text).toContain('Direct');
    });

    it('renders completion history with training name and class link', async () => {
        const wrapper = await mountShow([], richCompliance);

        const history = wrapper.find('[data-testid="completion-history"]');
        expect(history.exists()).toBe(true);
        expect(history.text()).toContain('Fall Protection');
        expect(history.text()).toContain('CERT-1');

        const classLink = history.find('a[href="/classes/cl1"]');
        expect(classLink.exists()).toBe(true);
        expect(classLink.text()).toContain('June Safety Day');
    });
});
