import { flushPromises, mount } from '@vue/test-utils';
import axios from 'axios';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import AttachmentsList from '@/components/AttachmentsList.vue';
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
    start_time: null,
    end_time: null,
    location: 'Yard 3',
    address: null,
    instructor: 'J. Cole',
    show_signature: false,
    total_hours: '4.00',
    notes: null,
    status: 'scheduled',
    completion_date: null,
    can_edit: true,
    trainings: [],
    enrollments: [
        {
            id: 'e1',
            user_id: 'u1',
            user_name: 'Dana Reed',
            user_email: 'dana.reed@demo.local',
            status: 'enrolled',
            notes: null,
            credited_training_ids: [],
        },
    ],
};

function mockGet() {
    (axios.get as ReturnType<typeof vi.fn>).mockImplementation((url: string) => {
        if (url === '/api/classes/c1') {
            return Promise.resolve({ data: detail });
        }

        if (
            url === '/api/trainings' ||
            url === '/api/tags' ||
            url === '/api/attachments'
        ) {
            return Promise.resolve({ data: [] });
        }

        if (url === '/api/users') {
            return Promise.resolve({
                data: [
                    { id: 'u1', f_name: 'Dana', l_name: 'Reed', email: 'dana.reed@demo.local' },
                    { id: 'u2', f_name: 'Sam', l_name: 'Lee', email: 'sam.lee@demo.local' },
                ],
            });
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
            expect.objectContaining({ name: 'Renamed' }),
            expect.anything(),
        );
    });

    it('shows the sign-in sheet link but gates certificates/summary to completed', async () => {
        const wrapper = await mountShow();
        const hrefs = wrapper
            .findAll('a')
            .map((a) => a.attributes('href') ?? '');

        expect(hrefs).toContain('/api/classes/c1/sign-in-sheet');
        // Scheduled class → no certificate / summary links yet.
        expect(hrefs).not.toContain('/api/classes/c1/certificates');
        expect(hrefs).not.toContain('/api/classes/c1/summary');
    });

    it('mounts AttachmentsList wired to this class as the morphable', async () => {
        const wrapper = await mountShow();

        const attachments = wrapper.findComponent(AttachmentsList);
        expect(attachments.exists()).toBe(true);
        expect(attachments.props('morphableType')).toBe(
            'App\\Models\\TrainingClass',
        );
        expect(attachments.props('morphableId')).toBe('c1');
    });

    it('lists the enrolled student name in the right-hand roster column', async () => {
        const wrapper = await mountShow();

        // Compact list shows the name; the email/picker lives in the modal.
        expect(wrapper.text()).toContain('Dana Reed');
    });

    it('shows compact topic names with hours inside the details card', async () => {
        // Add a topic to the detail used by the mock.
        detail.trainings = [
            {
                id: 'ct1',
                training_id: 't1',
                training_name: 'Fall Protection',
                initial_only: false,
                repeating: true,
                as_needed: false,
                std_freq_name: 'Annual',
                repeat_days: 365,
                hours: '4.00',
            },
        ];
        const wrapper = await mountShow();
        expect(wrapper.text()).toContain('Fall Protection');
        expect(wrapper.text()).toContain('(4h)');
        detail.trainings = []; // restore for other tests
    });
});
