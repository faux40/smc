import { flushPromises, mount } from '@vue/test-utils';
import axios from 'axios';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import TagFilter from '@/components/TagFilter.vue';
import NotRequiredDetail from '@/pages/compliance/NotRequiredDetail.vue';

vi.mock('axios');
vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<div><slot /></div>' },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    usePage: () => ({ props: { auth: { user: { org_id: 'o1' } } } }),
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
});
