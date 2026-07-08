import { flushPromises, mount } from '@vue/test-utils';
import axios from 'axios';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import TagFilter from '@/components/TagFilter.vue';
import { Checkbox } from '@/components/ui/checkbox';
import { toast } from 'vue-sonner';
import AddToClassModal from '@/pages/classes/Partials/AddToClassModal.vue';
import ClassFormModal from '@/pages/classes/Partials/ClassFormModal.vue';
import CompletionFormModal from '@/pages/completions/Partials/CompletionFormModal.vue';
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
vi.mock('vue-sonner', () => ({ toast: { success: vi.fn() } }));
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
                { user_id: 'u1', training_assignment_id: 'ta-u1', name: 'Adams, Amy', status: 'overdue', expires_at: '2026-01-01', last_completed_at: '2025-01-01', employee_number: 'EMP-1', department: 'Ops', location: 'Yard 3', tag_ids: [] },
                { user_id: 'u2', training_assignment_id: 'ta-u2', name: 'Baker, Bob', status: 'current', expires_at: null, last_completed_at: '2026-02-01', employee_number: 'EMP-2', department: 'Admin', location: 'HQ', tag_ids: [] },
            ],
            meta: META,
        },
    };
}

function stubAxios() {
    (axios.get as ReturnType<typeof vi.fn>).mockImplementation((url: string) => {
        if (url === USERS_URL) return Promise.resolve(usersResponse());
        if (url === '/api/tags') return Promise.resolve({ data: [] });
        // ClassActionsBar eligibility pre-check → one scheduled class exists.
        if (url === '/api/classes') {
            return Promise.resolve({ data: { data: [{ id: 'c1', name: 'A', scheduled_date: null, enrollments_count: 0 }], meta: {} } });
        }
        return Promise.resolve({ data: [] });
    });
    (axios.post as ReturnType<typeof vi.fn>).mockImplementation((url: string) => {
        if (url === '/api/assignments/remind-bulk') {
            return Promise.resolve({
                data: { reminded_count: 1, skipped_count: 1, supervisors_notified_count: 1 },
            });
        }
        if (/^\/api\/assignments\/.+\/remind$/.test(url)) {
            return Promise.resolve({
                data: { sent: true, status: 'overdue', supervisor_notified: true },
            });
        }
        return Promise.resolve({ data: { id: 'c1' } });
    });
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
        global: {
            stubs: {
                ClassFormModal: true,
                AddToClassModal: true,
                CompletionFormModal: true,
            },
        },
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
        // Full user info columns (EE# / dept / location) render.
        expect(wrapper.text()).toContain('EMP-1');
        expect(wrapper.text()).toContain('Ops');
        expect(wrapper.text()).toContain('Yard 3');
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

    it('links the export-report button to the training record PDF endpoint', async () => {
        const wrapper = await mountDetail();
        const link = wrapper.find('[data-testid="export-training-record"]');
        expect(link.exists()).toBe(true);
        expect(link.attributes('href')).toBe('/api/reports/training/t1/record');
    });

    it('refetches with the chosen tags when the tag filter changes', async () => {
        const wrapper = await mountDetail();
        const before = getParams().length;

        wrapper.findComponent(TagFilter).vm.$emit('update:tag-ids', ['tag1']);
        await flushPromises();

        expect(getParams().length).toBeGreaterThan(before);
        expect(getParams().at(-1)).toMatchObject({ tags: ['tag1'], tags_mode: 'and' });
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

    it('offers "add to existing class" for the selection and clears it after adding', async () => {
        const wrapper = await mountDetail();

        // Disabled with nothing selected, enabled once users are picked.
        const addBtn = wrapper.find('[data-testid="add-to-class"]');
        expect(addBtn.exists()).toBe(true);
        expect(addBtn.attributes('disabled')).toBeDefined();

        wrapper.findAllComponents(Checkbox)[0].vm.$emit('update:modelValue', true);
        await flushPromises();
        expect(wrapper.find('[data-testid="add-to-class"]').attributes('disabled')).toBeUndefined();

        const modal = wrapper.findComponent(AddToClassModal);
        expect(modal.props('userIds')).toEqual(['u1', 'u2']);
        expect(modal.props('trainingId')).toBe('t1');

        // Adding to an existing class stays on the list and clears the selection.
        modal.vm.$emit('added', 'c9');
        await flushPromises();
        expect(routerVisit).not.toHaveBeenCalledWith({ url: '/classes/c9' });
        expect(wrapper.find('[data-testid="selection-bar"]').exists()).toBe(false);
    });

    it('opens the completion modal in multi-user mode for the selection', async () => {
        const wrapper = await mountDetail();

        // Disabled until users are selected.
        const btn = wrapper.find('[data-testid="record-completion"]');
        expect(btn.exists()).toBe(true);
        expect(btn.attributes('disabled')).toBeDefined();

        wrapper.findAllComponents(Checkbox)[0].vm.$emit('update:modelValue', true);
        await flushPromises();
        expect(
            wrapper.find('[data-testid="record-completion"]').attributes('disabled'),
        ).toBeUndefined();

        await wrapper.find('[data-testid="record-completion"]').trigger('click');
        await flushPromises();

        const modal = wrapper.findComponent(CompletionFormModal);
        expect(modal.props('open')).toBe(true);
        expect(modal.props('userIds')).toEqual(['u1', 'u2']);
        expect(modal.props('initialTrainingId')).toBe('t1');
    });

    it('toasts the tallies, clears the selection, and refetches after recording', async () => {
        const wrapper = await mountDetail();

        wrapper.findAllComponents(Checkbox)[0].vm.$emit('update:modelValue', true);
        await flushPromises();
        await wrapper.find('[data-testid="record-completion"]').trigger('click');
        await flushPromises();

        const before = getParams().length;

        wrapper
            .findComponent(CompletionFormModal)
            .vm.$emit('saved', { created_count: 2, skipped_count: 1 });
        await flushPromises();

        expect(toast.success).toHaveBeenCalledWith(
            'Recorded 2 completions · 1 skipped.',
        );
        // Selection cleared and the table refetched so statuses re-render.
        expect(wrapper.find('[data-testid="selection-bar"]').exists()).toBe(false);
        expect(getParams().length).toBeGreaterThan(before);
    });

    // ── F10 Remind ────────────────────────────────────────────────────

    it('reminds a single row via the assignment remind endpoint', async () => {
        const wrapper = await mountDetail();

        await wrapper.find('[data-testid="row-remind-u1"]').trigger('click');
        await flushPromises();

        expect(axios.post).toHaveBeenCalledWith(
            '/api/assignments/ta-u1/remind',
            {},
            expect.anything(),
        );
        expect(toast.success).toHaveBeenCalledWith('Reminder sent (supervisor CC’d).');
    });

    it('reminds the selection in bulk, then clears it and refetches', async () => {
        const wrapper = await mountDetail();

        // Disabled until a row is picked.
        const btn = wrapper.find('[data-testid="remind-selected"]');
        expect(btn.attributes('disabled')).toBeDefined();

        wrapper.findAllComponents(Checkbox)[0].vm.$emit('update:modelValue', true);
        await flushPromises();

        const before = getParams().length;
        await wrapper.find('[data-testid="remind-selected"]').trigger('click');
        await flushPromises();

        expect(axios.post).toHaveBeenCalledWith(
            '/api/assignments/remind-bulk',
            { training_assignment_ids: ['ta-u1', 'ta-u2'] },
            expect.anything(),
        );
        expect(toast.success).toHaveBeenCalledWith(
            'Reminder sent to 1 person (1 supervisor CC’d) · 1 skipped.',
        );
        // Selection cleared + table refetched.
        expect(wrapper.find('[data-testid="selection-bar"]').exists()).toBe(false);
        expect(getParams().length).toBeGreaterThan(before);
    });
});
