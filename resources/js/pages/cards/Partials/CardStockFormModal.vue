<script setup lang="ts">
/*
 * Admin+ add/edit dialog for a card stock — the printable geometry of a
 * purchased card sheet. Lengths are typed in inches or millimetres and sent
 * as points (the API's unit); the live preview draws the grid so a wrong
 * margin or gutter is obvious before anything is printed.
 *
 * The overflow check mirrors the server's, which is authoritative — this one
 * exists to catch the mistake without a round trip.
 */
import { computed, reactive, ref, watch } from 'vue';
import CardSheetPreview from '@/components/CardSheetPreview.vue';
import ErrorBanner from '@/components/ErrorBanner.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useFieldErrors } from '@/composables/useFieldErrors';
import { fromPoints, perSheet, sheetFits, toPoints } from '@/lib/cardGeometry';
import type { CardGrid, LengthUnit } from '@/lib/cardGeometry';
import { useCardStocksStore } from '@/stores/cardStocks';
import type { CardStockRow } from '@/stores/cardStocks';
import { useErrorStore } from '@/stores/errors';

const FORM_CTX = 'form:card-stock';

/** Every length the form edits, in the display unit. */
const LENGTHS = [
    'page_width',
    'page_height',
    'card_width',
    'card_height',
    'margin_top',
    'margin_left',
    'gutter_x',
    'gutter_y',
    'offset_x',
    'offset_y',
] as const;

const props = defineProps<{ open: boolean; editing: CardStockRow | null }>();
const emit = defineEmits<{ (e: 'update:open', v: boolean): void }>();

const store = useCardStocksStore();
const errorStore = useErrorStore();
const fieldErrors = useFieldErrors(FORM_CTX);

const unit = ref<LengthUnit>('in');
const submitting = ref(false);

/** US letter with a 10-up wallet grid — the shape this feature exists for. */
const BLANK = {
    name: '',
    page_width: 612,
    page_height: 792,
    card_width: 243,
    card_height: 153,
    margin_top: 27,
    margin_left: 63,
    gutter_x: 0,
    gutter_y: 0,
    offset_x: 0,
    offset_y: 0,
    column_count: 2,
    row_count: 5,
    duplex_flip: '' as '' | 'long_edge' | 'short_edge',
    notes: '',
};

/** Lengths live here in the DISPLAY unit; counts and text as typed. */
const form = reactive({ ...BLANK });

function seed(): void {
    const source = props.editing;

    form.name = source?.name ?? BLANK.name;
    form.column_count = source?.column_count ?? BLANK.column_count;
    form.row_count = source?.row_count ?? BLANK.row_count;
    form.duplex_flip = source?.duplex_flip ?? '';
    form.notes = source?.notes ?? '';

    for (const key of LENGTHS) {
        form[key] = fromPoints(source?.[key] ?? BLANK[key], unit.value);
    }
}

watch(
    () => [props.open, props.editing] as const,
    ([open]) => {
        if (!open) {
            return;
        }

        unit.value = 'in';
        errorStore.clear(FORM_CTX);
        seed();
    },
    { immediate: true },
);

/** Switching the ruler must not resize the sheet. */
function useUnit(next: LengthUnit): void {
    if (next === unit.value) {
        return;
    }

    for (const key of LENGTHS) {
        form[key] = fromPoints(
            toPoints(Number(form[key]) || 0, unit.value),
            next,
        );
    }

    unit.value = next;
}

/** The form's numbers as the geometry helpers want them: points. */
const grid = computed<CardGrid>(() => ({
    page_width: toPoints(Number(form.page_width) || 0, unit.value),
    page_height: toPoints(Number(form.page_height) || 0, unit.value),
    card_width: toPoints(Number(form.card_width) || 0, unit.value),
    card_height: toPoints(Number(form.card_height) || 0, unit.value),
    margin_top: toPoints(Number(form.margin_top) || 0, unit.value),
    margin_left: toPoints(Number(form.margin_left) || 0, unit.value),
    gutter_x: toPoints(Number(form.gutter_x) || 0, unit.value),
    gutter_y: toPoints(Number(form.gutter_y) || 0, unit.value),
    offset_x: toPoints(Number(form.offset_x) || 0, unit.value),
    offset_y: toPoints(Number(form.offset_y) || 0, unit.value),
    column_count: Math.max(0, Math.trunc(Number(form.column_count) || 0)),
    row_count: Math.max(0, Math.trunc(Number(form.row_count) || 0)),
}));

const cards = computed(() => perSheet(grid.value));
const fits = computed(() => sheetFits(grid.value));

const title = computed(() =>
    props.editing ? 'Edit card stock' : 'New card stock',
);

async function submit(): Promise<void> {
    if (!fits.value) {
        return;
    }

    submitting.value = true;
    errorStore.clear(FORM_CTX);

    const payload = {
        ...grid.value,
        name: form.name.trim(),
        duplex_flip: form.duplex_flip === '' ? null : form.duplex_flip,
        notes: form.notes.trim() === '' ? null : form.notes.trim(),
    };

    try {
        if (props.editing) {
            await store.update(props.editing.id, payload);
        } else {
            await store.create(payload);
        }

        emit('update:open', false);
    } catch (e) {
        errorStore.reportFromAxios(e, FORM_CTX, {
            fallback: 'Failed to save the card stock.',
        });
    } finally {
        submitting.value = false;
    }
}
</script>

<template>
    <Dialog :open="open" @update:open="(v) => emit('update:open', v)">
        <DialogContent
            class="max-h-[90vh] w-[92vw] overflow-y-auto sm:max-w-4xl"
        >
            <form @submit.prevent="submit" class="space-y-4">
                <DialogHeader>
                    <DialogTitle>{{ title }}</DialogTitle>
                    <DialogDescription>
                        The measurements of a purchased card sheet. Cards are
                        placed on this grid exactly as entered — take them from
                        the packaging, then fine-tune the gutters against a test
                        print.
                    </DialogDescription>
                </DialogHeader>

                <ErrorBanner :context="FORM_CTX" />

                <div class="grid gap-4 lg:grid-cols-2">
                    <!-- Editor -->
                    <div class="space-y-3">
                        <div class="grid gap-2">
                            <Label for="cs_name">Name</Label>
                            <Input
                                id="cs_name"
                                v-model="form.name"
                                placeholder="e.g. Avery 5371 — 10-up wallet"
                                required
                            />
                            <InputError
                                :message="fieldErrors.message('name')"
                            />
                        </div>

                        <div class="flex items-center gap-2 text-xs">
                            <span class="text-muted-foreground">Units</span>
                            <button
                                v-for="u in ['in', 'mm'] as const"
                                :key="u"
                                type="button"
                                :data-testid="`unit-${u}`"
                                class="rounded px-2 py-0.5 ring-1 ring-inset"
                                :class="
                                    unit === u
                                        ? 'bg-muted text-foreground ring-border'
                                        : 'text-muted-foreground ring-border'
                                "
                                @click="useUnit(u)"
                            >
                                {{ u }}
                            </button>
                        </div>

                        <div class="grid grid-cols-2 gap-3">
                            <div class="grid gap-2">
                                <Label for="cs_page_width">
                                    Page width ({{ unit }})
                                </Label>
                                <Input
                                    id="cs_page_width"
                                    v-model="form.page_width"
                                    type="number"
                                    step="any"
                                />
                                <InputError
                                    :message="fieldErrors.message('page_width')"
                                />
                            </div>
                            <div class="grid gap-2">
                                <Label for="cs_page_height">
                                    Page height ({{ unit }})
                                </Label>
                                <Input
                                    id="cs_page_height"
                                    v-model="form.page_height"
                                    type="number"
                                    step="any"
                                />
                                <InputError
                                    :message="
                                        fieldErrors.message('page_height')
                                    "
                                />
                            </div>
                            <div class="grid gap-2">
                                <Label for="cs_card_width">
                                    Card width ({{ unit }})
                                </Label>
                                <Input
                                    id="cs_card_width"
                                    v-model="form.card_width"
                                    type="number"
                                    step="any"
                                />
                                <InputError
                                    :message="fieldErrors.message('card_width')"
                                />
                            </div>
                            <div class="grid gap-2">
                                <Label for="cs_card_height">
                                    Card height ({{ unit }})
                                </Label>
                                <Input
                                    id="cs_card_height"
                                    v-model="form.card_height"
                                    type="number"
                                    step="any"
                                />
                                <InputError
                                    :message="
                                        fieldErrors.message('card_height')
                                    "
                                />
                            </div>
                            <div class="grid gap-2">
                                <Label for="cs_column_count">Columns</Label>
                                <Input
                                    id="cs_column_count"
                                    v-model="form.column_count"
                                    type="number"
                                    min="1"
                                    step="1"
                                />
                                <InputError
                                    :message="
                                        fieldErrors.message('column_count')
                                    "
                                />
                            </div>
                            <div class="grid gap-2">
                                <Label for="cs_row_count">Rows</Label>
                                <Input
                                    id="cs_row_count"
                                    v-model="form.row_count"
                                    type="number"
                                    min="1"
                                    step="1"
                                />
                                <InputError
                                    :message="fieldErrors.message('row_count')"
                                />
                            </div>
                            <div class="grid gap-2">
                                <Label for="cs_margin_left">
                                    Left margin ({{ unit }})
                                </Label>
                                <Input
                                    id="cs_margin_left"
                                    v-model="form.margin_left"
                                    type="number"
                                    step="any"
                                />
                            </div>
                            <div class="grid gap-2">
                                <Label for="cs_margin_top">
                                    Top margin ({{ unit }})
                                </Label>
                                <Input
                                    id="cs_margin_top"
                                    v-model="form.margin_top"
                                    type="number"
                                    step="any"
                                />
                            </div>
                            <div class="grid gap-2">
                                <Label for="cs_gutter_x">
                                    Gap between columns ({{ unit }})
                                </Label>
                                <Input
                                    id="cs_gutter_x"
                                    v-model="form.gutter_x"
                                    type="number"
                                    step="any"
                                />
                            </div>
                            <div class="grid gap-2">
                                <Label for="cs_gutter_y">
                                    Gap between rows ({{ unit }})
                                </Label>
                                <Input
                                    id="cs_gutter_y"
                                    v-model="form.gutter_y"
                                    type="number"
                                    step="any"
                                />
                            </div>
                        </div>

                        <!-- Calibration (C6a): the whole-sheet nudge for a
                             printer that lands the image slightly off the
                             paper. Its own block, after the grid — these two
                             describe the PRINTER, not the stock. -->
                        <div
                            class="space-y-2 rounded-md border border-border p-3"
                        >
                            <p class="text-sm font-medium">
                                Printer calibration
                            </p>
                            <p class="text-xs text-muted-foreground">
                                Print the calibration sheet, measure how far the
                                marks sit from the card edges, and enter the
                                shift here. Positive moves every card right /
                                down; negative moves left / up.
                            </p>
                            <div class="grid gap-4 sm:grid-cols-2">
                                <div class="grid gap-2">
                                    <Label for="cs_offset_x">
                                        Shift right ({{ unit }})
                                    </Label>
                                    <Input
                                        id="cs_offset_x"
                                        v-model="form.offset_x"
                                        data-testid="stock-offset-x"
                                        type="number"
                                        step="any"
                                    />
                                    <InputError
                                        :message="
                                            fieldErrors.message('offset_x')
                                        "
                                    />
                                </div>
                                <div class="grid gap-2">
                                    <Label for="cs_offset_y">
                                        Shift down ({{ unit }})
                                    </Label>
                                    <Input
                                        id="cs_offset_y"
                                        v-model="form.offset_y"
                                        data-testid="stock-offset-y"
                                        type="number"
                                        step="any"
                                    />
                                    <InputError
                                        :message="
                                            fieldErrors.message('offset_y')
                                        "
                                    />
                                </div>
                            </div>
                        </div>

                        <div class="grid gap-2">
                            <Label for="cs_duplex">Duplex flip</Label>
                            <select
                                id="cs_duplex"
                                v-model="form.duplex_flip"
                                class="h-9 rounded-md border border-input bg-background px-2 text-sm"
                            >
                                <option value="">Single-sided / not set</option>
                                <option value="long_edge">
                                    Flip on the long edge
                                </option>
                                <option value="short_edge">
                                    Flip on the short edge
                                </option>
                            </select>
                            <p class="text-xs text-muted-foreground">
                                Only used by two-slide (front/back) templates,
                                to line the backs up with the right cards.
                            </p>
                        </div>

                        <div class="grid gap-2">
                            <Label for="cs_notes">Notes</Label>
                            <textarea
                                id="cs_notes"
                                v-model="form.notes"
                                rows="2"
                                class="w-full rounded border border-input bg-background p-2 text-sm"
                                placeholder="e.g. load the sheet face-up, top edge first"
                            ></textarea>
                        </div>
                    </div>

                    <!-- Live preview -->
                    <div class="space-y-2 lg:sticky lg:top-4">
                        <p
                            class="text-xs font-medium text-muted-foreground"
                            data-testid="per-sheet"
                        >
                            Preview — {{ cards }} per sheet
                        </p>

                        <div
                            v-if="!fits"
                            data-testid="overflow-warning"
                            class="rounded border border-amber-300 bg-amber-50 p-2 text-xs text-amber-900"
                        >
                            This grid runs off the page. Reduce the columns,
                            rows, card size, margin or gaps until it fits.
                        </div>

                        <CardSheetPreview :grid="grid" />
                    </div>
                </div>

                <DialogFooter>
                    <Button
                        type="button"
                        variant="outline"
                        @click="emit('update:open', false)"
                    >
                        Cancel
                    </Button>
                    <Button type="submit" :disabled="submitting || !fits">
                        Save stock
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
