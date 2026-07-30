import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { CardFieldWithValue } from '@/lib/cardFields';
import type { ClassDetail, ClassTrainingRow } from '@/stores/classes';
import { useClassesStore } from '@/stores/classes';
import ClassCardFieldsModal from './ClassCardFieldsModal.vue';

vi.mock('axios');
vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: { org: { name: 'Test Org' } } }),
}));

function field(
    overrides: Partial<CardFieldWithValue> & { id: string },
): CardFieldWithValue {
    return {
        key: 'trainer_id',
        placeholder: '${trainer_id}',
        label: 'Trainer ID',
        type: 'short',
        default_value: null,
        max_length: 100,
        seq: 0,
        value: null,
        ...overrides,
    };
}

function topic(fields: CardFieldWithValue[]): ClassTrainingRow {
    return {
        id: 'ct1',
        training_id: 't1',
        training_name: 'First Aid / CPR',
        initial_only: false,
        repeating: true,
        as_needed: false,
        std_freq_name: null,
        repeat_days: null,
        hours: '4.00',
        cert_title: null,
        cert_text: null,
        cert_code: null,
        card_fields: fields,
        credits: [],
    };
}

function detail(
    fields: CardFieldWithValue[],
    status: ClassDetail['status'] = 'scheduled',
): ClassDetail {
    return {
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
        min_students: null,
        max_students: null,
        notes: null,
        status,
        completion_date: null,
        can_edit: status === 'scheduled',
        trainings: [topic(fields)],
        enrollments: [],
    };
}

async function open(
    fields: CardFieldWithValue[],
    status: ClassDetail['status'] = 'scheduled',
) {
    const store = useClassesStore();
    store.detail = { c1: detail(fields, status) };

    const wrapper = mount(ClassCardFieldsModal, {
        props: { open: true, classId: 'c1', topicId: 'ct1' },
        attachTo: document.body,
    });

    await flushPromises();

    return { wrapper, store };
}

const inputs = () =>
    Array.from(
        document.body.querySelectorAll<HTMLInputElement>(
            '[data-testid="card-value-short"]',
        ),
    );
const areas = () =>
    Array.from(
        document.body.querySelectorAll<HTMLTextAreaElement>(
            '[data-testid="card-value-rich"]',
        ),
    );
const saveButton = () =>
    document.body.querySelector<HTMLButtonElement>(
        '[data-testid="card-value-save"]',
    );

describe('ClassCardFieldsModal', () => {
    enableAutoUnmount(afterEach);

    beforeEach(() => {
        setActivePinia(createPinia());
        vi.clearAllMocks();
        document.body.innerHTML = '';
    });

    it('seeds each field with this class’s answer', async () => {
        await open([
            field({ id: 'f1', value: 'INST-4471' }),
            field({
                id: 'f2',
                key: 'notes',
                type: 'rich',
                value: 'Signed off',
            }),
        ]);

        expect(inputs()[0].value).toBe('INST-4471');
        expect(areas()[0].value).toBe('Signed off');
    });

    it('shows the training default as the placeholder, not as the answer', async () => {
        // The distinction matters: leaving it blank prints the default, and
        // pre-filling it would turn the default into a copy that stops
        // tracking the training.
        await open([
            field({ id: 'f1', default_value: 'INST-0000', value: null }),
        ]);

        expect(inputs()[0].value).toBe('');
        expect(inputs()[0].placeholder).toContain('INST-0000');
    });

    it('labels each field and shows its merge key', async () => {
        await open([field({ id: 'f1', label: 'Trainer ID' })]);

        expect(document.body.textContent).toContain('Trainer ID');
        expect(document.body.textContent).toContain('${trainer_id}');
    });

    it('caps a short field at its own max length', async () => {
        await open([field({ id: 'f1', max_length: 100 })]);

        expect(inputs()[0].getAttribute('maxlength')).toBe('100');
    });

    it('saves the answers keyed by field id', async () => {
        const { store } = await open([
            field({ id: 'f1' }),
            field({ id: 'f2', key: 'notes', type: 'rich' }),
        ]);
        const save = vi
            .spyOn(store, 'updateTrainingCardValues')
            .mockResolvedValue(detail([]));

        inputs()[0].value = 'INST-4471';
        inputs()[0].dispatchEvent(new Event('input'));
        areas()[0].value = 'All good';
        areas()[0].dispatchEvent(new Event('input'));
        await flushPromises();

        saveButton()!.click();
        await flushPromises();

        expect(save).toHaveBeenCalledWith('c1', 'ct1', {
            f1: 'INST-4471',
            f2: 'All good',
        });
    });

    it('sends an emptied field as a blank, which clears it', async () => {
        const { store } = await open([field({ id: 'f1', value: 'INST-4471' })]);
        const save = vi
            .spyOn(store, 'updateTrainingCardValues')
            .mockResolvedValue(detail([]));

        inputs()[0].value = '';
        inputs()[0].dispatchEvent(new Event('input'));
        await flushPromises();

        saveButton()!.click();
        await flushPromises();

        expect(save).toHaveBeenCalledWith('c1', 'ct1', { f1: '' });
    });

    it('closes once saved', async () => {
        const { wrapper, store } = await open([field({ id: 'f1' })]);
        vi.spyOn(store, 'updateTrainingCardValues').mockResolvedValue(
            detail([]),
        );

        saveButton()!.click();
        await flushPromises();

        expect(wrapper.emitted('update:open')?.at(-1)).toEqual([false]);
    });

    it('stays open and reports a failed save', async () => {
        const { wrapper, store } = await open([field({ id: 'f1' })]);
        vi.spyOn(store, 'updateTrainingCardValues').mockRejectedValue(
            new Error('nope'),
        );

        saveButton()!.click();
        await flushPromises();

        expect(wrapper.emitted('update:open')).toBeUndefined();
        expect(document.body.textContent).toContain('nope');
    });

    it('is read-only on a completed class, and says why', async () => {
        // Confirmed behaviour: a finished class is read-only, so printing cards
        // from one means reopening it first.
        await open([field({ id: 'f1', value: 'INST-4471' })], 'completed');

        expect(inputs()[0].disabled).toBe(true);
        expect(saveButton()).toBeNull();
        expect(document.body.textContent).toContain('Reopen');
    });

    it('says so when the training defines no card fields', async () => {
        await open([]);

        expect(document.body.textContent).toContain('No card fields');
        expect(saveButton()).toBeNull();
    });
});
