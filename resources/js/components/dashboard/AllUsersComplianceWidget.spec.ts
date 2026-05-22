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
        counts: {
            overdue: 3,
            due_soon: 1,
            current: 0,
            never_started: 0,
            inactive: 0,
        },
        overall_status: 'overdue',
        tag_ids: [],
    },
    {
        user_id: 'u2',
        name: 'Bob',
        email: 'bob@x.com',
        counts: {
            overdue: 0,
            due_soon: 2,
            current: 1,
            never_started: 0,
            inactive: 0,
        },
        overall_status: 'due_soon',
        tag_ids: [],
    },
    {
        user_id: 'u3',
        name: 'Carol',
        email: 'carol@x.com',
        counts: {
            overdue: 1,
            due_soon: 0,
            current: 0,
            never_started: 0,
            inactive: 0,
        },
        overall_status: 'overdue',
        tag_ids: [],
    },
];

type DetailItem = { name?: string; days_until_due?: number | null };

function mockGet(
    detail: { groups: { overdue: DetailItem[]; due_soon: DetailItem[] } } = {
        groups: { overdue: [], due_soon: [] },
    },
) {
    (axios.get as ReturnType<typeof vi.fn>).mockImplementation(
        (url: string) => {
            if (url === '/api/dashboard/users-compliance') {
                return Promise.resolve({ data: userRows });
            }

            if (url === '/api/tags') {
                return Promise.resolve({ data: [] });
            }

            if (url.endsWith('/compliance')) {
                return Promise.resolve({ data: detail });
            }

            return Promise.reject(new Error(`unexpected GET ${url}`));
        },
    );
}

async function mountWidget() {
    const wrapper = mount(AllUsersComplianceWidget);
    await flushPromises();

    return wrapper;
}

function dataRowNames(wrapper: ReturnType<typeof mount>): string[] {
    // First cell of each user row holds the name link.
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

    it('defaults to sorting by overdue, descending', async () => {
        mockGet();
        const wrapper = await mountWidget();

        // overdue: Alice 3, Carol 1, Bob 0
        const first = dataRowNames(wrapper)[0];
        expect(first).toContain('Alice');
    });

    it('shows the bucket count inside the status pill', async () => {
        mockGet();
        const wrapper = await mountWidget();

        // Alice (overdue, count 3) sorts first; status cell is the 2nd column.
        const statusCell = wrapper.find('tbody tr').findAll('td')[1];
        expect(statusCell.text()).toContain('Overdue');
        expect(statusCell.text()).toContain('3');
    });

    it('filters by the search box', async () => {
        mockGet();
        const wrapper = await mountWidget();

        await wrapper.find('input[type="search"]').setValue('bob');

        const names = dataRowNames(wrapper);
        expect(names).toHaveLength(1);
        expect(names[0]).toContain('Bob');
    });

    it('re-sorts when the due-soon header is clicked', async () => {
        mockGet();
        const wrapper = await mountWidget();

        const dueSoonHeader = wrapper
            .findAll('thead th button')
            .find((b) => b.text().startsWith('Due soon'));
        await dueSoonHeader!.trigger('click');

        // due_soon: Bob 2, Alice 1, Carol 0
        expect(dataRowNames(wrapper)[0]).toContain('Bob');
    });

    it('lazy-loads detail from /api/users/{id}/compliance on expand', async () => {
        mockGet({
            groups: {
                overdue: [{ name: 'Fall Protection', days_until_due: -5 }],
                due_soon: [],
            },
        });
        const wrapper = await mountWidget();

        // Expand the first row (Alice).
        const expandBtn = wrapper.find('tbody tr td:last-child button');
        await expandBtn.trigger('click');
        await flushPromises();

        expect(axios.get).toHaveBeenCalledWith(
            '/api/users/u1/compliance',
            expect.anything(),
        );
        expect(wrapper.text()).toContain('Fall Protection');
    });
});
