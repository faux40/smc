import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import RqmtElementFormModal from '@/pages/requirements/Partials/RqmtElementFormModal.vue';
import { useRqmtElementsStore } from '@/stores/rqmtElements';
import type { RqmtElementRow } from '@/stores/rqmtElements';
import { useStdFrequenciesStore } from '@/stores/stdFrequencies';
import { useTrainingsStore } from '@/stores/trainings';
import type { TrainingRow } from '@/stores/trainings';

vi.mock('axios');
vi.mock('@/echo', () => ({ realtimeTabId: () => 'test-tab' }));

/*
 * The element name is an OVERRIDE: null follows the training's live name.
 * These specs pin the two traps that made it a snapshot before:
 *  - create must not prefill the name from the picked training
 *  - edit must load custom_name (not the effective name), or saving an
 *    untouched form would silently freeze the live name as an override
 */

const STUBS = {
    Dialog: { template: '<div v-if="open"><slot /></div>', props: ['open'] },
    DialogContent: { template: '<div><slot /></div>' },
    DialogHeader: { template: '<div><slot /></div>' },
    DialogTitle: { template: '<div><slot /></div>' },
    DialogDescription: { template: '<div><slot /></div>' },
    DialogFooter: { template: '<div><slot /></div>' },
    ErrorBanner: true,
    InputError: true,
    Select: {
        template: '<div />',
        props: ['modelValue'],
        emits: ['update:modelValue'],
    },
    SelectTrigger: true,
    SelectContent: true,
    SelectItem: true,
    SelectValue: true,
};

function training(over: Partial<TrainingRow>): TrainingRow {
    return {
        id: 't',
        name: 'T',
        nickname: null,
        description: null,
        initial_only: false,
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
        satisfied_by_ids: [],
        can_edit: true,
        can_delete: true,
        ...over,
    };
}

function element(over: Partial<RqmtElementRow> = {}): RqmtElementRow {
    return {
        id: 'el1',
        requirement_id: 'req1',
        module_type: 'App\\Models\\Training',
        module_id: 't1',
        name: 'Ladder Safety',
        custom_name: null,
        module_name: 'Ladder Safety',
        description: null,
        initial_only: true,
        repeating: false,
        std_freq_id: null,
        as_needed: false,
        can_edit: true,
        can_delete: true,
        ...over,
    };
}

async function mountModal(props: Record<string, unknown> = {}) {
    const trainings = useTrainingsStore();
    trainings.library = [
        training({
            id: 't1',
            name: 'Ladder Safety',
            description: 'ladders',
            initial_only: true,
        }),
    ];
    trainings.loaded = true;

    const frequencies = useStdFrequenciesStore();
    frequencies.library = [];
    frequencies.loaded = true;

    const elements = useRqmtElementsStore();

    const wrapper = mount(RqmtElementFormModal, {
        props: {
            open: false,
            mode: 'create',
            requirementId: 'req1',
            ...props,
        },
        global: { stubs: STUBS },
    });
    await flushPromises();
    // The prefill watch keys off `open` flipping true.
    await wrapper.setProps({ open: true });
    await flushPromises();

    return { wrapper, elements };
}

function nameInput(wrapper: ReturnType<typeof mount>): HTMLInputElement {
    return wrapper.find('#e_name').element as HTMLInputElement;
}

describe('RqmtElementFormModal — override-only naming', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        vi.clearAllMocks();
    });

    it('does not prefill the name when a training is picked (timing/description still snap)', async () => {
        const { wrapper } = await mountModal();

        const moduleSelect = wrapper.findAllComponents(STUBS.Select)[0];
        moduleSelect.vm.$emit('update:modelValue', 't1');
        await flushPromises();

        // Description proves the prefill ran — the name was skipped on purpose.
        const desc = wrapper.find('#e_desc').element as HTMLTextAreaElement;
        expect(desc.value).toBe('ladders');
        expect(nameInput(wrapper).value).toBe('');
    });

    it('creates with name null when the field is left blank', async () => {
        const { wrapper, elements } = await mountModal();
        const spy = vi.spyOn(elements, 'create').mockResolvedValue(undefined);

        const moduleSelect = wrapper.findAllComponents(STUBS.Select)[0];
        moduleSelect.vm.$emit('update:modelValue', 't1');
        await flushPromises();

        await wrapper.find('form').trigger('submit');
        await flushPromises();

        expect(spy).toHaveBeenCalledWith(
            'req1',
            expect.objectContaining({ name: null, module_id: 't1' }),
        );
    });

    it('edit loads the override, not the effective name — an untouched save must not freeze the live name', async () => {
        const { wrapper, elements } = await mountModal({
            mode: 'edit',
            target: element(), // follows its training: custom_name null
        });
        const spy = vi.spyOn(elements, 'update').mockResolvedValue(undefined);

        expect(nameInput(wrapper).value).toBe('');

        await wrapper.find('form').trigger('submit');
        await flushPromises();

        expect(spy).toHaveBeenCalledWith(
            'el1',
            'req1',
            expect.objectContaining({ name: null }),
        );
    });

    it('edit shows an existing override and keeps it on save', async () => {
        const { wrapper, elements } = await mountModal({
            mode: 'edit',
            target: element({
                name: 'Old Label',
                custom_name: 'Old Label',
                module_name: 'Ladder Safety',
            }),
        });
        const spy = vi.spyOn(elements, 'update').mockResolvedValue(undefined);

        expect(nameInput(wrapper).value).toBe('Old Label');

        await wrapper.find('form').trigger('submit');
        await flushPromises();

        expect(spy).toHaveBeenCalledWith(
            'el1',
            'req1',
            expect.objectContaining({ name: 'Old Label' }),
        );
    });

    it('clearing the override sends null so the element follows again', async () => {
        const { wrapper, elements } = await mountModal({
            mode: 'edit',
            target: element({
                name: 'Old Label',
                custom_name: 'Old Label',
                module_name: 'Ladder Safety',
            }),
        });
        const spy = vi.spyOn(elements, 'update').mockResolvedValue(undefined);

        await wrapper.find('#e_name').setValue('   ');
        await wrapper.find('form').trigger('submit');
        await flushPromises();

        expect(spy).toHaveBeenCalledWith(
            'el1',
            'req1',
            expect.objectContaining({ name: null }),
        );
    });

    it("edit placeholder names the training the element follows", async () => {
        const { wrapper } = await mountModal({
            mode: 'edit',
            target: element(),
        });

        expect(wrapper.find('#e_name').attributes('placeholder')).toContain(
            'Ladder Safety',
        );
    });
});
