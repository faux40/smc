import { flushPromises, mount } from '@vue/test-utils';
import axios from 'axios';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import NeedsActionWidget from '@/components/dashboard/NeedsActionWidget.vue';

vi.mock('axios');
vi.mock('@inertiajs/vue3', () => ({
    Link: { template: '<a><slot /></a>' },
}));
vi.mock('@/routes/users', () => ({
    show: (id: string) => ({ url: `/users/${id}` }),
}));

// Server order: overdue (worst first) → not_started → due_soon.
const allRows = [
    {
        id: 'ta1',
        user_id: 'u1',
        user_name: 'Alice Aardvark',
        training_id: 't1',
        training_name: 'Fall Protection',
        status: 'overdue',
        expires_at: '2026-04-01',
        days_until_due: -70,
        sources: [{ type: 'requirement', id: 'r1', name: 'OSHA General' }],
    },
    {
        id: 'ta2',
        user_id: 'u2',
        user_name: 'Bob Badger',
        training_id: 't1',
        training_name: 'Fall Protection',
        status: 'not_started',
        expires_at: null,
        days_until_due: null,
        sources: [{ type: 'direct', id: null, name: null }],
    },
    {
        id: 'ta3',
        user_id: 'u1',
        user_name: 'Alice Aardvark',
        training_id: 't2',
        training_name: 'Forklift',
        status: 'due_soon',
        expires_at: '2026-06-20',
        days_until_due: 10,
        sources: [{ type: 'direct', id: null, name: null }],
    },
];

const META = { current_page: 1, last_page: 1, per_page: 50, total: 3 };

/** Server does the filtering — respond to status + q query params. */
function mockGet(rows = allRows) {
    (axios.get as ReturnType<typeof vi.fn>).mockImplementation(
        (url: string, config?: { params?: Record<string, string> }) => {
            if (url !== '/api/dashboard/needs-action') {
                return Promise.reject(new Error(`unexpected GET ${url}`));
            }

            let data = rows;
            const status = config?.params?.status;
            const q = config?.params?.q?.toLowerCase();

            if (status) {
                data = data.filter((r) => r.status === status);
            }

            if (q) {
                data = data.filter(
                    (r) =>
                        (r.user_name ?? '').toLowerCase().includes(q) ||
                        r.training_name.toLowerCase().includes(q),
                );
            }

            return Promise.resolve({
                data: { data, meta: { ...META, total: data.length } },
            });
        },
    );
}

/** Params of each GET to the needs-action endpoint, in call order. */
function calls(): Array<Record<string, unknown>> {
    return (axios.get as ReturnType<typeof vi.fn>).mock.calls
        .filter((c) => c[0] === '/api/dashboard/needs-action')
        .map((c) => (c[1]?.params ?? {}) as Record<string, unknown>);
}

async function mountWidget() {
    mockGet();
    const wrapper = mount(NeedsActionWidget);
    await flushPromises();

    return wrapper;
}

function groupHeaders(wrapper: ReturnType<typeof mount>): string[] {
    return wrapper.findAll('[data-test="group-header"]').map((h) => h.text());
}

describe('NeedsActionWidget', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        vi.clearAllMocks();
    });

    it('fetches page 1 at 50 per page on mount', async () => {
        await mountWidget();

        expect(calls()[0]).toMatchObject({ page: 1, per_page: 50 });
    });

    it('groups the page by user by default, preserving worst-first order', async () => {
        const wrapper = await mountWidget();

        const headers = groupHeaders(wrapper);
        expect(headers).toHaveLength(2);
        // Alice owns the most-overdue row → her group leads.
        expect(headers[0]).toContain('Alice Aardvark');
        expect(headers[1]).toContain('Bob Badger');
        expect(wrapper.text()).toContain('Fall Protection');
        expect(wrapper.text()).toContain('Forklift');
    });

    it('regroups by training via the toggle (no refetch)', async () => {
        const wrapper = await mountWidget();
        const before = calls().length;

        await wrapper.find('[data-test="group-by-training"]').trigger('click');

        const headers = groupHeaders(wrapper);
        expect(headers).toHaveLength(2);
        expect(headers[0]).toContain('Fall Protection');
        expect(headers[1]).toContain('Forklift');
        // Grouping is client-side over the current page — no new request.
        expect(calls().length).toBe(before);
    });

    it('renders status badge, due date, days and source chips', async () => {
        const wrapper = await mountWidget();

        const text = wrapper.text();
        expect(text).toContain('Overdue');
        expect(text).toContain('Not started');
        expect(text).toContain('Due soon');
        expect(text).toContain('2026-04-01');
        expect(text).toContain('-70');
        expect(text).toContain('OSHA General');
        expect(text).toContain('Direct');
    });

    it('sends the status filter to the server and resets to page 1', async () => {
        const wrapper = await mountWidget();

        await wrapper
            .find('[data-test="status-filter"]')
            .setValue('not_started');
        await flushPromises();

        expect(calls().at(-1)).toMatchObject({ status: 'not_started', page: 1 });
        expect(wrapper.text()).toContain('Bob Badger');
        expect(wrapper.text()).not.toContain('Forklift');
    });

    it('sends the search term to the server (debounced)', async () => {
        const wrapper = await mountWidget();

        vi.useFakeTimers();
        await wrapper.find('input[type="search"]').setValue('forklift');
        await vi.advanceTimersByTimeAsync(400);
        vi.useRealTimers();
        await flushPromises();

        expect(calls().at(-1)).toMatchObject({ q: 'forklift' });
        const headers = groupHeaders(wrapper);
        expect(headers).toHaveLength(1);
        expect(headers[0]).toContain('Alice Aardvark');
        expect(wrapper.text()).toContain('Forklift');
        expect(wrapper.text()).not.toContain('Fall Protection');
    });

    it('shows the all-clear message when nothing needs action', async () => {
        mockGet([]);
        const wrapper = mount(NeedsActionWidget);
        await flushPromises();

        expect(wrapper.text().toLowerCase()).toContain('nothing needs action');
    });
});

describe('NeedsActionWidget — Record completion row action (F7)', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        vi.clearAllMocks();
    });

    it('opens the completion modal prefilled with the row user + training id (not the assignment id)', async () => {
        const wrapper = await mountWidget();

        const rowBtn = wrapper.findAll('[data-test="row-record-completion"]')[0];
        await rowBtn.trigger('click');

        const modal = wrapper.findComponent({ name: 'CompletionFormModal' });
        expect(modal.props('open')).toBe(true);
        expect(modal.props('initialUserId')).toBe('u1');
        // allRows[0].id is the TA id 'ta1' — must not leak in as the training.
        expect(modal.props('initialTrainingId')).toBe('t1');
    });

    it('refetches the current page and notifies the parent after a completion is saved', async () => {
        const wrapper = await mountWidget();
        const before = calls().length;

        const modal = wrapper.findComponent({ name: 'CompletionFormModal' });
        await modal.vm.$emit('saved');
        await flushPromises();

        expect(calls().length).toBeGreaterThan(before);
        expect(wrapper.emitted('completion-recorded')).toBeTruthy();
    });
});
