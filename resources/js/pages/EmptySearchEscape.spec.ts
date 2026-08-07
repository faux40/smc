import { flushPromises, mount } from '@vue/test-utils';
import axios from 'axios';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import CompletionsIndex from '@/pages/completions/Index.vue';
import RequirementsIndex from '@/pages/requirements/Index.vue';

/*
 * A search that matches nothing must still show the search box.
 *
 * Reported 2026-08-06: "the search returns nothing (this is correct for my
 * search) but I am stuck at a screen with only a new requirements button. No
 * visibility to the filter or a way out."
 *
 * The filters live in DataTable's #filters slot, so handing AsyncState an
 * `:empty` swaps out the whole table — filters included — and strands the user
 * with no way to clear the term that emptied it. The fix is the pattern three
 * other index pages already use: let DataTable render its own #empty row and
 * keep the filter bar mounted.
 *
 * AsyncState is deliberately NOT stubbed here. Both pages' own specs stub it as
 * `<div><slot /></div>`, a pass-through that renders the default slot
 * unconditionally — which is exactly why neither could see this.
 */

const { authUser } = vi.hoisted(() => ({
    authUser: {
        value: { id: 'me', org_id: 'org1', isAdmin: true } as Record<
            string,
            unknown
        >,
    },
}));

vi.mock('axios');
vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<div><slot /></div>' },
    Link: { template: '<a :href="href"><slot /></a>', props: ['href'] },
    router: { visit: vi.fn() },
    usePage: () => ({ props: { auth: { user: authUser.value } } }),
}));
vi.mock('@/routes/requirements', () => ({
    page: () => '/requirements',
    show: (id: string) => `/requirements/${id}`,
}));
vi.mock('@/routes/completions', () => ({ page: () => '/completions' }));
vi.mock('@/routes/users', () => ({
    index: () => '/users',
    show: (id: string) => `/users/${id}`,
}));

/** The paged list answers "no matches"; the picker endpoints answer normally. */
const PAGED = ['/api/requirements/paged', '/api/completions'];

function mockEmpty() {
    (axios.get as ReturnType<typeof vi.fn>).mockImplementation(
        (url: string, config?: { params?: Record<string, unknown> }) => {
            if (!PAGED.includes(url)) {
                // Users/trainings pickers etc. — plain arrays, and they are not
                // what is being searched.
                return Promise.resolve({ data: [] });
            }

            return Promise.resolve({
                data: {
                    data: [],
                    meta: {
                        current_page: Number(config?.params?.page ?? 1),
                        last_page: 1,
                        per_page: 25,
                        total: 0,
                    },
                },
            });
        },
    );
}

const STUBS = {
    // Only the things this assertion does not care about. AsyncState and
    // DataTable stay real — they are the two components under test.
    RequirementFormModal: true,
    CompletionFormModal: true,
    Heading: true,
    TableColumnsMenu: true,
};

beforeEach(() => {
    setActivePinia(createPinia());
    vi.clearAllMocks();
    mockEmpty();
});

const PAGES = [
    { name: 'requirements', component: RequirementsIndex },
    { name: 'completions', component: CompletionsIndex },
];

describe.each(PAGES)(
    '$name — an empty result still offers a way out',
    ({ component }) => {
        async function mountEmpty() {
            const wrapper = mount(component, { global: { stubs: STUBS } });
            await flushPromises();

            return wrapper;
        }

        it('keeps the search input on screen', async () => {
            const wrapper = await mountEmpty();

            // The control that produced the empty result must survive it.
            expect(wrapper.find('#filter_q').exists()).toBe(true);
        });

        it('says why the table is empty', async () => {
            const wrapper = await mountEmpty();

            expect(wrapper.text().toLowerCase()).toMatch(/no .*(match|found)/);
        });

        it('still renders the table shell, not a replacement panel', async () => {
            // The empty message belongs in a row inside the table (DataTable's own
            // #empty slot), which is what keeps the header and filter bar mounted.
            const wrapper = await mountEmpty();

            expect(wrapper.find('table').exists()).toBe(true);
            expect(wrapper.findAll('thead th').length).toBeGreaterThan(0);
        });
    },
);
