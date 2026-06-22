import { flushPromises, mount } from '@vue/test-utils';
import axios from 'axios';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import MultiSelectFilter from '@/components/MultiSelectFilter.vue';
import RequirementAssignmentChip from '@/components/RequirementAssignmentChip.vue';
import TagFilter from '@/components/TagFilter.vue';
import { Checkbox } from '@/components/ui/checkbox';
import AssignmentsIndex from '@/pages/assignments/Index.vue';
import { useRequirementAssignmentsStore } from '@/stores/requirementAssignments';

const { authUser } = vi.hoisted(() => ({
    authUser: {
        value: { id: 'me', org_id: 'org1', isAdmin: true } as Record<string, unknown>,
    },
}));

vi.mock('axios');
vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<div><slot /></div>' },
    usePage: () => ({ props: { auth: { user: authUser.value } } }),
}));
vi.mock('@/routes/assignments', () => ({ page: () => ({ url: '/assignments' }) }));

const META = { current_page: 1, last_page: 1, per_page: 25, total: 1 };

function userRow(overrides: Record<string, unknown> = {}) {
    return {
        user_id: 'u1',
        name: 'Lee, Pat',
        email: 'pat@x.com',
        employee_number: 'EMP-1',
        job_title: 'Foreman',
        department: 'Operations',
        location: 'Yard 3',
        supervisor_name: 'Boss, Dana',
        tag_ids: [],
        assignments_count: 1,
        assignments: [
            {
                id: 'ta-1',
                user_id: 'u1',
                training_id: 't1',
                name: 'Fall Protection',
                expires_at: null,
                last_completed_at: null,
                active_sources: [],
                can_delete: true,
            },
        ],
        ...overrides,
    };
}

const BY_USER = '/api/training-assignments/by-user';

function stubAxios(rows = [userRow()]) {
    (axios.get as ReturnType<typeof vi.fn>).mockImplementation((url: string) => {
        if (url === BY_USER) return Promise.resolve({ data: { data: rows, meta: { ...META, total: rows.length } } });
        if (url === '/api/requirements') return Promise.resolve({ data: [{ id: 'r1', name: 'OSHA General' }] });
        if (url === '/api/tags') return Promise.resolve({ data: [] });
        return Promise.resolve({ data: [] });
    });
}

function listParams(): Array<Record<string, unknown>> {
    return (axios.get as ReturnType<typeof vi.fn>).mock.calls
        .filter((c) => c[0] === BY_USER)
        .map((c) => (c[1]?.params ?? {}) as Record<string, unknown>);
}

const STUBS = {
    TrainingAssignmentPill: true,
    TagsListCell: true,
    TrainingAssignmentFormModal: true,
    BulkTrainingAssignModal: true,
    TrainingAssignmentPillLegend: true,
};

async function mountIndex() {
    const wrapper = mount(AssignmentsIndex, { global: { stubs: STUBS } });
    await flushPromises();

    return wrapper;
}

describe('assignments/Index — server-paged by user', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        vi.clearAllMocks();
        stubAxios();
    });

    it('fetches the by-user page on mount, sorted by name', async () => {
        await mountIndex();
        expect(listParams()[0]).toMatchObject({ sort: 'name', dir: 'asc' });
    });

    it('renders a user row with profile fields + supervisor', async () => {
        const wrapper = await mountIndex();
        const text = wrapper.find('tbody').text();
        expect(text).toContain('Lee, Pat');
        expect(text).toContain('EMP-1');
        expect(text).toContain('Operations');
        expect(text).toContain('Boss, Dana');
    });

    it('renders the profile column headers', async () => {
        const wrapper = await mountIndex();
        const headers = wrapper.findAll('thead th').map((th) => th.text());
        for (const h of ['Employee #', 'Job title', 'Department', 'Location', 'Supervisor', 'Net Assignments']) {
            expect(headers.some((x) => x.includes(h))).toBe(true);
        }
    });

    it('search box filters on training name only (q)', async () => {
        const wrapper = await mountIndex();
        vi.useFakeTimers();
        await wrapper.find('#filter_search').setValue('fall');
        await vi.advanceTimersByTimeAsync(400);
        vi.useRealTimers();
        await flushPromises();
        expect(listParams().at(-1)).toMatchObject({ q: 'fall' });
        expect(listParams().at(-1)).not.toHaveProperty('user_q');
    });

    it('user filter sends user_q', async () => {
        const wrapper = await mountIndex();
        vi.useFakeTimers();
        await wrapper.find('#filter_user').setValue('lee');
        await vi.advanceTimersByTimeAsync(400);
        vi.useRealTimers();
        await flushPromises();
        expect(listParams().at(-1)).toMatchObject({ user_q: 'lee' });
    });

    it('requirement filter sends requirements + req_mode', async () => {
        const wrapper = await mountIndex();
        wrapper.findComponent(MultiSelectFilter).vm.$emit('update:selected', ['r1']);
        await flushPromises();
        expect(listParams().at(-1)).toMatchObject({ requirements: ['r1'], req_mode: 'or' });
    });

    it('tag filter sends tags + tags_mode', async () => {
        const wrapper = await mountIndex();
        wrapper.findComponent(TagFilter).vm.$emit('update:tag-ids', ['tag1']);
        await flushPromises();
        expect(listParams().at(-1)).toMatchObject({ tags: ['tag1'], tags_mode: 'and' });
    });

    it('re-fetches with the server sort key when a header is clicked', async () => {
        const wrapper = await mountIndex();
        const btn = wrapper.findAll('thead button').find((b) => b.text().includes('Net Assignments'));
        await btn!.trigger('click');
        await flushPromises();
        expect(listParams().at(-1)).toMatchObject({ sort: 'assignments' });
    });

    it('shows the bulk-assign button only after a row is selected', async () => {
        const wrapper = await mountIndex();
        expect(wrapper.find('[data-testid="bulk-assign-btn"]').exists()).toBe(false);

        wrapper.findAllComponents(Checkbox)[0].vm.$emit('update:modelValue', true);
        await flushPromises();
        expect(wrapper.find('[data-testid="bulk-assign-btn"]').exists()).toBe(true);
    });

    it('renders a requirement chip and removes it via the store', async () => {
        stubAxios([
            userRow({
                assignments: [
                    {
                        id: 'ta-1', user_id: 'u1', training_id: 't1', name: 'Fall Protection',
                        expires_at: null, last_completed_at: null, can_delete: true,
                        active_sources: [
                            { id: 's1', sourceable_type: 'App\\Models\\Requirement', sourceable_id: 'r1', added_at: '2026-01-01' },
                        ],
                    },
                ],
            }),
        ]);
        const reqAssign = useRequirementAssignmentsStore();
        const spy = vi
            .spyOn(reqAssign, 'destroyByRequirement')
            .mockResolvedValue({ deleted_ids: [], updated_ids: [] } as never);

        const wrapper = await mountIndex();
        const chip = wrapper.findComponent(RequirementAssignmentChip);
        expect(chip.exists()).toBe(true);

        chip.vm.$emit('remove');
        await flushPromises();
        expect(spy).toHaveBeenCalledWith('u1', 'r1');
    });
});
