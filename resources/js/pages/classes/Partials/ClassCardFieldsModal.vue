<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue';
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
import { useClassesStore } from '@/stores/classes';

/**
 * Fill in this class's answers for one topic's custom card fields.
 *
 * Definitions are inherited from the training — this only supplies values, and
 * only while the class is editable: a completed class is read-only, so the
 * fields are shown but locked (printing cards for a finished class means
 * reopening it).
 */
const props = defineProps<{
    open: boolean;
    classId: string;
    topicId: string | null;
}>();
const emit = defineEmits<{ (e: 'update:open', v: boolean): void }>();

const store = useClassesStore();

const detail = computed(() => store.detail[props.classId] ?? null);

const topic = computed(
    () => detail.value?.trainings.find((t) => t.id === props.topicId) ?? null,
);

const fields = computed(() => topic.value?.card_fields ?? []);

const readOnly = computed(() => detail.value?.status === 'completed');

const saving = ref(false);
const actionError = ref<string | null>(null);

/** Answers being edited, keyed by field id. '' = clear it. */
const form = reactive<Record<string, string>>({});

// (Re)seed whenever the modal opens for a (different) topic. The training's
// default is shown as a placeholder rather than seeded in: copying it here
// would freeze today's default onto this class.
watch(
    () => [props.open, props.topicId] as const,
    ([open]) => {
        if (!open) {
            return;
        }

        for (const key of Object.keys(form)) {
            delete form[key];
        }

        for (const field of fields.value) {
            form[field.id] = field.value ?? '';
        }

        actionError.value = null;
    },
    { immediate: true },
);

function placeholderFor(defaultValue: string | null): string {
    return defaultValue === null || defaultValue === ''
        ? 'Leave blank to print nothing'
        : `Default: ${defaultValue}`;
}

async function save(): Promise<void> {
    if (!props.topicId) {
        return;
    }

    saving.value = true;
    actionError.value = null;

    try {
        await store.updateTrainingCardValues(props.classId, props.topicId, {
            ...form,
        });
        emit('update:open', false);
    } catch (e) {
        actionError.value =
            (e as { response?: { data?: { message?: string } } }).response?.data
                ?.message ?? (e as Error).message;
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <Dialog :open="open" @update:open="(v) => emit('update:open', v)">
        <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-4xl">
            <DialogHeader>
                <DialogTitle>Card fields</DialogTitle>
                <DialogDescription>
                    Values printed on the cards for
                    <span class="font-medium">{{
                        topic?.training_name ?? 'this topic'
                    }}</span>
                    in this class. The fields themselves are defined on the
                    training.
                </DialogDescription>
            </DialogHeader>

            <p
                v-if="actionError"
                class="rounded bg-red-50 p-2 text-sm text-red-800 dark:bg-red-900/30 dark:text-red-200"
            >
                {{ actionError }}
            </p>

            <p
                v-if="readOnly"
                class="rounded bg-amber-50 p-2 text-sm text-amber-900 dark:bg-amber-900/30 dark:text-amber-100"
            >
                This class is completed, so its records are read-only. Reopen it
                to change these values.
            </p>

            <p v-if="!fields.length" class="text-sm text-muted-foreground">
                No card fields are defined for this training. Add them on the
                training's page to collect values here.
            </p>

            <form v-else class="space-y-4" @submit.prevent="save">
                <div v-for="field in fields" :key="field.id" class="grid gap-1">
                    <div class="flex items-baseline justify-between gap-2">
                        <Label :for="`cv_${field.id}`">{{ field.label }}</Label>
                        <code class="text-xs text-muted-foreground">
                            {{ field.placeholder }}
                        </code>
                    </div>

                    <textarea
                        v-if="field.type === 'rich'"
                        :id="`cv_${field.id}`"
                        data-testid="card-value-rich"
                        v-model="form[field.id]"
                        rows="4"
                        :maxlength="field.max_length"
                        :disabled="readOnly"
                        class="w-full rounded border border-input bg-background p-2 text-sm disabled:opacity-60"
                        :placeholder="placeholderFor(field.default_value)"
                    ></textarea>
                    <Input
                        v-else
                        :id="`cv_${field.id}`"
                        data-testid="card-value-short"
                        v-model="form[field.id]"
                        :maxlength="field.max_length"
                        :disabled="readOnly"
                        :placeholder="placeholderFor(field.default_value)"
                    />
                </div>

                <DialogFooter>
                    <Button
                        type="button"
                        variant="outline"
                        @click="emit('update:open', false)"
                    >
                        {{ readOnly ? 'Close' : 'Cancel' }}
                    </Button>
                    <Button
                        v-if="!readOnly"
                        type="submit"
                        data-testid="card-value-save"
                        :disabled="saving || !topicId"
                    >
                        {{ saving ? 'Saving…' : 'Save card fields' }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
