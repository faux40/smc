import { flushPromises, mount } from '@vue/test-utils';
import axios from 'axios';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import Show from '@/pages/classes/Show.vue';
import type { ClassDetail } from '@/stores/classes';

vi.mock('axios');
vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<div><slot /></div>' },
    usePage: () => ({ props: { auth: { user: { org_id: 'org1' } } } }),
}));
vi.mock('@/routes/classes', () => ({ page: () => ({ url: '/classes' }) }));

const detail: ClassDetail = {
    id: 'c1',
    name: 'Fall Protection',
    scheduled_date: '2026-06-01',
    location: 'Yard 3',
    instructor: 'J. Cole',
    total_hours: '4.00',
    notes: null,
    status: 'scheduled',
    completion_date: null,
    can_edit: true,
    trainings: [],
    enrollments: [],
};

function mockGet() {
    (axios.get as ReturnType<typeof vi.fn>).mockImplementation((url: string) => {
        if (url === '/api/classes/c1') {
return Promise.resolve({ data: detail });
}

        if (url === '/api/trainings') {
return Promise.resolve({ data: [] });
}

        if (url === '/api/users') {
return Promise.resolve({ data: [] });
}

        return Promise.reject(new Error(`unexpected GET ${url}`));
    });
}

async function mountShow() {
    const wrapper = mount(Show, { props: { classId: 'c1' } });
    await flushPromises();

    return wrapper;
}

describe('classes/Show inline edit', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        vi.clearAllMocks();
        mockGet();
    });

    it('renders the editable details form prefilled, Save disabled until dirty', async () => {
        const wrapper = await mountShow();

        const name = wrapper.find<HTMLInputElement>('#edit_name');
        expect(name.exists()).toBe(true);
        expect(name.element.value).toBe('Fall Protection');

        const save = wrapper
            .findAll('button')
            .find((b) => b.text() === 'Save changes')!;
        expect(save.attributes('disabled')).toBeDefined();
    });

    it('enables Save when a field changes and PATCHes the payload', async () => {
        (axios.patch as ReturnType<typeof vi.fn>).mockResolvedValue({
            data: { ...detail, name: 'Renamed' },
        });
        const wrapper = await mountShow();

        await wrapper.find('#edit_name').setValue('Renamed');
        const save = wrapper
            .findAll('button')
            .find((b) => b.text() === 'Save changes')!;
        expect(save.attributes('disabled')).toBeUndefined();

        await wrapper.find('form').trigger('submit');
        await flushPromises();

        expect(axios.patch).toHaveBeenCalledWith(
            '/api/classes/c1',
            expect.objectContaining({ name: 'Renamed', total_hours: 4 }),
            expect.anything(),
        );
    });
});
