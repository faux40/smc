import { flushPromises, mount } from '@vue/test-utils';
import axios from 'axios';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import ClassesIndex from '@/pages/classes/Index.vue';

const { authUser } = vi.hoisted(() => ({
    authUser: {
        value: { id: 'me', org_id: 'org1' } as Record<string, unknown>,
    },
}));

vi.mock('axios');
vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<div><slot /></div>' },
    Link: { template: '<a :href="href"><slot /></a>', props: ['href'] },
    router: { visit: vi.fn() },
    usePage: () => ({ props: { auth: { user: authUser.value } } }),
}));
vi.mock('@/routes/classes', () => ({
    page: () => '/classes',
    showPage: (id: string) => `/classes/${id}`,
}));

const CLASSES = [
    {
        id: 'cl1',
        name: 'June Safety Day',
        scheduled_date: '2026-06-20',
        location: 'Main Hall',
        instructor: 'Jane Doe',
        total_hours: '4.0',
        status: 'scheduled',
        trainings_count: 3,
        enrollments_count: 12,
        can_edit: true,
        can_delete: true,
    },
    {
        id: 'cl2',
        name: 'Forklift Recert',
        scheduled_date: '2026-05-01',
        location: null,
        instructor: null,
        total_hours: null,
        status: 'completed',
        trainings_count: 1,
        enrollments_count: 4,
        can_edit: false,
        can_delete: false,
    },
];

const STUBS = {
    ClassFormModal: true,
    Heading: true,
    AsyncState: { template: '<div><slot /></div>' },
    TableColumnsMenu: true,
};

// Captured params from each GET /api/classes call (the paged contract).
let classesParams: Array<Record<string, unknown>> = [];
const lastParams = () => classesParams.at(-1) ?? {};

function mockAxios() {
    (axios.get as ReturnType<typeof vi.fn>).mockImplementation(
        (url: string, config?: { params?: Record<string, unknown> }) => {
            if (url === '/api/classes') {
                classesParams.push(config?.params ?? {});

                return Promise.resolve({
                    data: {
                        data: CLASSES,
                        meta: {
                            current_page: Number(config?.params?.page ?? 1),
                            last_page: 2,
                            per_page: Number(config?.params?.per_page ?? 25),
                            total: 30,
                        },
                    },
                });
            }

            return Promise.resolve({ data: [] });
        },
    );
}

async function mountPage() {
    mockAxios();
    const wrapper = mount(ClassesIndex, { global: { stubs: STUBS } });
    await flushPromises();

    return wrapper;
}

describe('classes/Index — server-paged table', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        vi.clearAllMocks();
        classesParams = [];
        authUser.value = { id: 'me', org_id: 'org1' };
    });

    it('renders the column headers (incl. the row-# lead column)', async () => {
        const wrapper = await mountPage();
        const headers = wrapper.findAll('thead th').map((th) => th.text());

        for (const h of [
            'Name',
            'Instructor',
            'Date',
            'Hours',
            'Location',
            'Trainings',
            'Enrolled',
            'Status',
        ]) {
            expect(headers.some((x) => x.includes(h))).toBe(true);
        }

        expect(headers.some((x) => x.trim() === '#')).toBe(true);
    });

    it('renders server-resolved fields and the detail link', async () => {
        const wrapper = await mountPage();
        const text = wrapper.text();
        expect(text).toContain('June Safety Day');
        expect(text).toContain('Jane Doe');
        expect(text).toContain('Main Hall');
        expect(wrapper.find('a[href="/classes/cl1"]').exists()).toBe(true);
    });

    it('requests page 1 sorted by scheduled_date desc on mount', async () => {
        await mountPage();
        const p = classesParams[0];
        expect(p.page).toBe(1);
        expect(p.sort).toBe('scheduled_date');
        expect(p.dir).toBe('desc');
    });

    it('asks the server for the sort when a sortable header is clicked', async () => {
        const wrapper = await mountPage();
        const nameBtn = wrapper
            .findAll('thead button')
            .find((b) => b.text().includes('Name'));
        await nameBtn!.trigger('click');
        await flushPromises();
        expect(lastParams().sort).toBe('name');
        expect(lastParams().dir).toBe('asc');

        await nameBtn!.trigger('click');
        await flushPromises();
        expect(lastParams().dir).toBe('desc');
    });

    it('Instructor header is sortable and sends sort=instructor', async () => {
        const wrapper = await mountPage();
        const btn = wrapper
            .findAll('thead button')
            .find((b) => b.text().includes('Instructor'));
        expect(btn).toBeTruthy();
        await btn!.trigger('click');
        await flushPromises();
        expect(lastParams().sort).toBe('instructor');
    });

    it('Location header is sortable and sends sort=location', async () => {
        const wrapper = await mountPage();
        const btn = wrapper
            .findAll('thead button')
            .find((b) => b.text().includes('Location'));
        expect(btn).toBeTruthy();
        await btn!.trigger('click');
        await flushPromises();
        expect(lastParams().sort).toBe('location');
    });

    it('Trainings header is sortable and sends sort=class_trainings_count', async () => {
        const wrapper = await mountPage();
        const btn = wrapper
            .findAll('thead button')
            .find((b) => b.text().includes('Trainings'));
        expect(btn).toBeTruthy();
        await btn!.trigger('click');
        await flushPromises();
        expect(lastParams().sort).toBe('class_trainings_count');
    });

    it('Enrolled header is sortable and sends sort=enrollments_count', async () => {
        const wrapper = await mountPage();
        const btn = wrapper
            .findAll('thead button')
            .find((b) => b.text().includes('Enrolled'));
        expect(btn).toBeTruthy();
        await btn!.trigger('click');
        await flushPromises();
        expect(lastParams().sort).toBe('enrollments_count');
    });

    it('debounces the search box into a server q', async () => {
        const wrapper = await mountPage();
        vi.useFakeTimers();
        await wrapper.find('#filter_q').setValue('forklift');
        await vi.advanceTimersByTimeAsync(300);
        vi.useRealTimers();
        await flushPromises();
        expect(lastParams().q).toBe('forklift');
    });

    it('requests the next page from the server', async () => {
        const wrapper = await mountPage();
        await wrapper.find('button[aria-label="Next page"]').trigger('click');
        await flushPromises();
        expect(lastParams().page).toBe(2);
    });
});
