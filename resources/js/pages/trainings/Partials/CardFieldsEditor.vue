<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import CopyableKey from '@/components/CopyableKey.vue';
import ErrorBanner from '@/components/ErrorBanner.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useFieldErrors } from '@/composables/useFieldErrors';
import { useListDrag } from '@/composables/useListDrag';
import {
    blankCardFieldDraft,
    cardFieldDraftPayload,
    cardFieldKeyErrors,
    seedCardFieldDrafts,
    slugifyCardKey,
} from '@/lib/cardFields';
import type { CardFieldDraft, CardFieldType } from '@/lib/cardFields';
import { moveItem } from '@/lib/reorder';
import { useCardFieldsStore } from '@/stores/cardFields';
import { useErrorStore } from '@/stores/errors';

/**
 * Define a training's custom card fields — the `${keys}` its card design can
 * merge beyond the built-in catalogue.
 *
 * Its own section with its own Save, deliberately outside the training form:
 * this is a set (membership + order), not a row of fields, and it saves as one
 * PUT that states the whole thing.
 */
const props = defineProps<{ trainingId: string }>();

const CONTEXT = 'form:cardFields';

const store = useCardFieldsStore();
const errorStore = useErrorStore();
const fieldErrors = useFieldErrors(CONTEXT);

const drafts = ref<CardFieldDraft[]>([]);
const baseline = ref('');
const saving = ref(false);
/** Row awaiting confirmation of removal, by uid (saved rows only). */
const pendingRemoval = ref<string | null>(null);

const saved = computed(() => store.forTraining(props.trainingId));

/** Reset the form from what the server currently holds. */
function seed(): void {
    drafts.value = seedCardFieldDrafts(saved.value);
    baseline.value = JSON.stringify(cardFieldDraftPayload(drafts.value));
    pendingRemoval.value = null;
}

seed();

onMounted(async () => {
    if (store.isLoaded(props.trainingId)) {
        return;
    }

    try {
        await store.load(props.trainingId);

        // Don't clobber anything typed while the request was in flight.
        if (!isDirty.value) {
            seed();
        }
    } catch (e) {
        errorStore.reportFromAxios(e, CONTEXT, {
            fallback: 'Failed to load card fields',
        });
    }
});

const payload = computed(() => cardFieldDraftPayload(drafts.value));
const isDirty = computed(
    () => JSON.stringify(payload.value) !== baseline.value,
);
const keyErrors = computed(() => cardFieldKeyErrors(drafts.value));
const hasKeyErrors = computed(() => Object.keys(keyErrors.value).length > 0);

/** How many class answers a saved row would discard if removed. */
function answerCount(draft: CardFieldDraft): number {
    return saved.value.find((f) => f.id === draft.id)?.value_count ?? 0;
}

function onLabel(index: number, value: string): void {
    const draft = drafts.value[index];
    draft.label = value;

    // Suggest the key while it's still untouched — and never for a field that
    // already exists, whose key is in templates already.
    if (draft.id === null && !draft.keyTouched) {
        draft.key = slugifyCardKey(value);
    }
}

function onKey(index: number, value: string): void {
    drafts.value[index].key = value;
    drafts.value[index].keyTouched = true;
}

/**
 * The server's ceiling, mirrored so Add stops before a 422 does. Nothing
 * about a card needs 50 fields — it's a runaway guard, not a design budget.
 */
const MAX_FIELDS = 50;

const atCapacity = computed(() => drafts.value.length >= MAX_FIELDS);

function addRow(): void {
    if (atCapacity.value) {
        return;
    }

    drafts.value = [...drafts.value, blankCardFieldDraft('short')];
}

function removeRow(draft: CardFieldDraft): void {
    // A saved field's answers go with it, so say so first.
    if (draft.id !== null) {
        pendingRemoval.value = draft.uid;

        return;
    }

    dropRow(draft);
}

function dropRow(draft: CardFieldDraft): void {
    drafts.value = drafts.value.filter((d) => d.uid !== draft.uid);
    pendingRemoval.value = null;
}

// ---- reordering ------------------------------------------------------
//
// Order is stored as `seq` and drives both the order values are entered on a
// class and the order the card builder lists the merge keys, so it's worth
// arranging. Rows are identified by uid throughout: the array index changes
// under a move, and unsaved rows have no server id.

const uids = computed(() => drafts.value.map((d) => d.uid));

/** A single row has nowhere to go; a dead handle beside it is just noise. */
const reorderable = computed(() => drafts.value.length > 1);

/** Rearrange to match a uid order, dropping any uid that has since gone. */
function applyOrder(order: string[]): void {
    const byUid = new Map(drafts.value.map((d) => [d.uid, d]));

    drafts.value = order.flatMap((uid) => {
        const draft = byUid.get(uid);

        return draft ? [draft] : [];
    });

    // The confirmation names one field; leaving it open while the rows move
    // invites confirming whichever row landed underneath it.
    pendingRemoval.value = null;
}

const { dragKey, overKey, sourceAttrs, targetAttrs } = useListDrag(
    uids,
    applyOrder,
);

/**
 * Reorder from the keyboard, so this isn't a mouse-only feature. The focused
 * handle keeps focus across the move for free: rows are keyed by uid, so Vue
 * moves the existing DOM node rather than rebuilding it.
 */
function onHandleKey(event: KeyboardEvent, index: number): void {
    const delta =
        event.key === 'ArrowUp' ? -1 : event.key === 'ArrowDown' ? 1 : 0;

    if (delta === 0) {
        return;
    }

    // Stop the page scrolling under the row being moved.
    event.preventDefault();

    // moveItem ignores an out-of-range target, so the ends simply hold rather
    // than wrapping around — a wrap would be a surprise, not a convenience.
    applyOrder(moveItem(uids.value, index, index + delta));
}

async function save(): Promise<void> {
    if (hasKeyErrors.value) {
        return;
    }

    saving.value = true;
    errorStore.clear(CONTEXT);

    try {
        await store.sync(props.trainingId, payload.value);
        // Rebuild from the response: the server owns seq and the ids of rows
        // that were just created.
        seed();
    } catch (e) {
        errorStore.reportFromAxios(e, CONTEXT, {
            fallback: 'Failed to save card fields',
        });
    } finally {
        saving.value = false;
    }
}

const TYPE_LABELS: Record<CardFieldType, string> = {
    short: 'Short text',
    rich: 'Formatted text',
};
</script>

<template>
    <section class="max-w-5xl space-y-4 rounded-md border border-border p-4">
        <header class="space-y-1">
            <h2 class="text-sm font-semibold">Card fields</h2>
            <p class="text-xs text-muted-foreground">
                Extra values this training's card design can print — a trainer
                id, an endorsement — on top of the built-in student, class and
                credit fields. Put the merge key in your template where the
                value should appear; each class fills the values in.
            </p>
        </header>

        <ErrorBanner :context="CONTEXT" />

        <p
            v-if="drafts.length === 0"
            class="rounded-md border border-dashed border-border p-4 text-sm text-muted-foreground"
        >
            No card fields yet. Add one for anything this training's card needs
            beyond the built-in student, class and credit values.
        </p>

        <!--
            Column headings once rather than a labelled box per field: with a
            list this long, repeating four labels per row buries the values in
            their own chrome.
        -->
        <div v-if="drafts.length" class="hidden px-2 lg:flex lg:gap-2">
            <!-- Mirrors the handle's footprint so the headings stay over
                 their columns once the rows are indented by one. -->
            <span v-if="reorderable" class="w-6 shrink-0" aria-hidden="true" />
            <div
                class="grid flex-1 gap-3 text-xs font-medium text-muted-foreground lg:grid-cols-12"
            >
                <span class="lg:col-span-3">Label</span>
                <span class="lg:col-span-3">Merge key</span>
                <span class="lg:col-span-2">Type</span>
                <span class="lg:col-span-3">Default value</span>
                <span class="lg:col-span-1"></span>
            </div>
        </div>

        <div class="space-y-2">
            <div
                v-for="(draft, i) in drafts"
                :key="draft.uid"
                v-bind="targetAttrs(draft.uid)"
                data-testid="card-field-row"
                class="rounded-md border px-2 py-2 transition-colors"
                :class="[
                    dragKey === draft.uid
                        ? 'border-border opacity-40'
                        : 'border-border',
                    overKey === draft.uid && dragKey !== draft.uid
                        ? 'border-primary ring-2 ring-primary'
                        : '',
                ]"
            >
                <div class="flex items-start gap-2">
                    <!--
                        The handle is the draggable element, not the row: a
                        draggable row would swallow text selection inside its
                        own inputs. Arrow keys do the same job for anyone not
                        using a mouse.
                    -->
                    <button
                        v-if="reorderable"
                        v-bind="sourceAttrs(draft.uid)"
                        type="button"
                        data-testid="card-field-handle"
                        class="mt-1 w-6 shrink-0 cursor-grab rounded text-center text-muted-foreground hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none active:cursor-grabbing"
                        :aria-label="`Reorder ${draft.label || draft.key || 'this field'} — position ${i + 1} of ${drafts.length}. Drag, or use the up and down arrow keys.`"
                        title="Drag to reorder, or use ↑ / ↓"
                        @keydown="onHandleKey($event, i)"
                    >
                        ⠿
                    </button>

                    <div class="grid flex-1 items-start gap-3 lg:grid-cols-12">
                        <div class="grid gap-1 lg:col-span-3">
                            <Label
                                :for="`cf_label_${i}`"
                                class="text-xs lg:sr-only"
                            >
                                Label
                            </Label>
                            <Input
                                :id="`cf_label_${i}`"
                                data-testid="card-field-label"
                                :model-value="draft.label"
                                placeholder="e.g. Trainer ID"
                                @update:model-value="
                                    onLabel(i, String($event ?? ''))
                                "
                            />
                        </div>

                        <div class="grid gap-1 lg:col-span-3">
                            <Label
                                :for="`cf_key_${i}`"
                                class="text-xs lg:sr-only"
                            >
                                Merge key
                            </Label>
                            <Input
                                :id="`cf_key_${i}`"
                                data-testid="card-field-key"
                                :model-value="draft.key"
                                placeholder="trainer_id"
                                :class="
                                    keyErrors[i] ? 'border-red-500' : undefined
                                "
                                @update:model-value="
                                    onKey(i, String($event ?? ''))
                                "
                            />
                            <div
                                v-if="draft.key && !keyErrors[i]"
                                class="flex items-center gap-2"
                            >
                                <CopyableKey :text="`\${${draft.key}}`" />
                            </div>
                            <p v-if="keyErrors[i]" class="text-xs text-red-600">
                                {{ keyErrors[i] }}
                            </p>
                            <InputError
                                :message="
                                    fieldErrors.message(`fields.${i}.key`)
                                "
                            />
                        </div>

                        <div class="grid gap-1 lg:col-span-2">
                            <Label
                                :for="`cf_type_${i}`"
                                class="text-xs lg:sr-only"
                            >
                                Type
                            </Label>
                            <select
                                :id="`cf_type_${i}`"
                                data-testid="card-field-type"
                                class="h-9 rounded-md border border-input bg-background px-2 text-sm"
                                :value="draft.type"
                                @change="
                                    draft.type = (
                                        $event.target as HTMLSelectElement
                                    ).value as CardFieldType
                                "
                            >
                                <option
                                    v-for="(label, value) in TYPE_LABELS"
                                    :key="value"
                                    :value="value"
                                >
                                    {{ label }}
                                </option>
                            </select>
                        </div>

                        <div class="grid gap-1 lg:col-span-3">
                            <Label
                                :for="`cf_default_${i}`"
                                class="text-xs lg:sr-only"
                            >
                                Default value
                            </Label>
                            <textarea
                                v-if="draft.type === 'rich'"
                                :id="`cf_default_${i}`"
                                data-testid="card-field-rich"
                                v-model="draft.default_value"
                                rows="2"
                                maxlength="2000"
                                class="w-full rounded border border-input bg-background p-2 text-sm"
                                placeholder="**bold**, *italic*; a new line starts a new line"
                            ></textarea>
                            <!--
                            Bound through '' rather than v-model: the draft
                            holds null for "no default", which Input's
                            modelValue doesn't accept. The payload maps ''
                            back to null, so this never reads as a change.
                        -->
                            <Input
                                v-else
                                :id="`cf_default_${i}`"
                                data-testid="card-field-default"
                                :model-value="draft.default_value ?? ''"
                                maxlength="100"
                                placeholder="Optional"
                                @update:model-value="
                                    draft.default_value = String($event ?? '')
                                "
                            />
                            <InputError
                                :message="
                                    fieldErrors.message(
                                        `fields.${i}.default_value`,
                                    )
                                "
                            />
                        </div>

                        <div class="flex justify-end lg:col-span-1">
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                data-testid="card-field-remove"
                                class="text-xs"
                                @click="removeRow(draft)"
                            >
                                Remove
                            </Button>
                        </div>
                    </div>
                </div>

                <div
                    v-if="pendingRemoval === draft.uid"
                    data-testid="card-field-confirm"
                    class="mt-3 rounded border border-red-200 bg-red-50 p-3 text-sm dark:border-red-900 dark:bg-red-900/30"
                >
                    <p>
                        Remove “{{ draft.label || draft.key }}”?
                        <template v-if="answerCount(draft) > 0">
                            {{ answerCount(draft) }}
                            {{
                                answerCount(draft) === 1
                                    ? 'class has'
                                    : 'classes have'
                            }}
                            a value recorded for it, and those values will be
                            deleted. Cards already generated keep what they
                            printed.
                        </template>
                        <template v-else>
                            No class has a value for it yet.
                        </template>
                    </p>
                    <div class="mt-2 flex gap-2">
                        <Button
                            type="button"
                            variant="destructive"
                            size="sm"
                            data-testid="card-field-confirm-remove"
                            @click="dropRow(draft)"
                        >
                            Remove field
                        </Button>
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            @click="pendingRemoval = null"
                        >
                            Keep it
                        </Button>
                    </div>
                </div>
            </div>
        </div>

        <div
            class="flex items-center justify-between border-t border-border pt-3"
        >
            <div class="flex items-center gap-3">
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    data-testid="card-field-add"
                    :disabled="atCapacity"
                    @click="addRow"
                >
                    + Add field
                </Button>
                <span
                    v-if="drafts.length"
                    class="text-xs text-muted-foreground"
                >
                    {{ drafts.length }} of {{ MAX_FIELDS }}
                </span>
            </div>
            <Button
                type="button"
                data-testid="card-field-save"
                :disabled="!isDirty || saving || hasKeyErrors"
                @click="save"
            >
                {{ saving ? 'Saving…' : 'Save card fields' }}
            </Button>
        </div>
    </section>
</template>
