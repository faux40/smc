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
        was_completed: false,
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
                expire_date: null,
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
                expire_date: null,
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

const dateInput = (): HTMLInputElement =>
    document.body.querySelector<HTMLInputElement>('#complete_date')!;

const expiryInput = (topicId: string): HTMLInputElement =>
    document.body.querySelector<HTMLInputElement>(
        `[data-testid="complete-expire-${topicId}"]`,
    )!;

function setValue(el: HTMLInputElement, value: string): void {
    el.value = value;
    el.dispatchEvent(new Event('input'));
}

const setDate = (value: string) => setValue(dateInput(), value);
const setExpiry = (topicId: string, value: string) =>
    setValue(expiryInput(topicId), value);

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
            trainings: [
                { id: 'ct1', expire_date: null },
                { id: 'ct2', expire_date: null },
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
            trainings: [
                { id: 'ct1', expire_date: null },
                { id: 'ct2', expire_date: null },
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
            trainings: [
                { id: 'ct1', expire_date: null },
                { id: 'ct2', expire_date: null },
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
            trainings: [
                { id: 'ct1', expire_date: null },
                { id: 'ct2', expire_date: null },
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
        target.was_completed = true; // closed once → this is a re-close
        target.enrollments[0].status = 'enrolled'; // freshly added back in

        await openWith(target);

        expect(document.body.textContent).toContain('new');
    });

    it('does not call everyone new just because a date was entered early', async () => {
        // `completion_date` is editable before close-out now, so it can no
        // longer stand in for "this class has been closed before".
        const target = detail();
        target.completion_date = '2026-06-03';
        target.was_completed = false;

        await openWith(target);

        expect(document.body.textContent).not.toContain('new');
    });

    describe('the completion date', () => {
        it('offers the one already recorded on the class', async () => {
            const target = detail();
            target.completion_date = '2026-06-03';

            await openWith(target);

            expect(dateInput().value).toBe('2026-06-03');
        });

        it('falls back to the scheduled date when none was recorded', async () => {
            await openWith(detail());

            expect(dateInput().value).toBe('2026-06-01');
        });
    });

    describe('per-topic expiry', () => {
        it('offers what each topic would derive, and sends it', async () => {
            const store = useClassesStore();
            const complete = vi
                .spyOn(store, 'complete')
                .mockResolvedValue(detail());
            const target = detail();
            target.trainings[0].repeat_days = 365;
            target.trainings[1].repeating = false;

            await openWith(target);

            expect(expiryInput('ct1').value).toBe('2027-06-01');
            expect(expiryInput('ct2').value).toBe('');

            submitForm();
            await flushPromises();

            expect(complete.mock.calls[0][1].trainings).toEqual([
                { id: 'ct1', expire_date: '2027-06-01' },
                { id: 'ct2', expire_date: null },
            ]);
        });

        it('offers a hand-set expiry over the derived one', async () => {
            const target = detail();
            target.trainings[0].repeat_days = 365;
            target.trainings[0].expire_date = '2029-07-15';

            await openWith(target);

            // Set deliberately on the class detail — close-out must confirm
            // it, not silently recompute over the top of it.
            expect(expiryInput('ct1').value).toBe('2029-07-15');
        });

        it('re-derives an untouched expiry when the completion date moves', async () => {
            const target = detail();
            target.trainings[0].repeat_days = 365;

            await openWith(target);
            expect(expiryInput('ct1').value).toBe('2027-06-01');

            setDate('2026-06-08');
            await flushPromises();

            expect(expiryInput('ct1').value).toBe('2027-06-08');
        });

        it('leaves an edited expiry alone when the completion date moves', async () => {
            const target = detail();
            target.trainings[0].repeat_days = 365;

            await openWith(target);
            setExpiry('ct1', '2029-07-15');
            await flushPromises();

            setDate('2026-06-08');
            await flushPromises();

            // Someone typed it; recomputing over it would discard a decision.
            expect(expiryInput('ct1').value).toBe('2029-07-15');
        });

        it('keeps a hand-set expiry when the completion date moves', async () => {
            const target = detail();
            target.trainings[0].repeat_days = 365;
            target.trainings[0].expire_date = '2029-07-15';

            await openWith(target);
            setDate('2026-06-08');
            await flushPromises();

            expect(expiryInput('ct1').value).toBe('2029-07-15');
        });
    });
});
