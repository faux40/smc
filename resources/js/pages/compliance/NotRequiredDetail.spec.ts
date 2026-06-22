import { flushPromises, mount } from '@vue/test-utils';
import axios from 'axios';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import TagFilter from '@/components/TagFilter.vue';
import { Checkbox } from '@/components/ui/checkbox';
import ClassActionsBar from '@/pages/classes/Partials/ClassActionsBar.vue';
import NotRequiredDetail from '@/pages/compliance/NotRequiredDetail.vue';

vi.mock('axios');
vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<div><slot /></div>' },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    router: { visit: vi.fn() },
    usePage: () => ({
        props: { auth: { user: { org_id: 'o1', isManager: true } } },
    }),
}));
vi.mock('@/routes/users', () => ({ show: (id: string) => ({ url: `/users/${id}` }) }));

const META = { current_page: 1, last_page: 1, per_page: 25, total: 1 };
const USERS = '/api/compliance/not-required/t1/users';

function stubAxios() {
    (axios.get as ReturnType<typeof vi.fn>).mockImplementation((url: string) => {
        if (url === USERS) {
            return Promise.resolve({
                data: {
                    data: [
                        { user_id: 'u1', name: 'Lee, Sam', status: 'overdue', expires_at: '2026-01-01', last_completed_at: '2025-01-01', employee_number: 'EMP-1', department: 'Ops', location: 'Yard', tag_ids: [] },
                    ],
                    meta: META,
                },
            });
        }
        if (url === '/api/tags') return Promise.resolve({ data: [] });
        return Promise.resolve({ data: [] });
    });
}

function params(): Array<Record<string, unknown>> {
    return (axios.get as ReturnType<typeof vi.fn>).mock.calls
        .filter((c) => c[0] === USERS)
        .map((c) => (c[1]?.params ?? {}) as Record<string, unknown>);
}

async function mountPage() {
    const wrapper = mount(NotRequiredDetail, {
        props: {
            training: { id: 't1', name: 'CPR' },
            counts: { current: 3, expired: 2, total: 5 },
        },
        global: { stubs: { ClassActionsBar: true } },
    });
    await flushPromises();

    return wrapper;
}

describe('compliance/NotRequiredDetail (via ComplianceDetail)', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        vi.clearAllMocks();
        stubAxios();
    });

    it('renders the title, two status chips with counts, and a user row', async () => {
        const wrapper = await mountPage();
        expect(wrapper.text()).toContain('CPR');
        expect(wrapper.find('[data-testid="status-chip-all"]').text()).toContain('5');
        expect(wrapper.find('[data-testid="status-chip-current"]').text()).toContain('3');
        expect(wrapper.find('[data-testid="status-chip-expired"]').text()).toContain('2');
        expect(wrapper.find('tbody').text()).toContain('Lee, Sam');
        expect(wrapper.find('tbody').text()).toContain('EMP-1');
        // Stored 'overdue' reads as "Expired" here (badge-status-map), matching
        // the "Taken but Expired" chip rather than saying "Overdue".
        expect(wrapper.find('tbody').text()).toContain('Expired');
        expect(wrapper.find('tbody').text()).not.toContain('Overdue');
    });

    it('filters by a status chip', async () => {
        const wrapper = await mountPage();
        await wrapper.find('[data-testid="status-chip-expired"]').trigger('click');
        await flushPromises();
        expect(params().at(-1)).toMatchObject({ status: 'expired' });
    });

    it('sends the search query and the tag filter', async () => {
        const wrapper = await mountPage();

        vi.useFakeTimers();
        await wrapper.find('#detail_q').setValue('sam');
        await vi.advanceTimersByTimeAsync(400);
        vi.useRealTimers();
        await flushPromises();
        expect(params().at(-1)).toMatchObject({ q: 'sam' });

        wrapper.findComponent(TagFilter).vm.$emit('update:tag-ids', ['t1']);
        await flushPromises();
        expect(params().at(-1)).toMatchObject({ tags: ['t1'], tags_mode: 'and' });
    });

    it('offers single-training class actions (add to existing + create)', async () => {
        const wrapper = await mountPage();
        const bar = wrapper.findComponent(ClassActionsBar);
        expect(bar.exists()).toBe(true);
        expect(bar.props('createTrainingIds')).toEqual(['t1']);
        expect(bar.props('addTrainingId')).toBe('t1');
    });

    it('selects every matching row beyond the visible page (#6)', async () => {
        // First load shows 1 of 3 matching; the bulk fetch returns all 3.
        let call = 0;
        (axios.get as ReturnType<typeof vi.fn>).mockImplementation((url: string) => {
            if (url === USERS) {
                call += 1;
                const n = call === 1 ? 1 : 3;
                const data = Array.from({ length: n }, (_, i) => ({
                    user_id: `u${i + 1}`, name: `User ${i + 1}`, status: 'current',
                    expires_at: null, last_completed_at: null, employee_number: null,
                    department: null, location: null, tag_ids: [],
                }));
                return Promise.resolve({ data: { data, meta: { ...META, total: 3 } } });
            }
            if (url === '/api/tags') return Promise.resolve({ data: [] });
            return Promise.resolve({ data: [] });
        });

        const wrapper = await mountPage();

        // Select the visible page (1 row) → banner offers "all 3 matching".
        wrapper.findAllComponents(Checkbox)[0].vm.$emit('update:modelValue', true);
        await flushPromises();
        expect(wrapper.find('[data-testid="selection-bar"]').text()).toContain('1 selected');

        await wrapper.find('[data-testid="select-all-matching"]').trigger('click');
        await flushPromises();

        expect(wrapper.find('[data-testid="selection-bar"]').text()).toContain('3 selected');
        expect(wrapper.findComponent(ClassActionsBar).props('selectedUserIds')).toHaveLength(3);
    });
});
