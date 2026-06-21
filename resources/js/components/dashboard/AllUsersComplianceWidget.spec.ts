import { flushPromises, mount } from '@vue/test-utils';
import axios from 'axios';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import AllUsersComplianceWidget from '@/components/dashboard/AllUsersComplianceWidget.vue';

vi.mock('axios');
vi.mock('@inertiajs/vue3', () => ({
    Link: { template: '<a><slot /></a>' },
}));
vi.mock('@/routes/users', () => ({
    show: (id: string) => ({ url: `/users/${id}` }),
}));

const userRows = [
    {
        user_id: 'u1',
        name: 'Alice',
        email: 'alice@x.com',
        counts: { overdue: 3, due_soon: 1, current: 0, not_started: 0, as_needed: 0 },
        overall_status: 'overdue',
        tag_ids: [],
    },
    {
        user_id: 'u2',
        name: 'Bob',
        email: 'bob@x.com',
        counts: { overdue: 0, due_soon: 2, current: 1, not_started: 0, as_needed: 0 },
        overall_status: 'due_soon',
        tag_ids: [],
    },
    {
        user_id: 'u3',
        name: 'Carol',
        email: 'carol@x.com',
        counts: { overdue: 1, due_soon: 0, current: 0, not_started: 0, as_needed: 0 },
        overall_status: 'overdue',
        tag_ids: [],
    },
];

const META = { current_page: 1, last_page: 1, per_page: 25, total: userRows.length };

type DetailItem = {
    training_name?: string | null;
    status?: string;
    expires_at?: string | null;
    last_completed_at?: string | null;
    days_until_due?: number | null;
    sources?: Array<{ type: string; id: string | null; name: string | null }>;
};

function mockGet(
    detail: { groups: Record<string, DetailItem[]> } = {
        groups: { overdue: [], due_soon: [] },
    },
) {
    (axios.get as ReturnType<typeof vi.fn>).mockImplementation((url: string) => {
        if (url === '/api/dashboard/users-compliance') {
            return Promise.resolve({ data: { data: userRows, meta: META } });
        }
        if (url === '/api/tags') {
            return Promise.resolve({ data: [] });
        }
        if (url.endsWith('/training-compliance')) {
            return Promise.resolve({ data: detail });
        }

        return Promise.reject(new Error(`unexpected GET ${url}`));
    });
}

/** Params of each GET to the users-compliance endpoint, in call order. */
function complianceParams(): Array<Record<string, unknown>> {
    return (axios.get as ReturnType<typeof vi.fn>).mock.calls
        .filter((c) => c[0] === '/api/dashboard/users-compliance')
        .map((c) => (c[1]?.params ?? {}) as Record<string, unknown>);
}

async function mountWidget() {
    const wrapper = mount(AllUsersComplianceWidget);
    await flushPromises();

    return wrapper;
}

function dataRowNames(wrapper: ReturnType<typeof mount>): string[] {
    return wrapper
        .findAll('tbody tr')
        .map((tr) => tr.find('td')?.text() ?? '')
        .filter((t) => t.length > 0);
}

describe('AllUsersComplianceWidget', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        vi.clearAllMocks();
    });

    it('renders a row per fetched user', async () => {
        mockGet();
        const wrapper = await mountWidget();

        const names = dataRowNames(wrapper);
        expect(names.some((n) => n.includes('Alice'))).toBe(true);
        expect(names.some((n) => n.includes('Bob'))).toBe(true);
        expect(names.some((n) => n.includes('Carol'))).toBe(true);
    });

    it('requests overdue-descending sort on first load', async () => {
        mockGet();
        await mountWidget();

        expect(complianceParams()[0]).toMatchObject({ sort: 'overdue', dir: 'desc' });
    });

    it('shows the bucket count inside the status pill', async () => {
        mockGet();
        const wrapper = await mountWidget();

        // Rows render in server order (Alice first); status cell is column 2.
        const statusCell = wrapper.find('tbody tr').findAll('td')[1];
        expect(statusCell.text()).toContain('Overdue');
        expect(statusCell.text()).toContain('3');
    });

    it('sends the search term to the server (debounced)', async () => {
        mockGet();
        const wrapper = await mountWidget();
        const before = complianceParams().length;

        vi.useFakeTimers();
        await wrapper.find('input[type="search"]').setValue('bob');
        await vi.advanceTimersByTimeAsync(400);
        vi.useRealTimers();
        await flushPromises();

        expect(complianceParams().length).toBeGreaterThan(before);
        expect(complianceParams().at(-1)).toMatchObject({ q: 'bob' });
    });

    it('re-fetches with the due_soon sort key when that header is clicked', async () => {
        mockGet();
        const wrapper = await mountWidget();

        const dueSoonHeader = wrapper
            .findAll('thead th button')
            .find((b) => b.text().startsWith('Due soon'));
        await dueSoonHeader!.trigger('click');
        await flushPromises();

        expect(complianceParams().at(-1)).toMatchObject({ sort: 'due_soon' });
    });

    it('lazy-loads detail from /api/users/{id}/training-compliance on expand', async () => {
        mockGet({
            groups: {
                overdue: [
                    {
                        training_name: 'Fall Protection',
                        status: 'overdue',
                        expires_at: '2026-06-05',
                        last_completed_at: '2025-06-05',
                        days_until_due: -5,
                    },
                ],
                due_soon: [],
            },
        });
        const wrapper = await mountWidget();

        const expandBtn = wrapper.find('tbody tr td:last-child button');
        await expandBtn.trigger('click');
        await flushPromises();

        expect(axios.get).toHaveBeenCalledWith(
            '/api/users/u1/training-compliance',
            expect.anything(),
        );
        expect(wrapper.text()).toContain('Fall Protection');
    });

    it('renders status, due date, last completion and days in the detail row', async () => {
        mockGet({
            groups: {
                overdue: [
                    {
                        training_name: 'Fall Protection',
                        status: 'overdue',
                        expires_at: '2026-06-05',
                        last_completed_at: '2025-06-05',
                        days_until_due: -5,
                        sources: [
                            { type: 'requirement', id: 'r1', name: 'OSHA General' },
                        ],
                    },
                ],
            },
        });
        const wrapper = await mountWidget();

        await wrapper.find('tbody tr td:last-child button').trigger('click');
        await flushPromises();

        const detailRow = wrapper.find('tbody tr.bg-muted\\/20');
        expect(detailRow.text()).toContain('Fall Protection');
        expect(detailRow.text()).toContain('Overdue');
        expect(detailRow.text()).toContain('2026-06-05');
        expect(detailRow.text()).toContain('2025-06-05');
        expect(detailRow.text()).toContain('-5');
        expect(detailRow.text()).toContain('OSHA General');
    });

    it('includes every status group in the detail, worst first', async () => {
        mockGet({
            groups: {
                due_soon: [
                    {
                        training_name: 'Forklift',
                        status: 'due_soon',
                        expires_at: '2026-07-01',
                        last_completed_at: '2025-07-01',
                        days_until_due: 21,
                    },
                ],
                current: [
                    {
                        training_name: 'OSHA General',
                        status: 'current',
                        expires_at: '2027-01-01',
                        last_completed_at: '2026-01-01',
                        days_until_due: 205,
                    },
                ],
                not_started: [
                    {
                        training_name: 'Confined Space',
                        status: 'not_started',
                        expires_at: null,
                        last_completed_at: null,
                        days_until_due: null,
                    },
                ],
            },
        });
        const wrapper = await mountWidget();

        await wrapper.find('tbody tr td:last-child button').trigger('click');
        await flushPromises();

        const items = wrapper
            .findAll('tbody tr.bg-muted\\/20 li')
            .map((li) => li.text());
        expect(items).toHaveLength(3);
        expect(items[0]).toContain('Forklift');
        expect(items[1]).toContain('Confined Space');
        expect(items[2]).toContain('OSHA General');
        expect(items[1].toLowerCase()).toContain('not started');
    });
});
