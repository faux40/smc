import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { useCardFieldsStore } from '@/stores/cardFields';
import { useCardMergeKeysStore } from '@/stores/cardMergeKeys';
import { useTrainingsStore } from '@/stores/trainings';
import MergeKeysPanel from './MergeKeysPanel.vue';

vi.mock('axios');

const writeText = vi.fn();

const catalogue = [
    {
        group: 'Person',
        keys: [
            { key: 'first_name', placeholder: '${first_name}' },
            { key: 'last_name', placeholder: '${last_name}' },
        ],
    },
    {
        group: 'Credit',
        keys: [{ key: 'cert_id', placeholder: '${cert_id}' }],
    },
];

async function mountPanel() {
    setActivePinia(createPinia());

    const keys = useCardMergeKeysStore();
    keys.groups = catalogue;
    keys.loaded = true;
    vi.spyOn(keys, 'load').mockResolvedValue();

    const trainings = useTrainingsStore();
    trainings.library = [
        { id: 't1', name: 'First Aid / CPR' } as never,
        { id: 't2', name: 'Forklift' } as never,
    ];
    trainings.loaded = true;
    vi.spyOn(trainings, 'load').mockResolvedValue();

    const fields = useCardFieldsStore();

    const wrapper = mount(MergeKeysPanel);
    await flushPromises();

    return { wrapper, fields };
}

async function pickTraining(
    wrapper: Awaited<ReturnType<typeof mountPanel>>['wrapper'],
    id: string,
) {
    await wrapper.get('[data-testid="keys-training"]').setValue(id);
    await flushPromises();
}

describe('MergeKeysPanel', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        Object.defineProperty(navigator, 'clipboard', {
            value: { writeText },
            configurable: true,
        });
    });

    it('lists the built-in keys under their groups', async () => {
        const { wrapper } = await mountPanel();

        expect(wrapper.text()).toContain('Person');
        expect(wrapper.text()).toContain('${first_name}');
        expect(wrapper.text()).toContain('${cert_id}');
    });

    it('copies a placeholder ready to paste into the slide', async () => {
        const { wrapper } = await mountPanel();

        await wrapper.get('[data-testid="copy-key"]').trigger('click');

        expect(writeText).toHaveBeenCalledWith('${first_name}');
    });

    it('adds a chosen training’s own fields to the list', async () => {
        const { wrapper, fields } = await mountPanel();
        vi.spyOn(fields, 'load').mockResolvedValue();
        fields.byTraining = {
            t1: [
                {
                    id: 'f1',
                    key: 'trainer_id',
                    placeholder: '${trainer_id}',
                    label: 'Trainer ID',
                    type: 'short',
                    default_value: null,
                    max_length: 100,
                    seq: 0,
                },
            ],
        };

        await pickTraining(wrapper, 't1');

        expect(wrapper.text()).toContain('${trainer_id}');
        expect(wrapper.text()).toContain('Trainer ID');
    });

    it('says when a training defines none of its own', async () => {
        const { wrapper, fields } = await mountPanel();
        vi.spyOn(fields, 'load').mockResolvedValue();
        fields.byTraining = { t2: [] };

        await pickTraining(wrapper, 't2');

        expect(wrapper.text()).toContain('no custom fields');
    });
});
