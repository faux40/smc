<script setup lang="ts">
/**
 * Print one class topic's cards onto a sheet of purchased stock (custom-certs
 * C4e). One run is one topic — design, custom fields and usually the stock all
 * differ per training — so the topic arrives as a prop and there is no picker
 * to get wrong.
 *
 * The job that does the work is deterministic and does not retry, and the
 * stock it prints onto was bought by the box. So this dialog's real work is
 * saying what will happen *before* anything is queued: how many cards, how
 * many sheets, which values each `${key}` resolves to, and every reason the
 * result might not be what the operator expects.
 */
import { computed, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import CardSheetPreview from '@/components/CardSheetPreview.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import type { CardFieldWithValue } from '@/lib/cardFields';
import { fitsCell, sheetCount } from '@/lib/cardGeometry';
import { useCardPrintRunsStore } from '@/stores/cardPrintRuns';
import { useCardStocksStore } from '@/stores/cardStocks';
import { useCardTemplatesStore } from '@/stores/cardTemplates';
import { useClassesStore } from '@/stores/classes';
import { useTrainingsStore } from '@/stores/trainings';

const props = defineProps<{
    open: boolean;
    classId: string;
    topicId: string | null;
}>();
const emit = defineEmits<{ (e: 'update:open', v: boolean): void }>();

const classes = useClassesStore();
const templates = useCardTemplatesStore();
const stocks = useCardStocksStore();
const trainings = useTrainingsStore();
const runs = useCardPrintRunsStore();

const detail = computed(() => classes.detail[props.classId] ?? null);

const topic = computed(
    () => detail.value?.trainings.find((t) => t.id === props.topicId) ?? null,
);

/*
 * Only certificate holders get a card, exactly as CardMergeData decides it:
 * a completion without a cert_id was never issued one, so there is nothing to
 * print for that person.
 */
const cardCount = computed(
    () => topic.value?.credits.filter((c) => c.cert_id).length ?? 0,
);

const templateId = ref<string>('');
const stockId = ref<string>('');
const startCell = ref(1);
const includeBacks = ref(false);
/** Print only the first card (C6b) — a positioning check before the batch. */
const proof = ref(false);
const submitting = ref(false);
const actionError = ref<string | null>(null);

/**
 * What this run will actually print. The counts, the sheet arithmetic and
 * the submit gate all read this one — a proof beside "12 cards · 2 sheets"
 * would look like it burns them.
 */
const effectiveCount = computed(() =>
    proof.value ? Math.min(1, cardCount.value) : cardCount.value,
);

/** The design the training carries — the default, overridable per run. */
const trainingTemplateId = computed(() => {
    const trainingId = topic.value?.training_id;

    if (!trainingId) {
        return null;
    }

    return (
        trainings.library.find((t) => t.id === trainingId)?.card_template_id ??
        null
    );
});

/**
 * The stock the training carries, likewise — but only if this org can still
 * see it. Deleting a stock detaches it from trainings, so a named-but-missing
 * id means a stale payload; falling back to asking beats pre-selecting an id
 * the picker can't show and the server would reject.
 */
const trainingStockId = computed(() => {
    const trainingId = topic.value?.training_id;

    if (!trainingId) {
        return null;
    }

    const stockId = trainings.library.find((t) => t.id === trainingId)
        ?.card_stock_id;

    return stockId && stocks.library.some((s) => s.id === stockId)
        ? stockId
        : null;
});

const template = computed(
    () => templates.library.find((t) => t.id === templateId.value) ?? null,
);

const stock = computed(
    () => stocks.library.find((s) => s.id === stockId.value) ?? null,
);

/**
 * null = the start cell is off the chosen stock's sheet, which happens by
 * picking a late cell and then switching to a smaller stock.
 */
const sheets = computed(() =>
    stock.value
        ? sheetCount(stock.value, effectiveCount.value, startCell.value)
        : null,
);

const startCellValid = computed(() => !stock.value || sheets.value !== null);

/** The design overhangs its cell. A warning: cards are never scaled to fit. */
const overhangs = computed(
    () =>
        template.value !== null &&
        stock.value !== null &&
        !fitsCell(
            template.value.card_width,
            template.value.card_height,
            stock.value,
        ),
);

const fontWarnings = computed(() => template.value?.unsupported_fonts ?? []);

const readOnlyValues = computed(() => detail.value?.status === 'completed');

/**
 * What each custom `${key}` will actually print, and where it came from —
 * mirroring the server's `answer ?? default ?? ''`. A stored answer is never
 * an empty string (clearing one deletes the row), so a present value always
 * means somebody typed it for this class.
 *
 * The source is the point. A card printing the training's default instructor
 * looks entirely correct; only the provenance reveals that this class's real
 * instructor was never entered — and by print time the class is closed and
 * those values are locked.
 */
const resolvedValues = computed(() =>
    (topic.value?.card_fields ?? []).map((f: CardFieldWithValue) => {
        if (f.value !== null && f.value !== '') {
            return { field: f, value: f.value, source: 'class' as const };
        }

        if (f.default_value !== null && f.default_value !== '') {
            return {
                field: f,
                value: f.default_value,
                source: 'default' as const,
            };
        }

        return { field: f, value: '', source: 'blank' as const };
    }),
);

const canSubmit = computed(
    () =>
        templateId.value !== '' &&
        stockId.value !== '' &&
        cardCount.value > 0 &&
        startCellValid.value &&
        !submitting.value,
);

// (Re)seed on open, and whenever the topic changes underneath us.
watch(
    () => [props.open, props.topicId] as const,
    ([open]) => {
        if (!open) {
            return;
        }

        templateId.value = trainingTemplateId.value ?? '';
        // From the training, the same way the design is. Still never guessed
        // — an org with exactly one stock gets no default unless a training
        // actually names it, because which sheet is in the printer is not
        // something to infer from how few there are to choose from.
        stockId.value = trainingStockId.value ?? '';
        startCell.value = 1;
        includeBacks.value = template.value?.has_back ?? false;
        proof.value = false;
        actionError.value = null;
    },
    { immediate: true },
);

/*
 * The stocks are fetched *after* this dialog opens (the class page opens it,
 * then awaits the fetch), so the seed above usually runs against an empty
 * library and finds nothing. Fill it in when they land — but only into an
 * empty picker, so a late arrival never reaches in and changes a stock the
 * user already chose.
 */
watch(trainingStockId, (id) => {
    if (props.open && id !== null && stockId.value === '') {
        stockId.value = id;
    }
});

// Backs follow the design: asking for them on a single-sided card is a no-op
// server-side, so the checkbox shouldn't pretend otherwise.
watch(template, (t) => {
    includeBacks.value = t?.has_back ?? false;
});

function chooseTemplate(value: string): void {
    templateId.value = value;
}

function chooseStock(value: string): void {
    stockId.value = value;
}

async function submit(): Promise<void> {
    if (!canSubmit.value || !props.topicId) {
        return;
    }

    submitting.value = true;
    actionError.value = null;

    try {
        await runs.create(props.classId, {
            class_training_id: props.topicId,
            card_template_id: templateId.value,
            card_stock_id: stockId.value,
            start_cell: startCell.value,
            include_backs: includeBacks.value,
            proof: proof.value,
        });

        toast.success(
            proof.value
                ? 'Printing a proof card — it will appear in Documents when ready.'
                : 'Printing cards — the sheets will appear in Documents when they are ready.',
        );
        emit('update:open', false);
    } catch (e) {
        actionError.value =
            (e as { response?: { data?: { message?: string } } }).response?.data
                ?.message ?? (e as Error).message;
    } finally {
        submitting.value = false;
    }
}
</script>

<template>
    <Dialog :open="open" @update:open="(v) => emit('update:open', v)">
        <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-5xl">
            <DialogHeader>
                <DialogTitle>Print cards</DialogTitle>
                <DialogDescription>
                    Cards for
                    <span class="font-medium">{{
                        topic?.training_name ?? 'this topic'
                    }}</span>
                    — one per person who was issued a certificate. The sheets
                    are filed to this class's documents when they're ready.
                </DialogDescription>
            </DialogHeader>

            <p
                v-if="actionError"
                data-testid="print-error"
                class="rounded bg-red-50 p-2 text-sm text-red-800"
            >
                {{ actionError }}
            </p>

            <div class="grid gap-6 lg:grid-cols-2">
                <!-- What to print -->
                <div class="space-y-4">
                    <p data-testid="card-count" class="text-sm">
                        <span class="font-medium"
                            >{{ effectiveCount }}
                            {{ effectiveCount === 1 ? 'card' : 'cards' }}</span
                        >
                        <span
                            v-if="sheets !== null"
                            data-testid="sheet-count"
                            class="text-muted-foreground"
                        >
                            → {{ sheets }}
                            {{ sheets === 1 ? 'sheet' : 'sheets' }}
                        </span>
                    </p>

                    <p
                        v-if="cardCount === 0"
                        data-testid="no-cards"
                        class="rounded bg-amber-50 p-2 text-sm text-amber-900"
                    >
                        Nobody on this class holds a certificate for this topic,
                        so there are no cards to print.
                    </p>

                    <div class="grid gap-2">
                        <Label for="print_template">Card design</Label>
                        <select
                            id="print_template"
                            data-testid="print-template"
                            class="h-9 rounded-md border border-input bg-background px-2 text-sm"
                            :value="templateId"
                            @change="
                                chooseTemplate(
                                    ($event.target as HTMLSelectElement).value,
                                )
                            "
                        >
                            <option value="">Pick a design…</option>
                            <option
                                v-for="t in templates.library"
                                :key="t.id"
                                :value="t.id"
                            >
                                {{ t.name }}
                            </option>
                        </select>
                        <p
                            v-if="templateId === ''"
                            class="text-xs text-muted-foreground"
                        >
                            This training has no card design of its own. Pick
                            one for this run, or assign one on the training.
                        </p>
                    </div>

                    <div class="grid gap-2">
                        <Label for="print_stock">Card stock</Label>
                        <select
                            id="print_stock"
                            data-testid="print-stock"
                            class="h-9 rounded-md border border-input bg-background px-2 text-sm"
                            :value="stockId"
                            @change="
                                chooseStock(
                                    ($event.target as HTMLSelectElement).value,
                                )
                            "
                        >
                            <option value="">
                                Pick the sheet you'll print on…
                            </option>
                            <option
                                v-for="s in stocks.library"
                                :key="s.id"
                                :value="s.id"
                            >
                                {{ s.name }} ({{ s.per_sheet }} per sheet)
                            </option>
                        </select>
                    </div>

                    <label class="flex items-center gap-2 text-sm">
                        <input
                            type="checkbox"
                            data-testid="print-backs"
                            v-model="includeBacks"
                            :disabled="!template?.has_back"
                            class="size-4"
                        />
                        <span
                            :class="
                                template?.has_back
                                    ? ''
                                    : 'text-muted-foreground'
                            "
                        >
                            Also print the backs
                            <template v-if="!template?.has_back">
                                (this design is single-sided)
                            </template>
                        </span>
                    </label>

                    <!-- C6b: one real card through the real pipeline before a
                         whole sheet of stock is committed to it. -->
                    <label class="flex items-start gap-2 text-sm">
                        <input
                            type="checkbox"
                            data-testid="print-proof"
                            v-model="proof"
                            class="mt-0.5 size-4"
                        />
                        <span>
                            Proof: first card only
                            <span class="block text-xs text-muted-foreground">
                                Prints one card at the chosen start cell to
                                check fit and position — same design, same
                                values — before running the whole class.
                            </span>
                        </span>
                    </label>

                    <p
                        v-if="overhangs"
                        data-testid="size-warning"
                        class="rounded bg-amber-50 p-2 text-sm text-amber-900"
                    >
                        The design is larger than this stock's cell, so cards
                        will overhang into the gutter — they are never scaled to
                        fit. Check the design's page size against the sheet.
                    </p>

                    <p
                        v-if="fontWarnings.length"
                        data-testid="font-warning"
                        class="rounded bg-amber-50 p-2 text-sm text-amber-900"
                    >
                        This design asks for
                        {{ fontWarnings.join(', ') }}, which isn't installed for
                        the converter — the text will be re-flowed in a
                        substitute face.
                    </p>
                </div>

                <!-- Where on the sheet -->
                <div class="space-y-2">
                    <p class="text-sm font-medium">Start at</p>
                    <p class="text-xs text-muted-foreground">
                        Click the first free cell on the sheet you're loading.
                        Earlier cells are left empty.
                    </p>

                    <p
                        v-if="!startCellValid"
                        data-testid="start-cell-error"
                        class="rounded bg-red-50 p-2 text-sm text-red-800"
                    >
                        This stock has {{ stock?.per_sheet }} cards per sheet —
                        pick a starting cell on it.
                    </p>

                    <CardSheetPreview
                        v-if="stock"
                        :grid="stock"
                        selectable
                        :selected="startCell"
                        @select="startCell = $event"
                    />
                    <p v-else class="text-sm text-muted-foreground">
                        Pick a card stock to choose where on the sheet to start.
                    </p>
                </div>
            </div>

            <!-- What the ${keys} will resolve to -->
            <div
                v-if="resolvedValues.length"
                class="space-y-2 border-t border-border pt-4"
            >
                <h3 class="text-sm font-semibold">Values that will print</h3>

                <p
                    v-if="readOnlyValues"
                    data-testid="values-locked"
                    class="text-xs text-muted-foreground"
                >
                    This class is completed, so these are locked. Reopen it to
                    change them.
                </p>

                <ul class="space-y-1 text-sm">
                    <li
                        v-for="row in resolvedValues"
                        :key="row.field.id"
                        data-testid="value-row"
                        class="flex flex-wrap items-baseline gap-2"
                    >
                        <code class="text-xs text-muted-foreground">{{
                            row.field.placeholder
                        }}</code>
                        <span
                            v-if="row.source === 'blank'"
                            class="text-amber-700"
                        >
                            blank
                        </span>
                        <span v-else class="font-medium">{{ row.value }}</span>
                        <span class="text-xs text-muted-foreground">
                            <template v-if="row.source === 'class'"
                                >from this class</template
                            >
                            <template v-else-if="row.source === 'default'"
                                >training default</template
                            >
                            <template v-else>no answer, no default</template>
                        </span>
                    </li>
                </ul>
            </div>

            <DialogFooter>
                <Button
                    type="button"
                    variant="outline"
                    @click="emit('update:open', false)"
                >
                    Cancel
                </Button>
                <Button
                    type="button"
                    data-testid="print-cards-submit"
                    :disabled="!canSubmit"
                    @click="submit"
                >
                    {{ submitting ? 'Queueing…' : 'Print cards' }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
