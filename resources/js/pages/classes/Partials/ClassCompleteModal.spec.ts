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
        min_students: null,
        max_students: null,
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
                cert_title: null,
                cert_text: null,
                cert_code: null,
                card_fields: [],
                credits: [],
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
                cert_title: null,
                cert_text: null,
                cert_code: null,
                card_fields: [],
                credits: [],
            },
        ],
        enrollments: [
            {
                id: 'e1',
                user_id: 'u1',
                user_name: 'John Allen Doe',
                user_sort_name: 'Doe, John Allen',
                user_email: null,
                status: 'enrolled',
                notes: null,
                credited_training_ids: [],
                results: {},
            },
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

const chip = (testid: string): HTMLButtonElement =>
    document.body.querySelector<HTMLButtonElement>(
        `[data-testid="${testid}"]`,
    )!;

function submitForm(): void {
    document.body
        .querySelector('form')!
        .dispatchEvent(
            new Event('submit', { cancelable: true, bubbles: true }),
        );
}

describe('ClassCompleteModal', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        vi.clearAllMocks();
    });

    afterEach(() => {
        document.body.innerHTML = '';
    });

    it('defaults every topic to incomplete and submits them all', async () => {
        const store = useClassesStore();
        const complete = vi
            .spyOn(store, 'complete')
            .mockResolvedValue(detail());

        await openWith(detail());
        // Incomplete is the active default.
        expect(chip('mark-ct1-incomplete').className).toMatch(/bg-muted/);

        submitForm();
        await flushPromises();

        expect(complete).toHaveBeenCalledWith('c1', {
            completion_date: '2026-06-01',
            enrollments: [
                {
                    id: 'e1',
                    notes: null,
                    results: [
                        { class_training_id: 'ct1', result: 'incomplete' },
                        { class_training_id: 'ct2', result: 'incomplete' },
                    ],
                },
            ],
        });
    });

    it('"Mark all passed" passes every topic', async () => {
        const store = useClassesStore();
        const complete = vi
            .spyOn(store, 'complete')
            .mockResolvedValue(detail());

        await openWith(detail());
        Array.from(document.body.querySelectorAll<HTMLButtonElement>('button'))
            .find((b) => b.textContent?.trim() === 'Mark all passed')!
            .click();
        await flushPromises();

        submitForm();
        await flushPromises();

        expect(complete).toHaveBeenCalledWith('c1', {
            completion_date: '2026-06-01',
            enrollments: [
                {
                    id: 'e1',
                    notes: null,
                    results: [
                        { class_training_id: 'ct1', result: 'pass' },
                        { class_training_id: 'ct2', result: 'pass' },
                    ],
                },
            ],
        });
    });

    it('records an explicit per-topic mix (pass / fail / incomplete)', async () => {
        const store = useClassesStore();
        const complete = vi
            .spyOn(store, 'complete')
            .mockResolvedValue(detail());

        await openWith(detail());
        chip('mark-ct1-pass').click();
        chip('mark-ct2-fail').click();
        await flushPromises();

        submitForm();
        await flushPromises();

        expect(complete).toHaveBeenCalledWith('c1', {
            completion_date: '2026-06-01',
            enrollments: [
                {
                    id: 'e1',
                    notes: null,
                    results: [
                        { class_training_id: 'ct1', result: 'pass' },
                        { class_training_id: 'ct2', result: 'fail' },
                    ],
                },
            ],
        });
    });

    it('pre-fills each topic from the stored results on a re-close', async () => {
        const store = useClassesStore();
        const complete = vi
            .spyOn(store, 'complete')
            .mockResolvedValue(detail());

        const target = detail();
        target.completion_date = '2026-06-01'; // previously completed → re-close
        target.enrollments[0].results = { ct1: 'pass', ct2: 'fail' };

        await openWith(target);
        // Submitting without touching anything preserves the prior outcome.
        submitForm();
        await flushPromises();

        expect(complete).toHaveBeenCalledWith('c1', {
            completion_date: '2026-06-01',
            enrollments: [
                {
                    id: 'e1',
                    notes: null,
                    results: [
                        { class_training_id: 'ct1', result: 'pass' },
                        { class_training_id: 'ct2', result: 'fail' },
                    ],
                },
            ],
        });
    });

    it('labels attendees last-name-first and keeps the served roster order', async () => {
        const target = detail();
        target.enrollments.push({
            id: 'e2',
            user_id: 'u2',
            user_name: 'Sandra Earle',
            user_sort_name: 'Earle, Sandra',
            user_email: null,
            status: 'enrolled',
            notes: null,
            credited_training_ids: [],
            results: {},
        });

        await openWith(target);

        const names = Array.from(
            document.body.querySelectorAll('[data-testid="attendee-name"]'),
        ).map((el) => el.textContent?.trim());

        // Sorted server-side; the modal renders that order verbatim.
        expect(names).toEqual(['Doe, John Allen', 'Earle, Sandra']);
    });

    it('flags a freshly-added enrollee on a re-opened class', async () => {
        const target = detail();
        target.completion_date = '2026-06-01'; // previously completed → re-close
        target.enrollments[0].status = 'enrolled'; // freshly added back in

        await openWith(target);

        expect(document.body.textContent).toContain('new');
    });
});
