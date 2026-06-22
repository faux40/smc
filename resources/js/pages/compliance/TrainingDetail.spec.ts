import { flushPromises, mount } from '@vue/test-utils';
import axios from 'axios';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { Checkbox } from '@/components/ui/checkbox';
import ClassFormModal from '@/pages/classes/Partials/ClassFormModal.vue';
import TrainingDetail from '@/pages/compliance/TrainingDetail.vue';

const { routerVisit } = vi.hoisted(() => ({ routerVisit: vi.fn() }));

vi.mock('axios');
vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<div><slot /></div>' },
    Link: { template: '<a><slot /></a>' },
    router: { visit: routerVisit },
    usePage: () => ({
        props: { auth: { user: { org_id: 'o1', isAdmin: true } } },
    }),
}));
vi.mock('@/routes/classes', () => ({
    showPage: (id: string) => ({ url: `/classes/${id}` }),
}));
vi.mock('@/routes/users', () => ({
    show: (id: string) => ({ url: `/users/${id}` }),
}));

const META = { current_page: 1, last_page: 1, per_page: 25, total: 2 };

function usersResponse() {
    return {
        data: {
            data: [
                { user_id: 'u1', name: 'Adams, Amy', status: 'overdue', expires_at: '2026-01-01', last_completed_at: '2025-01-01' },
                { user_id: 'u2', name: 'Baker, Bob', status: 'current', expires_at: null, last_completed_at: '2026-02-01' },
            ],
            meta: META,
        },
    };
}

function stubAxios() {
    (axios.get as ReturnType<typeof vi.fn>).mockResolvedValue(usersResponse());
    (axios.post as ReturnType<typeof vi.fn>).mockResolvedValue({ data: { id: 'c1' } });
}

const USERS_URL = '/api/compliance/by-training/t1/users';

function getParams(): Array<Record<string, unknown>> {
    return (axios.get as ReturnType<typeof vi.fn>).mock.calls
        .filter((c) => c[0] === USERS_URL)
        .map((c) => (c[1]?.params ?? {}) as Record<string, unknown>);
}

async function mountDetail() {
    const wrapper = mount(TrainingDetail, {
        props: {
            training: { id: 't1', name: 'Fall Protection' },
            counts: { overdue: 2, due_soon: 1, not_started: 1, current: 1, as_needed: 0, total: 5 },
        },
        global: { stubs: { ClassFormModal: true } },
    });
    await flushPromises();

    return wrapper;
}

describe('compliance/TrainingDetail', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        vi.clearAllMocks();
        stubAxios();
    });

    it('renders the users and the status chips with counts', async () => {
        const wrapper = await mountDetail();

        expect(wrapper.text()).toContain('Fall Protection');
        expect(wrapper.text()).toContain('Adams, Amy');
        // "All" chip shows the total.
        expect(wrapper.find('[data-testid="status-chip-all"]').text()).toContain('5');
        expect(wrapper.find('[data-testid="status-chip-overdue"]').text()).toContain('2');
    });

    it('filters the user list by a status chip', async () => {
        const wrapper = await mountDetail();

        await wrapper.find('[data-testid="status-chip-overdue"]').trigger('click');
        await flushPromises();

        expect(getParams().at(-1)).toMatchObject({ status: 'overdue' });
    });

    it('sends the user search to the server', async () => {
        const wrapper = await mountDetail();
        const before = getParams().length;

        vi.useFakeTimers();
        await wrapper.find('#detail_q').setValue('baker');
        await vi.advanceTimersByTimeAsync(400);
        vi.useRealTimers();
        await flushPromises();

        expect(getParams().length).toBeGreaterThan(before);
        expect(getParams().at(-1)).toMatchObject({ q: 'baker' });
    });

    it('assembles a class from the selected users, then navigates to it', async () => {
        const wrapper = await mountDetail();

        // Nothing selected → assemble disabled.
        const btn = wrapper.find('[data-testid="assemble-class"]');
        expect(btn.attributes('disabled')).toBeDefined();

        // Select all on the page via the header checkbox.
        wrapper.findAllComponents(Checkbox)[0].vm.$emit('update:modelValue', true);
        await flushPromises();
        expect(wrapper.find('[data-testid="assemble-class"]').attributes('disabled')).toBeUndefined();

        await wrapper.find('[data-testid="assemble-class"]').trigger('click');
        await flushPromises();

        // The class form modal fires `saved` with the new class.
        wrapper.findComponent(ClassFormModal).vm.$emit('saved', { id: 'c1' });
        await flushPromises();

        // Selected users are bulk-enrolled, then we navigate to the class.
        expect(axios.post).toHaveBeenCalledWith(
            '/api/classes/c1/enrollments/bulk',
            { enroll: ['u1', 'u2'], unenroll: [] },
            expect.anything(),
        );
        expect(routerVisit).toHaveBeenCalledWith({ url: '/classes/c1' });
    });
});
