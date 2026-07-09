import { flushPromises, mount } from '@vue/test-utils';
import axios from 'axios';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { Checkbox } from '@/components/ui/checkbox';
import AddToClassModal from '@/pages/classes/Partials/AddToClassModal.vue';
import ClassActionsBar from '@/pages/classes/Partials/ClassActionsBar.vue';
import RequirementDetail from '@/pages/compliance/RequirementDetail.vue';

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

async function mountPage() {
    const wrapper = mount(RequirementDetail, {
        props: { requirement: { id: 'r1', name: 'OSHA General' }, counts: { overdue: 1, current: 1, total: 2 } },
        global: { stubs: { ClassActionsBar: true, AddToClassModal: true } },
        attachTo: document.body,
    });
    await flushPromises();

    return wrapper;
}

describe('compliance/RequirementDetail', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        vi.clearAllMocks();
        document.body.innerHTML = '';
        stubAxios();
    });

    it('shows a Training column so per-training rows are self-explanatory', async () => {
        const wrapper = await mountPage();
        const headers = wrapper.findAll('thead th').map((th) => th.text());
        expect(headers.some((h) => h.includes('Training'))).toBe(true);
        const body = wrapper.find('tbody').text();
        expect(body).toContain('First Aid');
        expect(body).toContain('Lockout/Tagout');
    });

    it('passes the distinct selected trainings to the class-actions bar', async () => {
        const wrapper = await mountPage();

        // Header select-all checkbox picks both rows (same user, two trainings).
        wrapper.findAllComponents(Checkbox)[0].vm.$emit('update:modelValue', true);
        await flushPromises();

        const bar = wrapper.findComponent(ClassActionsBar);
        expect(bar.props('selectedUserIds')).toEqual(['u1']);
        expect(bar.props('createTrainingIds')).toEqual(['t1', 't2']);
        // No bulk "add to existing" for a multi-training requirement.
        expect(bar.props('addTrainingId')).toBeUndefined();
    });

    it('opens the add-to-class picker for a single row with that row\'s training', async () => {
        const wrapper = await mountPage();

        await wrapper.find('[data-testid="row-add-to-class-u1-t1"]').trigger('click');
        await flushPromises();

        const modal = wrapper.findComponent(AddToClassModal);
        expect(modal.props('open')).toBe(true);
        expect(modal.props('trainingId')).toBe('t1');
        expect(modal.props('trainingName')).toBe('First Aid');
        expect(modal.props('userIds')).toEqual(['u1']);
    });

    it('exports a compliance-status snapshot scoped to this requirement', async () => {
        const wrapper = await mountPage();

        await wrapper.find('[data-testid="open-requirement-export"]').trigger('click');
        await flushPromises();

        const pdfHref = document.body
            .querySelector('[data-testid="export-completion-report"]')!
            .getAttribute('href')!;
        const csvHref = document.body
            .querySelector('[data-testid="export-completion-report-csv"]')!
            .getAttribute('href')!;

        expect(pdfHref).toContain('/api/reports/compliance-status/export?');
        expect(pdfHref).toContain('requirement_id=r1');
        expect(csvHref).toBe(`${pdfHref}&format=csv`);
    });
});
