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
            { id: 'ct1', training_id: 't1', training_name: 'First Aid', initial_only: false, repeating: true, as_needed: false, std_freq_name: null, repeat_days: null, hours: null, cert_title: null, cert_text: null, cert_code: null, lifespan_months: null, credits: [] },
            { id: 'ct2', training_id: 't2', training_name: 'Fall Protection', initial_only: false, repeating: true, as_needed: false, std_freq_name: null, repeat_days: null, hours: null, cert_title: null, cert_text: null, cert_code: null, lifespan_months: null, credits: [] },
        ],
        enrollments: [
            { id: 'e1', user_id: 'u1', user_name: 'John Doe', user_email: null, status: 'enrolled', notes: null, credited_training_ids: [] },
        ],
    };
}

async function openWith(target: ClassDetail) {
    const wrapper = mount(ClassCompleteModal, {
        props: { open: false, target },
        attachTo: document.body,
    });
    await wrapper.setProps({ open: true });
    await flushPromises();

    return wrapper;
}

function buttonsByText(text: string): HTMLButtonElement[] {
    return Array.from(
        document.body.querySelectorAll<HTMLButtonElement>('button'),
    ).filter((b) => b.textContent?.trim() === text);
}

const isActive = (b: HTMLButtonElement) =>
    /bg-emerald-100|bg-red-100/.test(b.className);

describe('ClassCompleteModal', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        vi.clearAllMocks();
    });

    afterEach(() => {
        document.body.innerHTML = '';
    });

    it('starts every topic unmarked (neither pass nor fail active)', async () => {
        await openWith(detail());

        const passes = buttonsByText('Pass');
        const fails = buttonsByText('Fail');
        expect(passes).toHaveLength(2);
        expect(fails).toHaveLength(2);
        expect([...passes, ...fails].some(isActive)).toBe(false);
    });

    it('"Mark all passed" passes every topic and submits them all', async () => {
        const store = useClassesStore();
        const complete = vi.spyOn(store, 'complete').mockResolvedValue(detail());

        await openWith(detail());
        buttonsByText('Mark all passed')[0].click();
        await flushPromises();
        expect(buttonsByText('Pass').every(isActive)).toBe(true);

        document.body
            .querySelector('form')!
            .dispatchEvent(new Event('submit', { cancelable: true, bubbles: true }));
        await flushPromises();

        expect(complete).toHaveBeenCalledWith('c1', {
            completion_date: '2026-06-01',
            enrollments: [
                {
                    id: 'e1',
                    notes: null,
                    results: [
                        { class_training_id: 'ct1', passed: true },
                        { class_training_id: 'ct2', passed: true },
                    ],
                },
            ],
        });
    });

    it('submits only marked topics; unmarked ones are omitted', async () => {
        const store = useClassesStore();
        const complete = vi.spyOn(store, 'complete').mockResolvedValue(detail());

        await openWith(detail());
        // Mark only First Aid (ct1) passed; leave Fall Protection unmarked.
        buttonsByText('Pass')[0].click();
        await flushPromises();

        document.body
            .querySelector('form')!
            .dispatchEvent(new Event('submit', { cancelable: true, bubbles: true }));
        await flushPromises();

        expect(complete).toHaveBeenCalledWith('c1', {
            completion_date: '2026-06-01',
            enrollments: [
                {
                    id: 'e1',
                    notes: null,
                    results: [{ class_training_id: 'ct1', passed: true }],
                },
            ],
        });
    });

    it('re-clicking an active chip toggles it back to unmarked', async () => {
        await openWith(detail());

        const firstPass = buttonsByText('Pass')[0];
        firstPass.click();
        await flushPromises();
        expect(isActive(firstPass)).toBe(true);

        firstPass.click();
        await flushPromises();
        expect(isActive(buttonsByText('Pass')[0])).toBe(false);
    });

    it('flags a freshly-added enrollee on a re-opened class', async () => {
        const target = detail();
        target.completion_date = '2026-06-01'; // previously completed → re-close
        target.enrollments[0].status = 'enrolled'; // freshly added back in

        await openWith(target);

        expect(document.body.textContent).toContain('new');
    });
});
