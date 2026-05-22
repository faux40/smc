import { flushPromises, mount } from '@vue/test-utils';
import axios from 'axios';
import { createPinia, setActivePinia } from 'pinia';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import ClassFormModal from '@/pages/classes/Partials/ClassFormModal.vue';
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

    it('posts once name + date are provided', async () => {
        await openCreate();

        const name = document.body.querySelector<HTMLInputElement>('#class_name');
        const date = document.body.querySelector<HTMLInputElement>('#class_date');
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

    it('submits a numeric total_hours without throwing (number-input coercion)', async () => {
        await openCreate();

        const name = document.body.querySelector<HTMLInputElement>('#class_name');
        const date = document.body.querySelector<HTMLInputElement>('#class_date');
        const hours = document.body.querySelector<HTMLInputElement>('#class_hours');
        name!.value = 'Class';
        name!.dispatchEvent(new Event('input', { bubbles: true }));
        date!.value = '2026-09-01';
        date!.dispatchEvent(new Event('input', { bubbles: true }));
        // type="number" → Vue v-model yields a number; the old code did
        // `.trim()` on it and threw, killing submit before the POST.
        hours!.value = '4';
        hours!.dispatchEvent(new Event('input', { bubbles: true }));
        await flushPromises();

        clickCreate();
        await flushPromises();

        expect(axios.post).toHaveBeenCalledWith(
            '/api/classes',
            expect.objectContaining({ total_hours: 4 }),
            expect.anything(),
        );
    });

    it('the form opts out of silent native validation (novalidate)', async () => {
        await openCreate();
        const form = document.body.querySelector('form');
        expect(form?.hasAttribute('novalidate')).toBe(true);
    });
});
