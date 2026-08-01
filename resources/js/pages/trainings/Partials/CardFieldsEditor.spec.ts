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

/**
 * Mount with the store pre-seeded, so no fetch is involved.
 *
 * `attach` puts the component in the real document, which only focus
 * assertions need — detached, `document.activeElement` never leaves <body>.
 */
async function editor(rows: CardFieldRow[] = [], attach = false) {
    const store = useCardFieldsStore();
    store.byTraining = { t1: rows };
    store.loaded = { t1: true };

    const wrapper = mount(CardFieldsEditor, {
        props: { trainingId: 't1' },
        global: { stubs: { teleport: true } },
        ...(attach ? { attachTo: document.body } : {}),
    });

    await wrapper.vm.$nextTick();

    return { wrapper, store };
}

const keyInputs = (wrapper: ReturnType<typeof mount>) =>
    wrapper.findAll('[data-testid="card-field-key"]');
const labelInputs = (wrapper: ReturnType<typeof mount>) =>
    wrapper.findAll('[data-testid="card-field-label"]');

/**
 * The editor no longer opens on blank rows, so a test that wants somewhere to
 * type asks for one — exactly as a user now does.
 */
async function editorWithBlankRows(count = 1) {
    const opened = await editor([]);

    for (let i = 0; i < count; i++) {
        await opened.wrapper
            .get('[data-testid="card-field-add"]')
            .trigger('click');
    }

    return opened;
}

describe('CardFieldsEditor', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        vi.clearAllMocks();
    });

    it('opens a training with no fields on no rows at all', async () => {
        // Blank rows implied a fixed set of four. Only what has been added is
        // shown, and the empty state says what to do instead.
        const { wrapper } = await editor([]);

        expect(keyInputs(wrapper)).toHaveLength(0);
        expect(wrapper.text()).toContain('No card fields yet');
    });

    it('adds a row when asked, and stops at the server ceiling', async () => {
        const { wrapper } = await editor([]);
        const add = wrapper.get('[data-testid="card-field-add"]');

        await add.trigger('click');
        expect(keyInputs(wrapper)).toHaveLength(1);

        // The cap is the server's (50); the client just stops you earlier
        // than a 422 would.
        expect(wrapper.text()).toContain('1 of 50');
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
        const { wrapper } = await editorWithBlankRows();

        await labelInputs(wrapper)[0].setValue('Trainer ID');

        expect((keyInputs(wrapper)[0].element as HTMLInputElement).value).toBe(
            'trainer_id',
        );
    });

    it('stops suggesting once the key has been edited by hand', async () => {
        // Otherwise a deliberate key is silently overwritten by a later label
        // tweak.
        const { wrapper } = await editorWithBlankRows();

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
        const { wrapper } = await editorWithBlankRows();

        await keyInputs(wrapper)[0].setValue('trainer_id');

        expect(wrapper.text()).toContain('${trainer_id}');
    });

    it('adds a row on demand', async () => {
        const { wrapper } = await editor([row({ id: 'f1' })]);

        await wrapper.find('[data-testid="card-field-add"]').trigger('click');

        expect(keyInputs(wrapper)).toHaveLength(2);
    });

    it('removes an unsaved row without ceremony', async () => {
        const { wrapper } = await editorWithBlankRows(2);

        await wrapper
            .findAll('[data-testid="card-field-remove"]')[0]
            .trigger('click');

        expect(keyInputs(wrapper)).toHaveLength(1);
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
        const { wrapper, store } = await editorWithBlankRows(2);
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
        const { wrapper, store } = await editorWithBlankRows();
        const sync = vi.spyOn(store, 'sync');

        await keyInputs(wrapper)[0].setValue('Trainer ID');
        await wrapper.find('[data-testid="card-field-save"]').trigger('click');

        expect(sync).not.toHaveBeenCalled();
    });

    it('saves the set in order, dropping untouched rows', async () => {
        const { wrapper, store } = await editorWithBlankRows(2);
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

    describe('reordering', () => {
        /*
         * Order is what the server stores as `seq`, and `seq` drives the order
         * fields are entered on a class and listed in the card builder. Until
         * now the only way to change it was to delete rows and retype them.
         */
        const handles = (wrapper: ReturnType<typeof mount>) =>
            wrapper.findAll('[data-testid="card-field-handle"]');
        const rows = (wrapper: ReturnType<typeof mount>) =>
            wrapper.findAll('[data-testid="card-field-row"]');
        const keysInOrder = (wrapper: ReturnType<typeof mount>) =>
            keyInputs(wrapper).map((i) => (i.element as HTMLInputElement).value);

        async function threeFields(attach = false) {
            return editor(
                [
                    row({ id: 'f1', key: 'alpha', label: 'Alpha', seq: 0 }),
                    row({ id: 'f2', key: 'beta', label: 'Beta', seq: 1 }),
                    row({ id: 'f3', key: 'gamma', label: 'Gamma', seq: 2 }),
                ],
                attach,
            );
        }

        it('moves a row by dragging its handle onto another', async () => {
            const { wrapper } = await threeFields();

            await handles(wrapper)[0].trigger('dragstart');
            await rows(wrapper)[2].trigger('dragover');
            await handles(wrapper)[0].trigger('dragend');

            expect(keysInOrder(wrapper)).toEqual(['beta', 'gamma', 'alpha']);
        });

        it('moves a row down with the keyboard', async () => {
            // A drag-only control would put reordering out of reach entirely
            // for anyone not using a mouse.
            const { wrapper } = await threeFields();

            await handles(wrapper)[0].trigger('keydown', { key: 'ArrowDown' });

            expect(keysInOrder(wrapper)).toEqual(['beta', 'alpha', 'gamma']);
        });

        it('moves a row up with the keyboard', async () => {
            const { wrapper } = await threeFields();

            await handles(wrapper)[2].trigger('keydown', { key: 'ArrowUp' });

            expect(keysInOrder(wrapper)).toEqual(['alpha', 'gamma', 'beta']);
        });

        it('stops at the ends rather than wrapping around', async () => {
            const { wrapper } = await threeFields();

            await handles(wrapper)[0].trigger('keydown', { key: 'ArrowUp' });
            expect(keysInOrder(wrapper)).toEqual(['alpha', 'beta', 'gamma']);

            await handles(wrapper)[2].trigger('keydown', { key: 'ArrowDown' });
            expect(keysInOrder(wrapper)).toEqual(['alpha', 'beta', 'gamma']);
        });

        it('keeps focus travelling with the row it moved', async () => {
            /*
             * Holding ArrowDown has to keep moving the same row. That works
             * only because rows are keyed by uid: Vue then MOVES the focused
             * button rather than repainting a stationary one, so focus lands
             * on the row's new position instead of on whatever slid into the
             * old one.
             */
            const { wrapper } = await threeFields(true);
            const handle = handles(wrapper)[0].element as HTMLElement;

            handle.focus();
            await handles(wrapper)[0].trigger('keydown', { key: 'ArrowDown' });
            await wrapper.vm.$nextTick();

            expect(document.activeElement).toBe(handle);
            // ...and that node is now the SECOND handle — the row moved, the
            // focus went with it.
            expect(handles(wrapper)[1].element).toBe(handle);

            wrapper.unmount();
        });

        it('offers no handle when there is nothing to reorder', async () => {
            const { wrapper } = await editor([row({ id: 'f1' })]);

            expect(handles(wrapper)).toHaveLength(0);
        });

        it('counts a reorder as a change worth saving', async () => {
            const { wrapper } = await threeFields();

            expect(
                (
                    wrapper.find('[data-testid="card-field-save"]')
                        .element as HTMLButtonElement
                ).disabled,
            ).toBe(true);

            await handles(wrapper)[0].trigger('keydown', { key: 'ArrowDown' });

            expect(
                (
                    wrapper.find('[data-testid="card-field-save"]')
                        .element as HTMLButtonElement
                ).disabled,
            ).toBe(false);
        });

        it('sends the new order, which is what the server turns into seq', async () => {
            const { wrapper, store } = await threeFields();
            const sync = vi.spyOn(store, 'sync').mockResolvedValue([]);

            await handles(wrapper)[0].trigger('keydown', { key: 'ArrowDown' });
            await wrapper
                .find('[data-testid="card-field-save"]')
                .trigger('click');

            expect(
                (sync.mock.calls[0][1] as { key: string }[]).map((f) => f.key),
            ).toEqual(['beta', 'alpha', 'gamma']);
        });

        it('carries the hand-edited-key flag with the row that moved', async () => {
            /*
             * Regression: the flag used to live in an array indexed alongside
             * the drafts, so moving a row left it pointing at whichever row
             * inherited the position — and the label suggestion would then
             * quietly overwrite a key someone had chosen on purpose.
             */
            const { wrapper } = await editorWithBlankRows(2);

            await keyInputs(wrapper)[0].setValue('tid');
            await handles(wrapper)[0].trigger('keydown', { key: 'ArrowDown' });

            // The hand-keyed row is now second; typing its label must not
            // rewrite the key.
            await labelInputs(wrapper)[1].setValue('Trainer ID');

            expect(keysInOrder(wrapper)[1]).toBe('tid');
        });

        it('drops a pending delete confirmation when the list moves under it', async () => {
            // The confirmation names a specific field; leaving it open while
            // the rows shuffle invites confirming the wrong one.
            const { wrapper } = await threeFields();

            await wrapper
                .findAll('[data-testid="card-field-remove"]')[0]
                .trigger('click');
            expect(
                wrapper.find('[data-testid="card-field-confirm"]').exists(),
            ).toBe(true);

            await handles(wrapper)[1].trigger('keydown', { key: 'ArrowUp' });

            expect(
                wrapper.find('[data-testid="card-field-confirm"]').exists(),
            ).toBe(false);
        });
    });

    it('promises only the markdown that actually prints', async () => {
        /*
         * C5 ships bold, italic and line breaks — deliberately not lists,
         * which are paragraph-level and were cut. The placeholder is the
         * feature's documentation, and it must not advertise formatting that
         * would silently degrade to plain text on purchased stock.
         */
        const { wrapper } = await editor([
            row({ id: 'f1', key: 'endorsement', type: 'rich' }),
        ]);

        const hint = wrapper
            .get('[data-testid="card-field-rich"]')
            .attributes('placeholder');

        expect(hint).toContain('**bold**');
        expect(hint).toContain('*italic*');
        expect(hint).not.toContain('list');
    });

    it('leaves save disabled until something changes', async () => {
        const { wrapper } = await editor([row({ id: 'f1' })]);

        const save = wrapper.find('[data-testid="card-field-save"]');
        expect((save.element as HTMLButtonElement).disabled).toBe(true);

        await labelInputs(wrapper)[0].setValue('Changed');

        expect((save.element as HTMLButtonElement).disabled).toBe(false);
    });

    it('rebuilds its baseline from the response, so a second save is idle', async () => {
        const { wrapper, store } = await editorWithBlankRows();
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
