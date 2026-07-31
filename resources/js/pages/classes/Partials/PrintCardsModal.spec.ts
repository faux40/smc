import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { CardFieldWithValue } from '@/lib/cardFields';
import { useCardPrintRunsStore } from '@/stores/cardPrintRuns';
import { useCardStocksStore } from '@/stores/cardStocks';
import type { CardStockRow } from '@/stores/cardStocks';
import { useCardTemplatesStore } from '@/stores/cardTemplates';
import type { CardTemplateRow } from '@/stores/cardTemplates';
import { useClassesStore } from '@/stores/classes';
import type {
    ClassDetail,
    ClassTrainingRow,
    TopicCredit,
} from '@/stores/classes';
import { useTrainingsStore } from '@/stores/trainings';
import type { TrainingRow } from '@/stores/trainings';
import PrintCardsModal from './PrintCardsModal.vue';

vi.mock('axios');
const toastSuccess = vi.fn();
vi.mock('vue-sonner', () => ({
    toast: {
        success: (...args: unknown[]) => toastSuccess(...args),
        error: vi.fn(),
    },
}));

function credit(overrides: Partial<TopicCredit> = {}): TopicCredit {
    return {
        completion_id: 'comp1',
        user_id: 'u1',
        user_name: 'Reed, Dana',
        cert_id: 'FA-001',
        expire_date: null,
        hours: 4,
        ...overrides,
    };
}

function field(
    overrides: Partial<CardFieldWithValue> & { id: string },
): CardFieldWithValue {
    return {
        key: 'instructor_id',
        placeholder: '${instructor_id}',
        label: 'Instructor ID',
        type: 'short',
        default_value: null,
        max_length: 100,
        seq: 0,
        value: null,
        ...overrides,
    };
}

function template(
    overrides: Partial<CardTemplateRow> & { id: string },
): CardTemplateRow {
    return {
        name: 'CPR card',
        description: null,
        original_filename: 'cpr.pptx',
        extension: 'pptx',
        size: 1024,
        placeholders: ['first_name'],
        fonts: ['Liberation Sans'],
        unsupported_fonts: [],
        slide_count: 1,
        has_back: false,
        card_width: 243,
        card_height: 153,
        version: 1,
        is_system: false,
        can_edit: true,
        can_delete: true,
        updated_at: null,
        ...overrides,
    };
}

/** The 10-up wallet sheet used throughout the card tests. */
function stock(
    overrides: Partial<CardStockRow> & { id: string },
): CardStockRow {
    return {
        name: 'Avery 10-up',
        page_width: 612,
        page_height: 792,
        column_count: 2,
        row_count: 5,
        card_width: 243,
        card_height: 153,
        margin_top: 27,
        margin_left: 63,
        gutter_x: 0,
        gutter_y: 0,
        duplex_flip: null,
        notes: null,
        per_sheet: 10,
        is_system: false,
        can_edit: true,
        can_delete: true,
        ...overrides,
    };
}

function topic(overrides: Partial<ClassTrainingRow> = {}): ClassTrainingRow {
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
        card_fields: [],
        credits: [credit()],
        ...overrides,
    };
}

function training(overrides: Partial<TrainingRow> = {}): TrainingRow {
    return {
        id: 't1',
        name: 'First Aid / CPR',
        nickname: null,
        description: null,
        initial_only: false,
        repeating: true,
        std_freq_id: null,
        std_freq_name: null,
        std_freq_repeat_days: null,
        as_needed: false,
        default_hours: '4.00',
        cert_title: null,
        cert_text: null,
        cert_code: null,
        card_template_id: 'tpl1',
        default_trainer: null,
        default_location: null,
        default_address: null,
        can_edit: true,
        can_delete: true,
        ...overrides,
    } as TrainingRow;
}

function detail(
    t: ClassTrainingRow,
    status: ClassDetail['status'] = 'completed',
): ClassDetail {
    return {
        id: 'c1',
        name: 'CPR — June',
        scheduled_date: '2026-06-01',
        start_time: null,
        end_time: null,
        location: null,
        address: null,
        instructor: 'Rita Alvarez',
        show_signature: false,
        total_hours: '4.00',
        min_students: null,
        max_students: null,
        notes: null,
        status,
        completion_date: '2026-06-01',
        can_edit: status === 'scheduled',
        trainings: [t],
        enrollments: [],
    };
}

interface OpenOptions {
    topic?: ClassTrainingRow;
    status?: ClassDetail['status'];
    templates?: CardTemplateRow[];
    stocks?: CardStockRow[];
    trainings?: TrainingRow[];
}

async function open(options: OpenOptions = {}) {
    setActivePinia(createPinia());

    const classes = useClassesStore();
    classes.detail = { c1: detail(options.topic ?? topic(), options.status) };

    const templates = useCardTemplatesStore();
    templates.library = options.templates ?? [template({ id: 'tpl1' })];
    templates.loaded = true;

    const stocks = useCardStocksStore();
    stocks.library = options.stocks ?? [stock({ id: 's1' })];
    stocks.loaded = true;

    const trainingsStore = useTrainingsStore();
    trainingsStore.library = options.trainings ?? [training()];
    trainingsStore.loaded = true;

    const runs = useCardPrintRunsStore();

    const wrapper = mount(PrintCardsModal, {
        props: { open: true, classId: 'c1', topicId: 'ct1' },
        attachTo: document.body,
    });

    await flushPromises();

    return { wrapper, runs, classes };
}

const el = <T extends Element = HTMLElement>(testId: string) =>
    document.body.querySelector<T>(`[data-testid="${testId}"]`);

const text = (testId: string) => el(testId)?.textContent?.replace(/\s+/g, ' ');

const submitButton = () => el<HTMLButtonElement>('print-cards-submit');

async function chooseStock(id: string): Promise<void> {
    const select = el<HTMLSelectElement>('print-stock')!;
    select.value = id;
    select.dispatchEvent(new Event('change'));
    await flushPromises();
}

describe('PrintCardsModal', () => {
    enableAutoUnmount(afterEach);

    beforeEach(() => {
        vi.clearAllMocks();
        document.body.innerHTML = '';
    });

    it('counts only the people who hold a certificate', async () => {
        // Same rule as CardMergeData: a completion without a cert_id was never
        // issued one, so there is no card to print for it.
        await open({
            topic: topic({
                credits: [
                    credit({ completion_id: 'c1' }),
                    credit({ completion_id: 'c2', cert_id: 'FA-002' }),
                    credit({ completion_id: 'c3', cert_id: null }),
                ],
            }),
        });

        expect(text('card-count')).toContain('2 cards');
    });

    it('starts from the design the training carries', async () => {
        await open();

        expect(el<HTMLSelectElement>('print-template')!.value).toBe('tpl1');
    });

    it('says so when the training has no design of its own', async () => {
        await open({ trainings: [training({ card_template_id: null })] });

        expect(el<HTMLSelectElement>('print-template')!.value).toBe('');
        expect(submitButton()!.disabled).toBe(true);
    });

    it('works out the sheets once a stock is chosen', async () => {
        await open({
            topic: topic({
                credits: Array.from({ length: 12 }, (_, i) =>
                    credit({ completion_id: `c${i}`, cert_id: `FA-${i}` }),
                ),
            }),
        });

        expect(text('sheet-count')).toBeUndefined();

        await chooseStock('s1');

        expect(text('sheet-count')).toContain('2 sheets');
    });

    it('counts the cells a partial sheet has already used', async () => {
        await open({
            topic: topic({
                credits: Array.from({ length: 8 }, (_, i) =>
                    credit({ completion_id: `c${i}`, cert_id: `FA-${i}` }),
                ),
            }),
        });
        await chooseStock('s1');

        expect(text('sheet-count')).toContain('1 sheet');

        // Cell 4 on a 10-up sheet: three already peeled off, so 7 fit here and
        // the eighth opens a second sheet.
        await el('preview-cell')!.parentElement!.children[3].dispatchEvent(
            new Event('click'),
        );
        await flushPromises();

        expect(text('sheet-count')).toContain('2 sheets');
    });

    it('refuses a start cell the newly chosen stock does not have', async () => {
        await open({
            stocks: [
                stock({ id: 's1' }),
                stock({
                    id: 's4',
                    column_count: 2,
                    row_count: 2,
                    per_sheet: 4,
                }),
            ],
        });
        await chooseStock('s1');

        const cells = el('preview-cell')!.parentElement!.children;
        cells[8].dispatchEvent(new Event('click'));
        await flushPromises();

        await chooseStock('s4');

        expect(text('start-cell-error')).toContain('4 cards per sheet');
        expect(submitButton()!.disabled).toBe(true);
    });

    it('blocks a run nobody would get a card from', async () => {
        await open({ topic: topic({ credits: [] }) });
        await chooseStock('s1');

        // The job fails with this sentence minutes later; better to say it
        // while the form is still open.
        expect(text('no-cards')).toContain('holds a certificate');
        expect(submitButton()!.disabled).toBe(true);
    });

    it('warns when the design is bigger than the cell it lands in', async () => {
        await open({
            templates: [template({ id: 'tpl1', card_width: 260 })],
        });
        await chooseStock('s1');

        expect(text('size-warning')).toContain('never scaled');
        // A warning, not a refusal: the stock's own measurements may be the
        // conservative ones.
        expect(submitButton()!.disabled).toBe(false);
    });

    it('warns about fonts the converter would substitute', async () => {
        await open({
            templates: [
                template({ id: 'tpl1', unsupported_fonts: ['Comic Sans MS'] }),
            ],
        });

        expect(text('font-warning')).toContain('Comic Sans MS');
    });

    it('offers backs only when the design has a second side', async () => {
        await open();

        expect(el<HTMLInputElement>('print-backs')!.disabled).toBe(true);
        expect(el<HTMLInputElement>('print-backs')!.checked).toBe(false);
    });

    it('asks for backs by default on a two-sided design', async () => {
        await open({
            templates: [
                template({ id: 'tpl1', has_back: true, slide_count: 2 }),
            ],
        });

        expect(el<HTMLInputElement>('print-backs')!.disabled).toBe(false);
        expect(el<HTMLInputElement>('print-backs')!.checked).toBe(true);
    });

    it('shows which value each custom field will print, and where it came from', async () => {
        await open({
            topic: topic({
                card_fields: [
                    field({ id: 'f1', value: 'Rita', default_value: 'Teri' }),
                    field({
                        id: 'f2',
                        key: 'course_no',
                        label: 'Course number',
                        value: null,
                        default_value: 'CPR-100',
                    }),
                    field({
                        id: 'f3',
                        key: 'notes',
                        label: 'Notes',
                        value: null,
                        default_value: null,
                    }),
                ],
            }),
        });

        const rows = Array.from(
            document.body.querySelectorAll('[data-testid="value-row"]'),
        ).map((r) => r.textContent?.replace(/\s+/g, ' ') ?? '');

        // The whole point: a card that prints the training's default looks
        // perfectly correct, so the source has to be visible.
        expect(rows[0]).toContain('Rita');
        expect(rows[0]).toContain('from this class');
        expect(rows[1]).toContain('CPR-100');
        expect(rows[1]).toContain('training default');
        expect(rows[2]).toContain('blank');
    });

    it('points at the reopen a completed class needs to fix a value', async () => {
        await open({
            topic: topic({
                card_fields: [field({ id: 'f1', default_value: 'Teri' })],
            }),
            status: 'completed',
        });

        expect(text('values-locked')).toContain('Reopen');
    });

    it('queues the run with what was chosen', async () => {
        const { runs, wrapper } = await open({
            templates: [
                template({ id: 'tpl1', has_back: true, slide_count: 2 }),
                template({ id: 'tpl2', name: 'Other' }),
            ],
        });
        const create = vi.spyOn(runs, 'create').mockResolvedValue({} as never);

        const templateSelect = el<HTMLSelectElement>('print-template')!;
        templateSelect.value = 'tpl2';
        templateSelect.dispatchEvent(new Event('change'));
        await chooseStock('s1');

        submitButton()!.click();
        await flushPromises();

        expect(create).toHaveBeenCalledWith('c1', {
            class_training_id: 'ct1',
            card_template_id: 'tpl2',
            card_stock_id: 's1',
            start_cell: 1,
            include_backs: false,
        });
        expect(toastSuccess).toHaveBeenCalled();
        expect(wrapper.emitted('update:open')?.at(-1)).toEqual([false]);
    });

    it('keeps the dialog open and shows why the server refused', async () => {
        const { runs, wrapper } = await open();
        vi.spyOn(runs, 'create').mockRejectedValue({
            response: {
                data: { message: 'This stock has 4 cards per sheet.' },
            },
        });
        await chooseStock('s1');

        submitButton()!.click();
        await flushPromises();

        expect(text('print-error')).toContain('4 cards per sheet');
        expect(wrapper.emitted('update:open')).toBeUndefined();
    });
});
