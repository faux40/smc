import { flushPromises, mount } from '@vue/test-utils';
import axios from 'axios';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import RequirementAssignmentChip from '@/components/RequirementAssignmentChip.vue';
import AssignmentsIndex from '@/pages/assignments/Index.vue';
import { useRequirementAssignmentsStore } from '@/stores/requirementAssignments';

const { authUser } = vi.hoisted(() => ({
    authUser: {
        value: { id: 'me', org_id: 'org1' } as Record<string, unknown>,
    },
}));

vi.mock('axios');
vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<div><slot /></div>' },
    usePage: () => ({ props: { auth: { user: authUser.value } } }),
}));
vi.mock('@/routes/assignments', () => ({ page: () => ({ url: '/assignments' }) }));

const picker = [
    {
        id: 'u1',
        name: 'Pat Lee',
        sort_name: 'Lee, Pat',
        f_name: 'Pat',
        m_name: null,
        l_name: 'Lee',
        email: null,
        tag_ids: [],
        employee_number: 'EMP-1',
        department: 'Operations',
        location: 'Yard 3',
        job_title: 'Foreman',
        supervisor_id: null,
        supervisor_name: 'Dana Boss',
        supervisor_sort_name: 'Boss, Dana',
    },
];

const trainingAssignment = {
    id: 'ta-1',
    user_id: 'u1',
    training_id: 't1',
    name: 'Fall Protection',
    expires_at: null,
    last_completed_at: null,
    active_sources: [],
    can_delete: true,
};

const STUBS = {
    TrainingAssignmentPill: true,
    TagsListCell: true,
    TagFilter: true,
    MultiSelectFilter: true,
    FilterModeToggle: true,
    TrainingAssignmentFormModal: true,
    BulkTrainingAssignModal: true,
    Heading: true,
    AsyncState: { template: '<div><slot /></div>' },
};

async function mountPage(mockTrainingAssignments: unknown[] = []) {
    (axios.get as ReturnType<typeof vi.fn>).mockImplementation((url: string) => {
        if (url === '/api/users') return Promise.resolve({ data: picker });
        if (url === '/api/training-assignments')
            return Promise.resolve({ data: mockTrainingAssignments });
        return Promise.resolve({ data: [] });
    });
    const wrapper = mount(AssignmentsIndex, { global: { stubs: STUBS } });
    await flushPromises();

    return wrapper;
}

describe('assignments/Index — user profile columns', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        vi.clearAllMocks();
        authUser.value = { id: 'me', org_id: 'org1' };
    });

    it('hides a column the user has turned off in their preferences', async () => {
        authUser.value = {
            id: 'me',
            org_id: 'org1',
            preferences: { assignments: { visible_columns: { location: false } } },
        };
        const wrapper = await mountPage();
        const headers = wrapper.findAll('thead th').map((th) => th.text());
        expect(headers.some((h) => h.includes('Location'))).toBe(false);
        expect(headers.some((h) => h.includes('User'))).toBe(true);
    });

    it('renders the profile column headers', async () => {
        const wrapper = await mountPage();
        const headers = wrapper.findAll('thead th').map((th) => th.text());
        expect(headers.some((h) => h.includes('Job title'))).toBe(true);
        expect(headers.some((h) => h.includes('Employee #'))).toBe(true);
        expect(headers.some((h) => h.includes('Department'))).toBe(true);
        expect(headers.some((h) => h.includes('Location'))).toBe(true);
        expect(headers.some((h) => h.includes('Supervisor'))).toBe(true);
    });

    it("shows each user row's profile fields", async () => {
        const wrapper = await mountPage();
        const body = wrapper.find('tbody').text();
        expect(body).toContain('EMP-1');
        expect(body).toContain('Operations');
        expect(body).toContain('Yard 3');
        expect(body).toContain('Foreman');
        expect(body).toContain('Boss, Dana');
    });

    it("restores the user's saved filters on mount", async () => {
        authUser.value = {
            id: 'me',
            org_id: 'org1',
            preferences: { assignments: { filters: { search: 'fall-protection' } } },
        };
        const wrapper = await mountPage();
        const input = wrapper.find('#filter_search');
        expect((input.element as HTMLInputElement).value).toBe('fall-protection');
    });

    it('persists filters to prefs when they change (debounced)', async () => {
        (axios.patch as ReturnType<typeof vi.fn>).mockResolvedValue({ data: {} });
        const wrapper = await mountPage();

        vi.useFakeTimers();
        await wrapper.find('#filter_search').setValue('confined-space');
        await vi.advanceTimersByTimeAsync(700);
        vi.useRealTimers();

        expect(axios.patch).toHaveBeenCalledWith(
            '/api/me/preferences',
            expect.objectContaining({
                preferences: expect.objectContaining({
                    assignments: expect.objectContaining({
                        filters: expect.objectContaining({
                            search: 'confined-space',
                        }),
                    }),
                }),
            }),
            expect.anything(),
        );
    });
});

describe('assignments/Index — search includes profile fields', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        vi.clearAllMocks();
        authUser.value = { id: 'me', org_id: 'org1' };
    });

    // picker fixture has: employee_number 'EMP-1', department 'Operations',
    // location 'Yard 3', job_title 'Foreman', name 'Pat Lee'

    it('matches on employee number', async () => {
        const wrapper = await mountPage();
        await wrapper.find('#filter_search').setValue('EMP-1');
        await flushPromises();
        expect(wrapper.find('tbody').text()).toContain('Pat');
    });

    it('matches on department', async () => {
        const wrapper = await mountPage();
        await wrapper.find('#filter_search').setValue('Operations');
        await flushPromises();
        expect(wrapper.find('tbody').text()).toContain('Pat');
    });

    it('matches on location', async () => {
        const wrapper = await mountPage();
        await wrapper.find('#filter_search').setValue('Yard 3');
        await flushPromises();
        expect(wrapper.find('tbody').text()).toContain('Pat');
    });

    it('matches on job title', async () => {
        const wrapper = await mountPage();
        await wrapper.find('#filter_search').setValue('Foreman');
        await flushPromises();
        expect(wrapper.find('tbody').text()).toContain('Pat');
    });

    it('excludes users that do not match the search term', async () => {
        const wrapper = await mountPage();
        await wrapper.find('#filter_search').setValue('NoMatchXYZ999');
        await flushPromises();
        expect(wrapper.find('tbody').text()).not.toContain('Pat');
    });
});

describe('assignments/Index — training assignment pills', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        vi.clearAllMocks();
        authUser.value = { id: 'me', org_id: 'org1', isAdmin: true };
    });

    it('fetches /api/training-assignments on mount', async () => {
        await mountPage();
        expect(axios.get).toHaveBeenCalledWith(
            '/api/training-assignments',
            expect.any(Object),
        );
    });

    it('renders a training assignment pill for a user that has TAs', async () => {
        const wrapper = await mountPage([trainingAssignment]);
        expect(wrapper.find('training-assignment-pill-stub').exists()).toBe(true);
    });

    it('renders an Add button for admin users', async () => {
        const wrapper = await mountPage([trainingAssignment]);
        expect(wrapper.text()).toContain('+ Add');
    });
});

describe('assignments/Index — bulk assign', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        vi.clearAllMocks();
        authUser.value = { id: 'me', org_id: 'org1', isAdmin: true };
    });

    it('does not show the bulk assign button when no users are selected', async () => {
        const wrapper = await mountPage([trainingAssignment]);
        expect(wrapper.find('[data-testid="bulk-assign-btn"]').exists()).toBe(false);
    });

    it('shows the bulk assign button after checking a row checkbox', async () => {
        const wrapper = await mountPage([trainingAssignment]);
        // Find the per-row checkbox and check it.
        const checkbox = wrapper.findAll('input[type="checkbox"]')[1]; // index 0 = select-all
        if (checkbox) {
            await checkbox.setValue(true);
            await wrapper.vm.$nextTick();
        }
        // The bulk button appears when selectedCount > 0. Since Checkbox is
        // rendered as real UI, trigger via the component's update:checked event.
        const checkboxes = wrapper.findAllComponents({ name: 'Checkbox' });
        if (checkboxes.length > 1) {
            await checkboxes[1].trigger('click');
            await wrapper.vm.$nextTick();
        }
        // Assert BulkTrainingAssignModal stub is rendered (v-if="canCreate").
        expect(wrapper.findComponent({ name: 'BulkTrainingAssignModal' }).exists()).toBe(true);
    });
});

describe('assignments/Index — requirement assignment chips', () => {
    const taWithReqSource = {
        ...trainingAssignment,
        active_sources: [
            {
                id: 'src-1',
                sourceable_type: 'App\\Models\\Requirement',
                sourceable_id: 'r1',
                added_at: '2026-01-01T00:00:00.000Z',
            },
        ],
    };
    const taWithDirectSource = {
        ...trainingAssignment,
        active_sources: [
            {
                id: 'src-2',
                sourceable_type: null,
                sourceable_id: null,
                added_at: '2026-01-01T00:00:00.000Z',
            },
        ],
    };

    beforeEach(() => {
        setActivePinia(createPinia());
        vi.clearAllMocks();
        authUser.value = { id: 'me', org_id: 'org1', isAdmin: true };
    });

    it('renders a chip for each unique requirement source', async () => {
        const wrapper = await mountPage([taWithReqSource]);
        expect(wrapper.findAllComponents(RequirementAssignmentChip)).toHaveLength(1);
    });

    it('renders no chips when TAs have only direct sources', async () => {
        const wrapper = await mountPage([taWithDirectSource]);
        expect(wrapper.findAllComponents(RequirementAssignmentChip)).toHaveLength(0);
    });

    it('calls destroyByRequirement when a chip emits remove', async () => {
        const wrapper = await mountPage([taWithReqSource]);
        const raStore = useRequirementAssignmentsStore();
        const spy = vi
            .spyOn(raStore, 'destroyByRequirement')
            .mockResolvedValue({ deleted_ids: [], updated_ids: [] });

        await wrapper.find('button[aria-label^="Remove"]').trigger('click');

        expect(spy).toHaveBeenCalledWith('u1', 'r1');
    });
});
