import { mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { CardFieldRow } from '@/lib/cardFields';
import CardFieldsEditor from '@/pages/trainings/Partials/CardFieldsEditor.vue';
import { useCardFieldsStore } from '@/stores/cardFields';

vi.mock('axios');
vi.mock('@/echo', () => ({ realtimeTabId: () => 'test-tab' }));

function row(overrides: Partial<CardFieldRow> & { id: string }): CardFieldRow {
    return {
        key: 'trainer_id',
        placeholder: '${trainer_id}',
        label: 'Trainer ID',
        type: 'short',
        default_value: null,
        max_length: 100,
        seq: 0,
        ...overrides,
    };
}

/** Mount with the store pre-seeded, so no fetch is involved. */
async function editor(rows: CardFieldRow[] = []) {
    const store = useCardFieldsStore();
    store.byTraining = { t1: rows };
    store.loaded = { t1: true };

    const wrapper = mount(CardFieldsEditor, {
        props: { trainingId: 't1' },
        global: { stubs: { teleport: true } },
    });

    await wrapper.vm.$nextTick();

    return { wrapper, store };
}

const keyInputs = (wrapper: ReturnType<typeof mount>) =>
    wrapper.findAll('[data-testid="card-field-key"]');
const labelInputs = (wrapper: ReturnType<typeof mount>) =>
    wrapper.findAll('[data-testid="card-field-label"]');

describe('CardFieldsEditor', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        vi.clearAllMocks();
    });

    it('opens a training with no fields on four blank rows', async () => {
        // Two plain and two formatted, per the plan — a starting point, not a
        // limit, and nothing is written until saved.
        const { wrapper } = await editor([]);

        expect(keyInputs(wrapper)).toHaveLength(4);
        expect(wrapper.findAll('[data-testid="card-field-rich"]')).toHaveLength(
            2,
        );
    });

    it('shows the defined fields when there are some', async () => {
        const { wrapper } = await editor([
            row({ id: 'f1', key: 'trainer_id' }),
            row({ id: 'f2', key: 'notes', type: 'rich', max_length: 2000 }),
        ]);

        expect(keyInputs(wrapper)).toHaveLength(2);
        expect((keyInputs(wrapper)[0].element as HTMLInputElement).value).toBe(
            'trainer_id',
        );
    });

    it('suggests a key from the label on a new row', async () => {
        const { wrapper } = await editor([]);

        await labelInputs(wrapper)[0].setValue('Trainer ID');

        expect((keyInputs(wrapper)[0].element as HTMLInputElement).value).toBe(
            'trainer_id',
        );
    });

    it('stops suggesting once the key has been edited by hand', async () => {
        // Otherwise a deliberate key is silently overwritten by a later label
        // tweak.
        const { wrapper } = await editor([]);

        await keyInputs(wrapper)[0].setValue('tid');
        await labelInputs(wrapper)[0].setValue('Trainer ID');

        expect((keyInputs(wrapper)[0].element as HTMLInputElement).value).toBe(
            'tid',
        );
    });

    it('never rewrites the key of a field that already exists', async () => {
        // A saved key is in templates already; renaming it is deliberate work,
        // not a side effect of fixing a typo in the label.
        const { wrapper } = await editor([
            row({ id: 'f1', key: 'trainer_id' }),
        ]);

        await labelInputs(wrapper)[0].setValue('Instructor Number');

        expect((keyInputs(wrapper)[0].element as HTMLInputElement).value).toBe(
            'trainer_id',
        );
    });

    it('shows the merge placeholder for a keyed row', async () => {
        const { wrapper } = await editor([]);

        await keyInputs(wrapper)[0].setValue('trainer_id');

        expect(wrapper.text()).toContain('${trainer_id}');
    });

    it('adds a row on demand', async () => {
        const { wrapper } = await editor([row({ id: 'f1' })]);

        await wrapper.find('[data-testid="card-field-add"]').trigger('click');

        expect(keyInputs(wrapper)).toHaveLength(2);
    });

    it('removes an unsaved row without ceremony', async () => {
        const { wrapper } = await editor([]);

        await wrapper
            .findAll('[data-testid="card-field-remove"]')[0]
            .trigger('click');

        expect(keyInputs(wrapper)).toHaveLength(3);
        expect(
            wrapper.find('[data-testid="card-field-confirm"]').exists(),
        ).toBe(false);
    });

    it('asks before removing a saved field, naming what it would discard', async () => {
        const { wrapper } = await editor([
            row({ id: 'f1', label: 'Trainer ID', value_count: 3 }),
        ]);

        await wrapper
            .findAll('[data-testid="card-field-remove"]')[0]
            .trigger('click');

        const confirm = wrapper.find('[data-testid="card-field-confirm"]');
        expect(confirm.exists()).toBe(true);
        expect(confirm.text()).toContain('3');
        // Still on screen until confirmed.
        expect(keyInputs(wrapper)).toHaveLength(1);
    });

    it('does not save a set with a duplicate key', async () => {
        const { wrapper, store } = await editor([]);
        const sync = vi.spyOn(store, 'sync');

        await labelInputs(wrapper)[0].setValue('Trainer');
        await keyInputs(wrapper)[0].setValue('trainer_id');
        await labelInputs(wrapper)[1].setValue('Trainer again');
        await keyInputs(wrapper)[1].setValue('trainer_id');
        await wrapper.find('[data-testid="card-field-save"]').trigger('click');

        expect(sync).not.toHaveBeenCalled();
        expect(wrapper.text()).toContain('used twice');
    });

    it('does not save an illegal key', async () => {
        const { wrapper, store } = await editor([]);
        const sync = vi.spyOn(store, 'sync');

        await keyInputs(wrapper)[0].setValue('Trainer ID');
        await wrapper.find('[data-testid="card-field-save"]').trigger('click');

        expect(sync).not.toHaveBeenCalled();
    });

    it('saves the set in order, dropping untouched rows', async () => {
        const { wrapper, store } = await editor([]);
        const sync = vi.spyOn(store, 'sync').mockResolvedValue([]);

        await labelInputs(wrapper)[0].setValue('Trainer ID');
        await labelInputs(wrapper)[1].setValue('Card Number');
        await wrapper.find('[data-testid="card-field-save"]').trigger('click');

        expect(sync).toHaveBeenCalledWith('t1', [
            {
                id: null,
                key: 'trainer_id',
                label: 'Trainer ID',
                type: 'short',
                default_value: null,
            },
            {
                id: null,
                key: 'card_number',
                label: 'Card Number',
                type: 'short',
                default_value: null,
            },
        ]);
    });

    it('leaves save disabled until something changes', async () => {
        const { wrapper } = await editor([row({ id: 'f1' })]);

        const save = wrapper.find('[data-testid="card-field-save"]');
        expect((save.element as HTMLButtonElement).disabled).toBe(true);

        await labelInputs(wrapper)[0].setValue('Changed');

        expect((save.element as HTMLButtonElement).disabled).toBe(false);
    });

    it('rebuilds its baseline from the response, so a second save is idle', async () => {
        const { wrapper, store } = await editor([]);
        vi.spyOn(store, 'sync').mockImplementation(async () => {
            const saved = [
                row({ id: 'f9', key: 'trainer_id', label: 'Trainer ID' }),
            ];
            store.byTraining = { t1: saved };

            return saved;
        });

        await labelInputs(wrapper)[0].setValue('Trainer ID');
        await wrapper.find('[data-testid="card-field-save"]').trigger('click');
        await wrapper.vm.$nextTick();

        const save = wrapper.find('[data-testid="card-field-save"]');
        expect((save.element as HTMLButtonElement).disabled).toBe(true);
    });
});
