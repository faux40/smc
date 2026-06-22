import { flushPromises, mount } from '@vue/test-utils';
import axios from 'axios';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import RequirementDetail from '@/pages/compliance/RequirementDetail.vue';

vi.mock('axios');
vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<div><slot /></div>' },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    usePage: () => ({ props: { auth: { user: { org_id: 'o1' } } } }),
}));
vi.mock('@/routes/users', () => ({ show: (id: string) => ({ url: `/users/${id}` }) }));

const META = { current_page: 1, last_page: 1, per_page: 25, total: 2 };
const USERS = '/api/compliance/by-requirement/r1/users';

function stubAxios() {
    (axios.get as ReturnType<typeof vi.fn>).mockImplementation((url: string) => {
        if (url === USERS) {
            return Promise.resolve({
                data: {
                    data: [
                        { user_id: 'u1', name: 'Lee, Sam', status: 'overdue', training_id: 't1', training: 'First Aid', expires_at: null, last_completed_at: null, employee_number: null, department: null, location: null, tag_ids: [] },
                        { user_id: 'u1', name: 'Lee, Sam', status: 'current', training_id: 't2', training: 'Lockout/Tagout', expires_at: '2027-01-01', last_completed_at: '2026-01-01', employee_number: null, department: null, location: null, tag_ids: [] },
                    ],
                    meta: META,
                },
            });
        }
        if (url === '/api/tags') return Promise.resolve({ data: [] });
        return Promise.resolve({ data: [] });
    });
}

describe('compliance/RequirementDetail', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        vi.clearAllMocks();
        stubAxios();
    });

    it('shows a Training column so per-training rows are self-explanatory', async () => {
        const wrapper = mount(RequirementDetail, {
            props: { requirement: { id: 'r1', name: 'OSHA General' }, counts: { overdue: 1, current: 1, total: 2 } },
        });
        await flushPromises();

        expect(wrapper.text()).toContain('OSHA General');
        const headers = wrapper.findAll('thead th').map((th) => th.text());
        expect(headers.some((h) => h.includes('Training'))).toBe(true);

        const body = wrapper.find('tbody').text();
        expect(body).toContain('First Aid');
        expect(body).toContain('Lockout/Tagout');
    });
});
