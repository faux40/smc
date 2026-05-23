import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import ClassCompleteModal from '@/pages/classes/Partials/ClassCompleteModal.vue';
import { useClassesStore } from '@/stores/classes';
import type { ClassDetail } from '@/stores/classes';

function detail(): ClassDetail {
    return {
        id: 'c1',
        name: 'Combined Safety',
        scheduled_date: '2026-06-01',
        start_time: null,
        end_time: null,
        location: null,
        address: null,
        instructor: null,
        show_signature: false,
        total_hours: null,
        notes: null,
        status: 'scheduled',
        completion_date: null,
        can_edit: true,
        trainings: [
            {
                id: 'ct1',
                training_id: 't1',
                training_name: 'First Aid',
                initial_only: false,
                repeating: true,
                as_needed: false,
                std_freq_name: null,
                repeat_days: null,
                hours: null,
            },
            {
                id: 'ct2',
                training_id: 't2',
                training_name: 'Fall Protection',
                initial_only: false,
                repeating: true,
                as_needed: false,
                std_freq_name: null,
                repeat_days: null,
                hours: null,
            },
        ],
        enrollments: [
            {
                id: 'e1',
                user_id: 'u1',
                user_name: 'John Doe',
                user_email: null,
                status: 'enrolled',
                notes: null,
            },
        ],
    };
}

async function openModal() {
    const target = detail();
    const wrapper = mount(ClassCompleteModal, {
        props: { open: false, target },
        attachTo: document.body,
    });
    await wrapper.setProps({ open: true }); // triggers the init watch
    await flushPromises();

    return wrapper;
}

function topicButtons() {
    return Array.from(
        document.body.querySelectorAll<HTMLButtonElement>('button'),
    ).filter((b) => /First Aid|Fall Protection/.test(b.textContent ?? ''));
}

describe('ClassCompleteModal', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        vi.clearAllMocks();
    });

    afterEach(() => {
        document.body.innerHTML = '';
    });

    it('renders a pass toggle per training, defaulting to passed', async () => {
        await openModal();

        const btns = topicButtons();
        expect(btns).toHaveLength(2);
        // Default: every topic passed (✓).
        expect(btns.every((b) => b.textContent?.includes('✓'))).toBe(true);
    });

    it('submits a per-training result matrix; a flipped topic is failed', async () => {
        const store = useClassesStore();
        const complete = vi
            .spyOn(store, 'complete')
            .mockResolvedValue(detail());

        await openModal();

        // Fail First Aid only.
        const firstAid = topicButtons().find((b) =>
            b.textContent?.includes('First Aid'),
        )!;
        firstAid.click();
        await flushPromises();

        document.body.querySelector('form')!.dispatchEvent(
            new Event('submit', { cancelable: true, bubbles: true }),
        );
        await flushPromises();

        expect(complete).toHaveBeenCalledWith('c1', {
            completion_date: '2026-06-01',
            enrollments: [
                {
                    id: 'e1',
                    notes: null,
                    results: [
                        { class_training_id: 'ct1', passed: false },
                        { class_training_id: 'ct2', passed: true },
                    ],
                },
            ],
        });
    });
});
