import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { CardFieldWithValue } from '@/lib/cardFields';
import { useClassesStore } from '@/stores/classes';
import type { ClassDetail, ClassTrainingRow } from '@/stores/classes';
import ClassTopicPanel from './ClassTopicPanel.vue';

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

function topic(
    overrides: Partial<ClassTrainingRow> = {},
): ClassTrainingRow {
    return {
        id: 'ct1',
        training_id: 't1',
        training_name: 'First Aid / CPR',
        initial_only: false,
        repeating: true,
        as_needed: false,
        std_freq_name: 'Annual',
        repeat_days: 365,
        hours: '4.00',
        cert_title: 'First Aid',
        cert_text: 'Satisfies **Cal/OSHA**.',
        cert_code: 'FA',
        card_fields: [],
        expire_date: null,
        credits: [],
        ...overrides,
    };
}

function detail(): ClassDetail {
    return {
        id: 'c1',
        name: 'Class 1',
        scheduled_date: '2026-06-01',
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
        status: 'scheduled',
        completion_date: null,
        was_completed: false,
        can_edit: true,
        tag_ids: [],
        trainings: [topic()],
        enrollments: [],
    };
}

function mountPanel(
    row: ClassTrainingRow = topic(),
    props: Record<string, unknown> = {},
) {
    setActivePinia(createPinia());
    const store = useClassesStore();
    const wrapper = mount(ClassTopicPanel, {
        props: {
            classId: 'c1',
            topic: row,
            derivedFrom: '2026-06-01',
            readOnly: false,
            ...props,
        },
    });

    return { wrapper, store };
}

/**
 * Roll the section open — most fields live behind it. Keyed off the body
 * rather than the Save button, which a read-only panel never renders.
 */
async function openPanel(wrapper: ReturnType<typeof mountPanel>['wrapper']) {
    if (!wrapper.find('[data-testid="topic-body"]').exists()) {
        await wrapper.get('[data-testid="section-toggle"]').trigger('click');
    }
}

/** The certificate box is shut by default — it's the tall one. */
async function openCert(wrapper: ReturnType<typeof mountPanel>['wrapper']) {
    await openPanel(wrapper);

    if (!wrapper.find('[data-testid="topic-cert-code"]').exists()) {
        await wrapper.get('[data-testid="cert-toggle"]').trigger('click');
    }
}

const shortInputs = (w: ReturnType<typeof mountPanel>['wrapper']) =>
    w.findAll<HTMLInputElement>('[data-testid="card-value-short"]');
const richAreas = (w: ReturnType<typeof mountPanel>['wrapper']) =>
    w.findAll<HTMLTextAreaElement>('[data-testid="card-value-rich"]');

describe('ClassTopicPanel', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    describe('the rolled-up header', () => {
        it('names the topic and summarises it without being opened', async () => {
            const { wrapper } = mountPanel(
                topic({
                    hours: '8.00',
                    card_fields: [field({ id: 'f1' })],
                }),
            );

            const text = wrapper.text();

            expect(text).toContain('First Aid / CPR');
            expect(text).toContain('8h');
            expect(text).toContain('1 card field');
            // Shut by default: nothing here was decided by hand.
            expect(wrapper.find('[data-testid="topic-body"]').exists()).toBe(
                false,
            );
        });

        it('opens itself when the topic already carries a card answer', () => {
            // Something was entered by hand — surface it rather than hiding it
            // one click deep where nobody re-checks it before printing.
            const { wrapper } = mountPanel(
                topic({
                    card_fields: [field({ id: 'f1', value: 'INST-4471' })],
                }),
            );

            expect(wrapper.find('[data-testid="topic-body"]').exists()).toBe(
                true,
            );
        });

        it('opens itself when the expiry was set by hand, and says so shut', () => {
            const { wrapper } = mountPanel(
                topic({ expire_date: '2029-07-15' }),
            );

            expect(wrapper.find('[data-testid="topic-body"]').exists()).toBe(
                true,
            );
            expect(wrapper.text()).toContain('expires 2029-07-15');
        });

        it('stays shut for certificate text alone', () => {
            // cert_title/text/code are copied from the training when the topic
            // is attached, so having them says nothing about this class.
            const { wrapper } = mountPanel(
                topic({ cert_title: 'First Aid', cert_text: 'Anything.' }),
            );

            expect(wrapper.find('[data-testid="topic-body"]').exists()).toBe(
                false,
            );
        });
    });

    describe('expiry', () => {
        it('shows what close-out would derive when none is set', async () => {
            const { wrapper } = mountPanel();
            await openPanel(wrapper);

            // 2026-06-01 + 365 days.
            const hint = wrapper.get('[data-testid="expiry-hint"]').text();
            expect(hint).toContain('2027-06-01');
            expect(hint).toContain('Annual');
        });

        it('says a training that does not repeat never expires', async () => {
            const { wrapper } = mountPanel(
                topic({ repeating: false, repeat_days: null }),
            );
            await openPanel(wrapper);

            expect(wrapper.get('[data-testid="expiry-hint"]').text()).toContain(
                'never expires',
            );
        });

        it('has nothing to derive from before a date is known', async () => {
            const { wrapper } = mountPanel(topic(), { derivedFrom: null });
            await openPanel(wrapper);

            expect(wrapper.find('[data-testid="expiry-hint"]').exists()).toBe(
                false,
            );
        });

        it('saves a hand-set expiry', async () => {
            const { wrapper, store } = mountPanel();
            const save = vi
                .spyOn(store, 'updateTopic')
                .mockResolvedValue(detail());
            await openPanel(wrapper);

            await wrapper
                .get('[data-testid="topic-expire-date"]')
                .setValue('2029-07-15');
            await wrapper.get('[data-testid="topic-save"]').trigger('click');
            await flushPromises();

            expect(save).toHaveBeenCalledWith(
                'c1',
                'ct1',
                expect.objectContaining({ expire_date: '2029-07-15' }),
            );
        });

        it('sends a cleared expiry as null, not as an empty string', async () => {
            const { wrapper, store } = mountPanel(
                topic({ expire_date: '2029-07-15' }),
            );
            const save = vi
                .spyOn(store, 'updateTopic')
                .mockResolvedValue(detail());
            await openPanel(wrapper);

            await wrapper
                .get('[data-testid="topic-expire-date"]')
                .setValue('');
            await wrapper.get('[data-testid="topic-save"]').trigger('click');
            await flushPromises();

            // null means "derive it again"; '' would fail date validation.
            expect(save).toHaveBeenCalledWith(
                'c1',
                'ct1',
                expect.objectContaining({ expire_date: null }),
            );
        });
    });

    describe('card fields', () => {
        it('seeds each field with this class’s answer', async () => {
            const { wrapper } = mountPanel(
                topic({
                    card_fields: [
                        field({ id: 'f1', value: 'INST-4471' }),
                        field({
                            id: 'f2',
                            key: 'notes',
                            type: 'rich',
                            value: 'Signed off',
                        }),
                    ],
                }),
            );
            await openPanel(wrapper);

            expect(shortInputs(wrapper)[0].element.value).toBe('INST-4471');
            expect(richAreas(wrapper)[0].element.value).toBe('Signed off');
        });

        it('shows the training default as the placeholder, not as the answer', async () => {
            // Pre-filling it would turn the default into a copy that stops
            // tracking the training.
            const { wrapper } = mountPanel(
                topic({
                    card_fields: [
                        field({
                            id: 'f1',
                            default_value: 'INST-0000',
                            value: null,
                        }),
                    ],
                }),
            );
            await openPanel(wrapper);

            expect(shortInputs(wrapper)[0].element.value).toBe('');
            expect(
                shortInputs(wrapper)[0].attributes('placeholder'),
            ).toContain('INST-0000');
        });

        it('labels each field, shows its merge key and caps its length', async () => {
            const { wrapper } = mountPanel(
                topic({
                    card_fields: [
                        field({ id: 'f1', label: 'Trainer ID', max_length: 100 }),
                    ],
                }),
            );
            await openPanel(wrapper);

            expect(wrapper.text()).toContain('Trainer ID');
            expect(wrapper.text()).toContain('${trainer_id}');
            expect(shortInputs(wrapper)[0].attributes('maxlength')).toBe('100');
        });

        it('tells a formatted field how to format, and a plain one nothing', async () => {
            /*
             * This is where per-class values are actually typed — a hint that
             * lives only on the training's field editor leaves the person
             * entering an endorsement here unable to know `**bold**` works.
             */
            const { wrapper } = mountPanel(
                topic({
                    card_fields: [
                        field({ id: 'f1', key: 'notes', type: 'rich' }),
                        field({ id: 'f2' }),
                    ],
                }),
            );
            await openPanel(wrapper);

            const hints = wrapper.findAll(
                '[data-testid="card-value-format-hint"]',
            );

            expect(hints).toHaveLength(1);
            expect(hints[0].text()).toContain('**bold**');
        });

        it('says so when the training defines none', async () => {
            const { wrapper } = mountPanel();
            await openPanel(wrapper);

            expect(wrapper.text()).toContain('No card fields');
        });
    });

    describe('its two inner boxes', () => {
        it('shows the card fields and keeps the certificate rolled up', async () => {
            // The certificate carries an editor and a live preview, so it is
            // by far the tallest thing here and the least often wanted. Card
            // fields are short and are usually what the panel was opened for.
            const { wrapper } = mountPanel(
                topic({ card_fields: [field({ id: 'f1' })] }),
            );
            await openPanel(wrapper);

            expect(shortInputs(wrapper)).toHaveLength(1);
            expect(
                wrapper.find('[data-testid="topic-cert-code"]').exists(),
            ).toBe(false);
        });

        it('rolls the certificate open on request', async () => {
            const { wrapper } = mountPanel();
            await openCert(wrapper);

            expect(
                wrapper.find('[data-testid="topic-cert-code"]').exists(),
            ).toBe(true);
            expect(wrapper.find('[data-testid="cert-title"]').exists()).toBe(
                true,
            );
        });

        it('names the topic on its certificate preview', async () => {
            // A class shows one of these per topic, so a bare "Enlarge" would
            // be ambiguous the moment there are two on screen.
            const { wrapper } = mountPanel();
            await openCert(wrapper);

            expect(
                wrapper
                    .get('[data-testid="cert-preview-open"]')
                    .attributes('aria-label'),
            ).toContain('First Aid / CPR');
        });

        it('rolls the card fields shut on request', async () => {
            const { wrapper } = mountPanel(
                topic({ card_fields: [field({ id: 'f1' })] }),
            );
            await openPanel(wrapper);

            await wrapper
                .get('[data-testid="card-fields-toggle"]')
                .trigger('click');

            expect(shortInputs(wrapper)).toHaveLength(0);
        });

        it('keeps an edit made in a box that was rolled shut again', async () => {
            // The boxes hide fields; they must not discard them. One Save
            // covers the whole topic, so a value typed and then tucked away
            // still has to be in the request.
            const { wrapper, store } = mountPanel();
            const save = vi
                .spyOn(store, 'updateTopic')
                .mockResolvedValue(detail());
            await openCert(wrapper);

            await wrapper
                .get('[data-testid="topic-cert-code"]')
                .setValue('FA-2');
            await wrapper.get('[data-testid="cert-toggle"]').trigger('click');
            await wrapper.get('[data-testid="topic-save"]').trigger('click');
            await flushPromises();

            expect(save).toHaveBeenCalledWith(
                'c1',
                'ct1',
                expect.objectContaining({ cert_code: 'FA-2' }),
            );
        });

        it('summarises each box while it is shut', async () => {
            const { wrapper } = mountPanel(
                topic({
                    cert_title: 'First Aid Certificate',
                    card_fields: [field({ id: 'f1' }), field({ id: 'f2' })],
                }),
            );
            await openPanel(wrapper);

            expect(wrapper.text()).toContain('First Aid Certificate');
            expect(wrapper.text()).toContain('2 fields');
        });
    });

    describe('saving', () => {
        it('sends every field the panel owns in one request', async () => {
            const { wrapper, store } = mountPanel(
                topic({ card_fields: [field({ id: 'f1' })] }),
            );
            const save = vi
                .spyOn(store, 'updateTopic')
                .mockResolvedValue(detail());
            await openPanel(wrapper);

            await shortInputs(wrapper)[0].setValue('INST-4471');
            await wrapper.get('[data-testid="topic-hours"]').setValue('8');
            await openCert(wrapper);
            await wrapper
                .get('[data-testid="topic-cert-code"]')
                .setValue('FA-2');
            await wrapper.get('[data-testid="topic-save"]').trigger('click');
            await flushPromises();

            // One PATCH, not four: the endpoint merges partial payloads, and
            // four requests would leave the topic half-saved on any failure.
            expect(save).toHaveBeenCalledTimes(1);
            expect(save).toHaveBeenCalledWith('c1', 'ct1', {
                hours: 8,
                expire_date: null,
                cert_title: 'First Aid',
                cert_text: 'Satisfies **Cal/OSHA**.',
                cert_code: 'FA-2',
                card_values: { f1: 'INST-4471' },
            });
        });

        it('sends an emptied card answer as a blank, which clears it', async () => {
            const { wrapper, store } = mountPanel(
                topic({
                    card_fields: [field({ id: 'f1', value: 'INST-4471' })],
                }),
            );
            const save = vi
                .spyOn(store, 'updateTopic')
                .mockResolvedValue(detail());
            await openPanel(wrapper);

            await shortInputs(wrapper)[0].setValue('');
            await wrapper.get('[data-testid="topic-save"]').trigger('click');
            await flushPromises();

            expect(save).toHaveBeenCalledWith(
                'c1',
                'ct1',
                expect.objectContaining({ card_values: { f1: '' } }),
            );
        });

        it('blanks an emptied certificate field rather than saving ""', async () => {
            const { wrapper, store } = mountPanel();
            const save = vi
                .spyOn(store, 'updateTopic')
                .mockResolvedValue(detail());
            await openCert(wrapper);

            await wrapper.get('[data-testid="topic-cert-code"]').setValue('');
            await wrapper.get('[data-testid="topic-save"]').trigger('click');
            await flushPromises();

            expect(save).toHaveBeenCalledWith(
                'c1',
                'ct1',
                expect.objectContaining({ cert_code: null }),
            );
        });

        it('reports a failed save and keeps what was typed', async () => {
            const { wrapper, store } = mountPanel();
            vi.spyOn(store, 'updateTopic').mockRejectedValue(
                new Error('nope'),
            );
            await openCert(wrapper);

            await wrapper
                .get('[data-testid="topic-cert-code"]')
                .setValue('FA-2');
            await wrapper.get('[data-testid="topic-save"]').trigger('click');
            await flushPromises();

            expect(wrapper.text()).toContain('nope');
            expect(
                wrapper.get<HTMLInputElement>('[data-testid="topic-cert-code"]')
                    .element.value,
            ).toBe('FA-2');
        });
    });

    describe('a completed class', () => {
        it('shows the values but locks them, and says why', async () => {
            const { wrapper } = mountPanel(
                topic({ card_fields: [field({ id: 'f1', value: 'INST-4471' })] }),
                { readOnly: true },
            );
            await openPanel(wrapper);

            expect(shortInputs(wrapper)[0].attributes('disabled')).toBeDefined();
            expect(
                wrapper.get('[data-testid="topic-expire-date"]').attributes(
                    'disabled',
                ),
            ).toBeDefined();
            expect(wrapper.find('[data-testid="topic-save"]').exists()).toBe(
                false,
            );
            expect(wrapper.text()).toContain('Reopen');
        });
    });
});
