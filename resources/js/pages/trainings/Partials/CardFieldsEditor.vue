<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import ErrorBanner from '@/components/ErrorBanner.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useFieldErrors } from '@/composables/useFieldErrors';
import {
    blankCardFieldDraft,
    cardFieldDraftPayload,
    cardFieldKeyErrors,
    seedCardFieldDrafts,
    slugifyCardKey,
} from '@/lib/cardFields';
import type { CardFieldDraft, CardFieldType } from '@/lib/cardFields';
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
/**
 * Per row: has the key been set deliberately? A hand-edited or already-saved
 * key is never overwritten by the label suggestion.
 */
const keyTouched = ref<boolean[]>([]);
const baseline = ref('');
const saving = ref(false);
/** Index awaiting confirmation of removal (saved rows only). */
const pendingRemoval = ref<number | null>(null);

const saved = computed(() => store.forTraining(props.trainingId));

/** Reset the form from what the server currently holds. */
function seed(): void {
    drafts.value = seedCardFieldDrafts(saved.value);
    keyTouched.value = drafts.value.map((d) => d.key !== '');
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
function answerCount(index: number): number {
    const id = drafts.value[index]?.id;

    return saved.value.find((f) => f.id === id)?.value_count ?? 0;
}

function onLabel(index: number, value: string): void {
    const draft = drafts.value[index];
    draft.label = value;

    // Suggest the key while it's still untouched — and never for a field that
    // already exists, whose key is in templates already.
    if (draft.id === null && !keyTouched.value[index]) {
        draft.key = slugifyCardKey(value);
    }
}

function onKey(index: number, value: string): void {
    drafts.value[index].key = value;
    keyTouched.value[index] = true;
}

function addRow(): void {
    drafts.value = [...drafts.value, blankCardFieldDraft('short')];
    keyTouched.value = [...keyTouched.value, false];
}

function removeRow(index: number): void {
    // A saved field's answers go with it, so say so first.
    if (drafts.value[index].id !== null) {
        pendingRemoval.value = index;

        return;
    }

    dropRow(index);
}

function dropRow(index: number): void {
    drafts.value = drafts.value.filter((_, i) => i !== index);
    keyTouched.value = keyTouched.value.filter((_, i) => i !== index);
    pendingRemoval.value = null;
}

async function copyPlaceholder(key: string): Promise<void> {
    await navigator.clipboard?.writeText(`\${${key}}`);
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

        <div class="space-y-3">
            <div
                v-for="(draft, i) in drafts"
                :key="draft.id ?? `new-${i}`"
                class="rounded-md border border-border bg-muted/20 p-3"
            >
                <div class="grid items-start gap-3 lg:grid-cols-12">
                    <div class="grid gap-1 lg:col-span-3">
                        <Label :for="`cf_label_${i}`" class="text-xs">
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
                        <Label :for="`cf_key_${i}`" class="text-xs">
                            Merge key
                        </Label>
                        <Input
                            :id="`cf_key_${i}`"
                            data-testid="card-field-key"
                            :model-value="draft.key"
                            placeholder="trainer_id"
                            :class="keyErrors[i] ? 'border-red-500' : undefined"
                            @update:model-value="onKey(i, String($event ?? ''))"
                        />
                        <div
                            v-if="draft.key && !keyErrors[i]"
                            class="flex items-center gap-2"
                        >
                            <code class="text-xs text-muted-foreground">
                                ${{ '{' }}{{ draft.key }}{{ '}' }}
                            </code>
                            <button
                                type="button"
                                class="text-xs text-primary hover:underline"
                                @click="copyPlaceholder(draft.key)"
                            >
                                Copy
                            </button>
                        </div>
                        <p v-if="keyErrors[i]" class="text-xs text-red-600">
                            {{ keyErrors[i] }}
                        </p>
                        <InputError
                            :message="fieldErrors.message(`fields.${i}.key`)"
                        />
                    </div>

                    <div class="grid gap-1 lg:col-span-2">
                        <Label :for="`cf_type_${i}`" class="text-xs">
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
                        <Label :for="`cf_default_${i}`" class="text-xs">
                            Default value
                        </Label>
                        <textarea
                            v-if="draft.type === 'rich'"
                            :id="`cf_default_${i}`"
                            data-testid="card-field-rich"
                            v-model="draft.default_value"
                            rows="3"
                            maxlength="2000"
                            class="w-full rounded border border-input bg-background p-2 text-sm"
                            placeholder="Markdown: **bold**, *italic*, - lists"
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
                                fieldErrors.message(`fields.${i}.default_value`)
                            "
                        />
                    </div>

                    <div class="flex justify-end lg:col-span-1 lg:pt-6">
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            data-testid="card-field-remove"
                            class="text-xs"
                            @click="removeRow(i)"
                        >
                            Remove
                        </Button>
                    </div>
                </div>

                <div
                    v-if="pendingRemoval === i"
                    data-testid="card-field-confirm"
                    class="mt-3 rounded border border-red-200 bg-red-50 p-3 text-sm dark:border-red-900 dark:bg-red-900/30"
                >
                    <p>
                        Remove “{{ draft.label || draft.key }}”?
                        <template v-if="answerCount(i) > 0">
                            {{ answerCount(i) }}
                            {{
                                answerCount(i) === 1
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
                            @click="dropRow(i)"
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
            <Button
                type="button"
                variant="outline"
                size="sm"
                data-testid="card-field-add"
                @click="addRow"
            >
                + Add field
            </Button>
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
