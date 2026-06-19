import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import axios from 'axios';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { useClassesStore } from '@/stores/classes';
import ClassCertEditModal from './ClassCertEditModal.vue';

vi.mock('axios');
vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: { org: { name: 'Test Org' } } }),
}));

const topic = {
    id: 'ct1',
    training_id: 't1',
    training_name: 'Fall Protection',
    initial_only: false,
    repeating: true,
    as_needed: false,
    std_freq_name: null,
    repeat_days: null,
    hours: '4.00',
    cert_title: 'Snapshotted Title',
    cert_text: 'Snapshotted **text**',
    cert_code: 'OLD',
    lifespan_months: 12,
    credits: [],
};

function seedStore() {
    const store = useClassesStore();
    store.detail['c1'] = {
        id: 'c1',
        name: 'Class 1',
        scheduled_date: null,
        start_time: null,
        end_time: null,
        location: null,
        address: null,
        instructor: null,
        show_signature: false,
        total_hours: '4.00',
        notes: null,
        status: 'scheduled',
        completion_date: null,
        can_edit: true,
        trainings: [topic],
        enrollments: [],
    };
    return store;
}

describe('ClassCertEditModal', () => {
    enableAutoUnmount(afterEach);

    beforeEach(() => {
        setActivePinia(createPinia());
        vi.clearAllMocks();
        document.body.innerHTML = '';
    });

    // The Dialog teleports its content to document.body, so query the DOM
    // directly and drive native events (Vue reactivity still applies).
    function input(selector: string): HTMLInputElement {
        const el = document.body.querySelector(selector);
        if (!el) {
            throw new Error(`missing ${selector}`);
        }
        return el as HTMLInputElement;
    }

    function setInput(selector: string, value: string) {
        const el = input(selector);
        el.value = value;
        el.dispatchEvent(new Event('input', { bubbles: true }));
    }

    it('seeds the form from the topic snapshot when opened', async () => {
        seedStore();
        mount(ClassCertEditModal, {
            props: { open: true, classId: 'c1', topicId: 'ct1' },
            attachTo: document.body,
        });
        await flushPromises();

        expect(input('#cert_title').value).toBe('Snapshotted Title');
    });

    it('PATCHes the per-class cert fields and closes on save', async () => {
        const store = seedStore();
        (axios.patch as ReturnType<typeof vi.fn>).mockResolvedValue({
            data: store.detail['c1'],
        });

        const wrapper = mount(ClassCertEditModal, {
            props: { open: true, classId: 'c1', topicId: 'ct1' },
            attachTo: document.body,
        });
        await flushPromises();

        setInput('#cert_title', 'Per-class Title');
        await flushPromises();
        (document.body.querySelector('form') as HTMLFormElement).dispatchEvent(
            new Event('submit', { bubbles: true, cancelable: true }),
        );
        await flushPromises();

        expect(axios.patch).toHaveBeenCalledWith(
            '/api/classes/c1/trainings/ct1',
            {
                cert_title: 'Per-class Title',
                cert_text: 'Snapshotted **text**',
                cert_code: 'OLD',
                lifespan_months: 12,
            },
            expect.anything(),
        );
        expect(wrapper.emitted('update:open')?.at(-1)).toEqual([false]);
    });
});
