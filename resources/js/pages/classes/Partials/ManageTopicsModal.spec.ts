import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import ManageTopicsModal from '@/pages/classes/Partials/ManageTopicsModal.vue';
import type { ClassDetail } from '@/stores/classes';
import { useClassesStore } from '@/stores/classes';
import { useTrainingsStore } from '@/stores/trainings';

const { success } = vi.hoisted(() => ({ success: vi.fn() }));
vi.mock('vue-sonner', () => ({ toast: { success, error: vi.fn() } }));

function detail(over: Partial<ClassDetail> = {}): ClassDetail {
    return {
        id: 'c1',
        name: 'Spring session',
        scheduled_date: '2026-09-01',
        start_time: null,
        end_time: null,
        location: null,
        address: null,
        instructor: null,
        show_signature: false,
        total_hours: null,
        min_students: null,
        max_students: null,
        notes: null,
        status: 'scheduled',
        completion_date: null,
        was_completed: false,
        can_edit: true,
        tag_ids: [],
        trainings: [],
        enrollments: [],
        ...over,
    };
}

describe('ManageTopicsModal — inherited tags are announced', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        vi.clearAllMocks();
        useTrainingsStore().library = [
            {
                id: 't1',
                name: 'Forklift',
                default_hours: '4.00',
            } as unknown as ReturnType<
                typeof useTrainingsStore
            >['library'][number],
        ];
    });

    afterEach(() => {
        document.body.innerHTML = '';
    });

    function mountModal() {
        const store = useClassesStore();
        store.detail['c1'] = detail();

        const wrapper = mount(ManageTopicsModal, {
            props: { open: true, classId: 'c1' },
            attachTo: document.body,
        });

        return { wrapper, store };
    }

    async function clickAdd() {
        const add = document.body.querySelector<HTMLButtonElement>(
            'button[aria-label="Add"]',
        );
        add?.dispatchEvent(new Event('click', { bubbles: true }));
        await flushPromises();
    }

    it('names the tags a new topic brought with it', async () => {
        // Adding a topic silently widens the class's tag set. Saying so is the
        // difference between a feature and a surprise.
        const { wrapper, store } = mountModal();
        store.attachTraining = vi
            .fn()
            .mockResolvedValue(detail({ tag_ids: ['tag-a', 'tag-b'] }));
        await flushPromises();

        await clickAdd();

        expect(success).toHaveBeenCalledWith(
            expect.stringContaining('2 tags inherited'),
        );
        wrapper.unmount();
    });

    it('says nothing about tags when the topic brought none', async () => {
        const { wrapper, store } = mountModal();
        store.attachTraining = vi.fn().mockResolvedValue(detail());
        await flushPromises();

        await clickAdd();

        expect(success).toHaveBeenCalledWith(
            expect.not.stringContaining('inherited'),
        );
        wrapper.unmount();
    });

    it('counts only the tags that are actually new to the class', async () => {
        // A tag the class already carried is not "inherited" — announcing it
        // would overstate what changed.
        const { wrapper, store } = mountModal();
        store.detail['c1'] = detail({ tag_ids: ['tag-a'] });
        store.attachTraining = vi
            .fn()
            .mockResolvedValue(detail({ tag_ids: ['tag-a', 'tag-b'] }));
        await flushPromises();

        await clickAdd();

        expect(success).toHaveBeenCalledWith(
            expect.stringContaining('1 tag inherited'),
        );
        wrapper.unmount();
    });
});
