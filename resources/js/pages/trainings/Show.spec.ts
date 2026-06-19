import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils';
import axios from 'axios';
import { createPinia, setActivePinia } from 'pinia';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { TrainingFormSource } from '@/lib/trainingForm';
import Show from '@/pages/trainings/Show.vue';

const visit = vi.fn();

vi.mock('axios');
vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<div><slot /></div>' },
    Link: { template: '<a :href="href"><slot /></a>', props: ['href'] },
    usePage: () => ({ props: { auth: { user: { isAdmin: true } } } }),
    router: { visit: (...args: unknown[]) => visit(...args) },
}));
vi.mock('@/routes/trainings', () => ({ page: () => ({ url: '/trainings' }) }));

const training: TrainingFormSource & { id: string } = {
    id: 't1',
    name: 'Fall Protection',
    nickname: null,
    description: null,
    initial_only: false,
    repeating: false,
    std_freq_id: null,
    as_needed: true,
    default_hours: null,
    cert_title: 'FP Authorized',
    cert_text: null,
    lifespan_months: null,
    cert_code: null,
    default_trainer: null,
    default_location: null,
    default_address: null,
};

describe('trainings/Show', () => {
    enableAutoUnmount(afterEach);

    beforeEach(() => {
        setActivePinia(createPinia());
        vi.clearAllMocks();
        document.body.innerHTML = '';
        // std-frequencies load (TrainingFields onMounted) + any GET.
        (axios.get as ReturnType<typeof vi.fn>).mockResolvedValue({ data: [] });
    });

    it('Save is disabled until a field changes, then PATCHes the payload', async () => {
        (axios.patch as ReturnType<typeof vi.fn>).mockResolvedValue({
            data: { ...training, cert_title: 'New Title' },
        });

        const wrapper = mount(Show, { props: { training } });
        await flushPromises();

        const saveBtn = wrapper
            .findAll('button')
            .find((b) => b.text().includes('Save changes'))!;
        expect(saveBtn.attributes('disabled')).toBeDefined();

        await wrapper.get('#t_cert_title').setValue('New Title');
        expect(saveBtn.attributes('disabled')).toBeUndefined();

        await wrapper.get('form').trigger('submit');
        await flushPromises();

        expect(axios.patch).toHaveBeenCalledWith(
            '/api/trainings/t1',
            expect.objectContaining({ cert_title: 'New Title' }),
            expect.anything(),
        );
    });

    it('confirming the delete dialog DELETEs and redirects to the list', async () => {
        (axios.delete as ReturnType<typeof vi.fn>).mockResolvedValue({
            data: { ok: true },
        });

        const wrapper = mount(Show, { props: { training }, attachTo: document.body });
        await flushPromises();

        await wrapper
            .findAll('button')
            .find((b) => b.text().includes('Delete training'))!
            .trigger('click');
        await flushPromises();

        // Confirm button lives in the teleported dialog.
        const confirm = Array.from(
            document.body.querySelectorAll('button'),
        ).find((b) => b.textContent?.trim() === 'Delete')!;
        confirm.dispatchEvent(new Event('click', { bubbles: true }));
        await flushPromises();

        expect(axios.delete).toHaveBeenCalledWith(
            '/api/trainings/t1',
            expect.anything(),
        );
        expect(visit).toHaveBeenCalledWith('/trainings');
    });
});
