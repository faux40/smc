import { flushPromises, mount } from '@vue/test-utils';
import axios from 'axios';
import { createPinia, setActivePinia } from 'pinia';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import ClassFormModal from '@/pages/classes/Partials/ClassFormModal.vue';
import type { ClassDetail } from '@/stores/classes';
import { useErrorStore } from '@/stores/errors';

vi.mock('axios');

const FORM_CTX = 'form:class';

describe('ClassFormModal create validation', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        vi.clearAllMocks();
        (axios.get as ReturnType<typeof vi.fn>).mockResolvedValue({ data: [] });
        (axios.post as ReturnType<typeof vi.fn>).mockResolvedValue({
            data: { id: 'c1', name: 'X', trainings: [], enrollments: [] },
        });
    });

    afterEach(() => {
        document.body.innerHTML = '';
    });

    async function openCreate() {
        const wrapper = mount(ClassFormModal, {
            props: { open: false },
            attachTo: document.body,
        });
        await wrapper.setProps({ open: true });
        await flushPromises();

        return wrapper;
    }

    function clickCreate() {
        const btn = Array.from(
            document.body.querySelectorAll<HTMLButtonElement>('button'),
        ).find((b) => b.textContent?.trim() === 'Create');
        btn?.click();
    }

    it('shows field errors and does NOT post when required fields are blank', async () => {
        await openCreate();

        clickCreate();
        await flushPromises();

        const errors = useErrorStore();
        expect(errors.getFieldError(FORM_CTX, 'name')).toBeTruthy();
        expect(errors.getFieldError(FORM_CTX, 'scheduled_date')).toBeTruthy();
        expect(axios.post).not.toHaveBeenCalled();
    });

    it('seeds the class name from presetName on open (assemble-a-class flow)', async () => {
        const wrapper = mount(ClassFormModal, {
            props: {
                open: false,
                presetTrainingIds: ['t1'],
                presetName: 'Fall Protection',
            },
            attachTo: document.body,
        });
        await wrapper.setProps({ open: true });
        await flushPromises();

        const seeded =
            document.body.querySelector<HTMLInputElement>('#class_name');
        expect(seeded?.value).toBe('Fall Protection');
    });

    it('posts once name + date are provided', async () => {
        await openCreate();

        const name =
            document.body.querySelector<HTMLInputElement>('#class_name');
        const date =
            document.body.querySelector<HTMLInputElement>('#class_date');
        name!.value = 'Fall Protection';
        name!.dispatchEvent(new Event('input', { bubbles: true }));
        date!.value = '2026-09-01';
        date!.dispatchEvent(new Event('input', { bubbles: true }));
        await flushPromises();

        clickCreate();
        await flushPromises();

        expect(axios.post).toHaveBeenCalledWith(
            '/api/classes',
            expect.objectContaining({
                name: 'Fall Protection',
                scheduled_date: '2026-09-01',
            }),
            expect.anything(),
        );
    });

    it('sends the reference student counts when filled', async () => {
        await openCreate();

        const set = (sel: string, value: string) => {
            const el = document.body.querySelector<HTMLInputElement>(sel)!;
            el.value = value;
            el.dispatchEvent(new Event('input', { bubbles: true }));
        };
        set('#class_name', 'Counted');
        set('#class_date', '2026-09-01');
        set('#class_min_students', '5');
        set('#class_max_students', '20');
        await flushPromises();

        clickCreate();
        await flushPromises();

        expect(axios.post).toHaveBeenCalledWith(
            '/api/classes',
            expect.objectContaining({ min_students: 5, max_students: 20 }),
            expect.anything(),
        );
    });

    it('the form opts out of silent native validation (novalidate)', async () => {
        await openCreate();
        const form = document.body.querySelector('form');
        expect(form?.hasAttribute('novalidate')).toBe(true);
    });
});

// Duplicate-class mode — the modal seeded from an existing class (Actions →
// Duplicate on the detail page).
const topic = (
    id: string,
    trainingId: string | null,
    name: string,
): ClassDetail['trainings'][number] => ({
    id,
    training_id: trainingId,
    training_name: name,
    initial_only: false,
    repeating: true,
    as_needed: false,
    std_freq_name: 'Annual',
    repeat_days: 365,
    hours: '4.00',
    cert_title: null,
    cert_text: null,
    cert_code: null,
    credits: [],
});

const sourceClass: ClassDetail = {
    id: 'c9',
    name: 'Fall Protection — Spring',
    scheduled_date: '2026-03-01',
    start_time: '08:00',
    end_time: '12:00',
    location: 'Yard 3',
    address: '450 Ryder St',
    instructor: 'J. Cole',
    show_signature: true,
    total_hours: '4.00',
    min_students: 5,
    max_students: 20,
    notes: 'Bring harnesses.',
    status: 'completed',
    completion_date: '2026-03-01',
    can_edit: false,
    trainings: [
        topic('ct1', 't1', 'Fall Protection'),
        topic('ct2', null, 'Orphan Topic'),
    ],
    enrollments: [
        {
            id: 'e1',
            user_id: 'u1',
            user_name: 'Dana Reed',
            user_sort_name: 'Reed, Dana',
            user_email: 'dana@demo.local',
            status: 'passed',
            notes: null,
            credited_training_ids: [],
            results: {},
        },
        {
            id: 'e2',
            user_id: 'u2',
            user_name: 'Sam Lee',
            user_sort_name: 'Lee, Sam',
            user_email: 'sam@demo.local',
            status: 'passed',
            notes: null,
            credited_training_ids: [],
            results: {},
        },
    ],
};

describe('ClassFormModal duplicate mode', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        vi.clearAllMocks();
        (axios.get as ReturnType<typeof vi.fn>).mockResolvedValue({
            data: [{ id: 't1', name: 'Fall Protection', default_hours: 4 }],
        });
        (axios.post as ReturnType<typeof vi.fn>).mockResolvedValue({
            data: { id: 'c10', name: 'X', trainings: [], enrollments: [] },
        });
    });

    afterEach(() => {
        document.body.innerHTML = '';
    });

    async function openDuplicate(copyFrom: ClassDetail = sourceClass) {
        const wrapper = mount(ClassFormModal, {
            props: { open: false, copyFrom },
            attachTo: document.body,
        });
        await wrapper.setProps({ open: true });
        await flushPromises();

        return wrapper;
    }

    function submitForm() {
        document.body
            .querySelector('form')!
            .dispatchEvent(new Event('submit', { bubbles: true }));
    }

    it('titles the dialog Duplicate class and seeds the fields, date blank', async () => {
        await openDuplicate();

        expect(document.body.textContent).toContain('Duplicate class');

        const value = (sel: string) =>
            document.body.querySelector<HTMLInputElement>(sel)?.value;
        expect(value('#class_name')).toBe('Fall Protection — Spring');
        // A copy is a NEW session — the date must be chosen, not inherited.
        expect(value('#class_date')).toBe('');
        expect(value('#class_start_time')).toBe('08:00');
        expect(value('#class_location')).toBe('Yard 3');
        expect(value('#class_instructor')).toBe('J. Cole');
        expect(value('#class_min_students')).toBe('5');
        expect(value('#class_max_students')).toBe('20');
    });

    it('prepends a clearable "Copied from" line to the notes', async () => {
        await openDuplicate();

        const notes =
            document.body.querySelector<HTMLTextAreaElement>('#class_notes');
        expect(notes?.value).toContain(
            'Copied from "Fall Protection — Spring" (2026-03-01)',
        );
        // The source's own notes survive below the marker line.
        expect(notes?.value).toContain('Bring harnesses.');
    });

    it('pre-checks the copyable trainings and flags the uncopyable topic', async () => {
        await openDuplicate();

        const check = document.body.querySelector<HTMLInputElement>(
            'input[type="checkbox"][value="t1"]',
        );
        expect(check?.checked).toBe(true);
        // ct2 has no live training to snapshot from — it is named, not silently dropped.
        expect(document.body.textContent).toContain('Orphan Topic');
    });

    it('sends training_ids + user_ids (include-students defaults on)', async () => {
        await openDuplicate();

        const date =
            document.body.querySelector<HTMLInputElement>('#class_date')!;
        date.value = '2026-09-01';
        date.dispatchEvent(new Event('input', { bubbles: true }));
        await flushPromises();

        const include = document.body.querySelector(
            '[data-testid="copy-include-students"]',
        );
        expect(include).not.toBeNull();

        submitForm();
        await flushPromises();

        expect(axios.post).toHaveBeenCalledWith(
            '/api/classes',
            expect.objectContaining({
                name: 'Fall Protection — Spring',
                scheduled_date: '2026-09-01',
                training_ids: ['t1'],
                user_ids: ['u1', 'u2'],
            }),
            expect.anything(),
        );
    });

    it('omits user_ids when include-students is unchecked', async () => {
        const wrapper = await openDuplicate();

        const date =
            document.body.querySelector<HTMLInputElement>('#class_date')!;
        date.value = '2026-09-01';
        date.dispatchEvent(new Event('input', { bubbles: true }));

        const include = document.body.querySelector<HTMLElement>(
            '[data-testid="copy-include-students"]',
        )!;
        include.click();
        await flushPromises();

        submitForm();
        await flushPromises();

        const payload = (axios.post as ReturnType<typeof vi.fn>).mock
            .calls[0][1] as Record<string, unknown>;
        expect(payload.user_ids).toBeUndefined();
        wrapper.unmount();
    });

    it('offers no include-students control when the source has nobody enrolled', async () => {
        await openDuplicate({ ...sourceClass, enrollments: [] });

        expect(
            document.body.querySelector(
                '[data-testid="copy-include-students"]',
            ),
        ).toBeNull();
    });
});
