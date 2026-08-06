import { flushPromises, mount } from '@vue/test-utils';
import axios from 'axios';
import { createPinia, setActivePinia } from 'pinia';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import TrainingFormModal from '@/pages/trainings/Partials/TrainingFormModal.vue';
import type { TrainingRow } from '@/stores/trainings';

vi.mock('axios');
vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: { org: { name: 'Test Org' } } }),
}));

function target(overrides: Partial<TrainingRow> = {}): TrainingRow {
    return {
        id: 't1',
        name: 'Fall Protection',
        nickname: 'FallPro',
        description: null,
        initial_only: true,
        repeating: false,
        std_freq_id: null,
        std_freq_name: null,
        std_freq_repeat_days: null,
        as_needed: false,
        default_hours: null,
        cert_title: null,
        cert_text: null,
        cert_code: null,
        card_template_id: null,
        card_stock_id: null,
        default_trainer: null,
        default_location: null,
        default_address: null,
        superseded_by_id: null,
        can_edit: true,
        can_delete: true,
        ...overrides,
    };
}

function clickSave() {
    Array.from(document.body.querySelectorAll<HTMLButtonElement>('button'))
        .find((b) => b.textContent?.trim() === 'Save')
        ?.click();
}

describe('TrainingFormModal — nickname', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        vi.clearAllMocks();
        (axios.get as ReturnType<typeof vi.fn>).mockResolvedValue({ data: [] });
        (axios.post as ReturnType<typeof vi.fn>).mockResolvedValue({
            data: { id: 't1' },
        });
    });

    afterEach(() => {
        document.body.innerHTML = '';
    });

    it('includes nickname in the create payload', async () => {
        const wrapper = mount(TrainingFormModal, {
            props: { open: false, mode: 'create' },
            attachTo: document.body,
        });
        await wrapper.setProps({ open: true });
        await flushPromises();

        const name = document.body.querySelector<HTMLInputElement>('#t_name');
        const nick =
            document.body.querySelector<HTMLInputElement>('#t_nickname');
        name!.value = 'Fall Protection';
        name!.dispatchEvent(new Event('input', { bubbles: true }));
        nick!.value = 'FallPro';
        nick!.dispatchEvent(new Event('input', { bubbles: true }));
        await flushPromises();

        clickSave();
        await flushPromises();

        expect(axios.post).toHaveBeenCalledWith(
            '/api/trainings',
            expect.objectContaining({
                name: 'Fall Protection',
                nickname: 'FallPro',
            }),
            expect.anything(),
        );
    });

    it('sends null nickname when left blank', async () => {
        const wrapper = mount(TrainingFormModal, {
            props: { open: false, mode: 'create' },
            attachTo: document.body,
        });
        await wrapper.setProps({ open: true });
        await flushPromises();

        const name = document.body.querySelector<HTMLInputElement>('#t_name');
        name!.value = 'Fall Protection';
        name!.dispatchEvent(new Event('input', { bubbles: true }));
        await flushPromises();

        clickSave();
        await flushPromises();

        expect(axios.post).toHaveBeenCalledWith(
            '/api/trainings',
            expect.objectContaining({ nickname: null }),
            expect.anything(),
        );
    });

    it('prefills nickname when editing', async () => {
        const wrapper = mount(TrainingFormModal, {
            props: { open: false, mode: 'edit', target: target() },
            attachTo: document.body,
        });
        await wrapper.setProps({ open: true });
        await flushPromises();

        const nick =
            document.body.querySelector<HTMLInputElement>('#t_nickname');
        expect(nick?.value).toBe('FallPro');
    });
});
