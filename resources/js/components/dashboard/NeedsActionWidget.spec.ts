import { flushPromises, mount } from '@vue/test-utils';
import axios from 'axios';
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
const rows = [
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

function mockGet() {
    (axios.get as ReturnType<typeof vi.fn>).mockImplementation(
        (url: string) => {
            if (url === '/api/dashboard/needs-action') {
                return Promise.resolve({ data: rows });
            }

            return Promise.reject(new Error(`unexpected GET ${url}`));
        },
    );
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
        vi.clearAllMocks();
    });

    it('groups by user by default, preserving worst-first order', async () => {
        const wrapper = await mountWidget();

        const headers = groupHeaders(wrapper);
        expect(headers).toHaveLength(2);
        // Alice owns the most-overdue row → her group leads.
        expect(headers[0]).toContain('Alice Aardvark');
        expect(headers[1]).toContain('Bob Badger');
        // Rows show the other dimension (training names).
        expect(wrapper.text()).toContain('Fall Protection');
        expect(wrapper.text()).toContain('Forklift');
    });

    it('regroups by training via the toggle', async () => {
        const wrapper = await mountWidget();

        await wrapper.find('[data-test="group-by-training"]').trigger('click');

        const headers = groupHeaders(wrapper);
        expect(headers).toHaveLength(2);
        expect(headers[0]).toContain('Fall Protection');
        expect(headers[1]).toContain('Forklift');
        expect(wrapper.text()).toContain('Alice Aardvark');
        expect(wrapper.text()).toContain('Bob Badger');
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

    it('filters by the status select', async () => {
        const wrapper = await mountWidget();

        await wrapper
            .find('[data-test="status-filter"]')
            .setValue('not_started');

        expect(wrapper.text()).toContain('Bob Badger');
        expect(wrapper.text()).not.toContain('Forklift');
    });

    it('filters by the search box across user and training names', async () => {
        const wrapper = await mountWidget();

        await wrapper.find('input[type="search"]').setValue('forklift');

        const headers = groupHeaders(wrapper);
        expect(headers).toHaveLength(1);
        expect(headers[0]).toContain('Alice Aardvark');
        expect(wrapper.text()).toContain('Forklift');
        expect(wrapper.text()).not.toContain('Fall Protection');
    });

    it('shows the all-clear message when nothing needs action', async () => {
        (axios.get as ReturnType<typeof vi.fn>).mockResolvedValue({ data: [] });
        const wrapper = mount(NeedsActionWidget);
        await flushPromises();

        expect(wrapper.text().toLowerCase()).toContain('nothing needs action');
    });
});
