import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils';
import axios from 'axios';
import { createPinia, setActivePinia } from 'pinia';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import AttachmentsList from '@/components/AttachmentsList.vue';
import TagsField from '@/components/TagsField.vue';
import type { TrainingFormSource } from '@/lib/trainingForm';
import CardFieldsEditor from '@/pages/trainings/Partials/CardFieldsEditor.vue';
import Show from '@/pages/trainings/Show.vue';
import { useTrainingsStore } from '@/stores/trainings';

const visit = vi.fn();
const { authUser } = vi.hoisted(() => ({
    authUser: { value: { isAdmin: true } as Record<string, unknown> },
}));

vi.mock('axios');
vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<div><slot /></div>' },
    Link: { template: '<a :href="href"><slot /></a>', props: ['href'] },
    usePage: () => ({ props: { auth: { user: authUser.value } } }),
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
    cert_code: null,
    card_template_id: null,
    card_stock_id: null,
    default_trainer: null,
    default_location: null,
    default_address: null,
    satisfied_by_ids: [],
};

describe('trainings/Show', () => {
    enableAutoUnmount(afterEach);

    beforeEach(() => {
        setActivePinia(createPinia());
        vi.clearAllMocks();
        authUser.value = { isAdmin: true };
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

        await wrapper.get('#cert_title').setValue('New Title');
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

        const wrapper = mount(Show, {
            props: { training },
            attachTo: document.body,
        });
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

    it('offers the card-fields editor, scoped to this training', async () => {
        // Its own section with its own save — the training form PATCHes fields,
        // the editor PUTs a set.
        const wrapper = mount(Show, { props: { training } });
        await flushPromises();

        const editor = wrapper.findComponent(CardFieldsEditor);
        expect(editor.exists()).toBe(true);
        expect(editor.props('trainingId')).toBe('t1');
    });

    it('offers a Satisfied-by picker that excludes this training and its dependants', async () => {
        // Options come from the library. The training itself is out (nothing
        // satisfies itself), and so is anything whose chain already runs
        // THROUGH this training — checking one would loop the DAG. Here
        // t-below names t1 among its satisfiers, so both are excluded;
        // t-free is offered.
        const trainings = useTrainingsStore();
        trainings.loaded = true;
        trainings.library = [
            { id: 't1', name: 'Authorized', satisfied_by_ids: [] },
            {
                id: 't-below',
                name: 'Awareness',
                satisfied_by_ids: ['t-free', 't1'],
            },
            { id: 't-free', name: 'Competent', satisfied_by_ids: [] },
        ] as (typeof trainings)['library'];

        const wrapper = mount(Show, { props: { training } });
        await flushPromises();

        const labels = wrapper
            .get('#t_satisfied_by')
            .findAll('li label')
            .map((l) => l.text());
        expect(labels.join(' ')).toContain('Competent');
        expect(labels.join(' ')).not.toContain('Authorized');
        expect(labels.join(' ')).not.toContain('Awareness');
    });

    it('saves the checked higher trainings on the PATCH payload', async () => {
        const trainings = useTrainingsStore();
        trainings.loaded = true;
        trainings.library = [
            { id: 't-free', name: 'Competent', satisfied_by_ids: [] },
            { id: 't-alt', name: 'Refresher', satisfied_by_ids: [] },
        ] as unknown as (typeof trainings)['library'];
        (axios.patch as ReturnType<typeof vi.fn>).mockResolvedValue({
            data: { ...training, satisfied_by_ids: ['t-free', 't-alt'] },
        });

        const wrapper = mount(Show, { props: { training } });
        await flushPromises();

        await wrapper
            .get('#t_satisfied_by input[value="t-free"]')
            .setValue(true);
        await wrapper
            .get('#t_satisfied_by input[value="t-alt"]')
            .setValue(true);
        await wrapper.get('form').trigger('submit');
        await flushPromises();

        expect(axios.patch).toHaveBeenCalledWith(
            '/api/trainings/t1',
            expect.objectContaining({
                satisfied_by_ids: ['t-free', 't-alt'],
            }),
            expect.anything(),
        );
    });

    it('mounts TagsField for this training, hydrated from the page prop', async () => {
        // The tags store has no per-morphable fetch, so the page prop is the
        // only hydration path — a wrong morphable type would attach the tag to
        // the wrong record entirely.
        const wrapper = mount(Show, {
            props: { training, tagIds: ['tag-1', 'tag-2'] },
        });
        await flushPromises();

        const field = wrapper.findComponent(TagsField);
        expect(field.exists()).toBe(true);
        expect(field.props('morphableType')).toBe('App\\Models\\Training');
        expect(field.props('morphableId')).toBe('t1');
        expect(field.props('initialTagIds')).toEqual(['tag-1', 'tag-2']);
    });

    it('lists this training’s supporting material, uploadable by a manager', async () => {
        const wrapper = mount(Show, { props: { training } });
        await flushPromises();

        const files = wrapper.findComponent(AttachmentsList);
        expect(files.exists()).toBe(true);
        expect(files.props('morphableType')).toBe('App\\Models\\Training');
        expect(files.props('morphableId')).toBe('t1');
        expect(files.props('canUpload')).toBe(true);
    });

    it('shows the material read-only to someone who cannot manage the training', async () => {
        authUser.value = { isAdmin: false };
        const wrapper = mount(Show, { props: { training } });
        await flushPromises();

        const files = wrapper.findComponent(AttachmentsList);
        expect(files.exists()).toBe(true);
        expect(files.props('canUpload')).toBe(false);
    });

    it('defaults tagIds to empty rather than passing undefined through', async () => {
        const wrapper = mount(Show, { props: { training } });
        await flushPromises();

        expect(wrapper.findComponent(TagsField).props('initialTagIds')).toEqual(
            [],
        );
    });
});
