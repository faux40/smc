<script setup lang="ts">
import { reactive, ref, watch } from 'vue';
import ErrorBanner from '@/components/ErrorBanner.vue';
import { Badge } from '@/components/ui/badge';
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
import type { ClassDetail } from '@/stores/classes';
import { useErrorStore } from '@/stores/errors';

const FORM_CTX = 'form:class-complete';

type Decision = 'pass' | 'fail';

const props = defineProps<{ open: boolean; target: ClassDetail }>();
const emit = defineEmits<{ (e: 'update:open', v: boolean): void }>();

const store = useClassesStore();
const errorStore = useErrorStore();
const submitting = ref(false);
const completionDate = ref('');

interface Mark {
    id: string;
    user_name: string | null;
    notes: string;
    // Freshly added on a re-opened class (never graded yet).
    isNew: boolean;
    // Topics this person already holds a certificate for (for context).
    credited: Set<string>;
    // Per-topic decision keyed by class_training_id; absent = unmarked.
    result: Record<string, Decision>;
}

const marks = reactive<Mark[]>([]);

watch(
    () => props.open,
    (open) => {
        if (!open) {
            return;
        }

        errorStore.clear(FORM_CTX);
        completionDate.value = props.target.scheduled_date ?? '';

        // Every topic starts UNMARKED — the instructor must choose pass/fail
        // (or use "Mark all passed"). Nothing is pre-selected, so a freshly
        // added attendee is never silently credited, and on a re-close an
        // unmarked topic leaves the existing certificate untouched.
        const previouslyCompleted = props.target.completion_date != null;

        marks.splice(
            0,
            marks.length,
            ...props.target.enrollments.map((e) => ({
                id: e.id,
                user_name: e.user_name,
                notes: e.notes ?? '',
                isNew: previouslyCompleted && e.status === 'enrolled',
                credited: new Set(e.credited_training_ids),
                result: {} as Record<string, Decision>,
            })),
        );
    },
);

/** Click a pass/fail chip: set it, or toggle back to unmarked if re-clicked. */
function choose(m: Mark, trainingId: string, value: Decision): void {
    if (m.result[trainingId] === value) {
        delete m.result[trainingId];
    } else {
        m.result[trainingId] = value;
    }
}

function markAll(value: Decision): void {
    for (const m of marks) {
        for (const t of props.target.trainings) {
            m.result[t.id] = value;
        }
    }
}

function clearAll(): void {
    for (const m of marks) {
        m.result = {};
    }
}

async function submit(): Promise<void> {
    submitting.value = true;
    errorStore.clear(FORM_CTX);

    try {
        await store.complete(props.target.id, {
            completion_date: completionDate.value,
            enrollments: marks.map((m) => ({
                id: m.id,
                notes: m.notes.trim() === '' ? null : m.notes,
                // Only marked topics are sent; unmarked ones are left as-is.
                results: props.target.trainings
                    .filter((t) => m.result[t.id] !== undefined)
                    .map((t) => ({
                        class_training_id: t.id,
                        passed: m.result[t.id] === 'pass',
                    })),
            })),
        });
        emit('update:open', false);
    } catch (e) {
        errorStore.reportFromAxios(e, FORM_CTX, {
            fallback: 'Failed to complete the class.',
        });
    } finally {
        submitting.value = false;
    }
}
</script>

<template>
    <Dialog :open="open" @update:open="(v) => emit('update:open', v)">
        <DialogContent class="max-h-[90vh] w-[92vw] overflow-y-auto sm:max-w-3xl">
            <form @submit.prevent="submit" class="space-y-4">
                <DialogHeader>
                    <DialogTitle>Complete class</DialogTitle>
                    <DialogDescription>
                        Mark each attendee passed or failed per training — a
                        certificate is issued for every passed pairing.
                        Unmarked topics are left unchanged. This locks the
                        class.
                    </DialogDescription>
                </DialogHeader>

                <ErrorBanner :context="FORM_CTX" />

                <div class="flex flex-wrap items-end justify-between gap-3">
                    <div class="grid gap-2">
                        <Label for="complete_date">Completion date</Label>
                        <Input
                            id="complete_date"
                            type="date"
                            v-model="completionDate"
                            required
                            class="w-48"
                        />
                    </div>
                    <div v-if="marks.length" class="flex gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            @click="markAll('pass')"
                        >
                            Mark all passed
                        </Button>
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            @click="clearAll"
                        >
                            Clear all
                        </Button>
                    </div>
                </div>

                <div
                    v-if="marks.length === 0"
                    class="rounded border border-dashed border-border p-3 text-xs text-muted-foreground"
                >
                    Nobody is enrolled — nothing to complete.
                </div>

                <ul v-else class="space-y-3">
                    <li
                        v-for="m in marks"
                        :key="m.id"
                        class="space-y-1.5 border-b border-border pb-3 text-sm"
                    >
                        <span class="flex items-center gap-2 font-medium">
                            {{ m.user_name }}
                            <Badge
                                v-if="m.isNew"
                                variant="secondary"
                                class="text-[10px]"
                            >
                                new
                            </Badge>
                        </span>
                        <div
                            v-for="t in target.trainings"
                            :key="t.id"
                            class="flex items-center gap-2"
                        >
                            <span class="flex-1">
                                {{ t.training_name }}
                                <span
                                    v-if="
                                        m.credited.has(t.id) &&
                                        m.result[t.id] === undefined
                                    "
                                    class="text-xs text-muted-foreground"
                                >
                                    · certified
                                </span>
                            </span>
                            <button
                                type="button"
                                class="rounded px-2 py-0.5 text-xs ring-1 ring-inset"
                                :class="
                                    m.result[t.id] === 'pass'
                                        ? 'bg-emerald-100 text-emerald-900 ring-emerald-300'
                                        : 'text-muted-foreground ring-border'
                                "
                                @click="choose(m, t.id, 'pass')"
                            >
                                Pass
                            </button>
                            <button
                                type="button"
                                class="rounded px-2 py-0.5 text-xs ring-1 ring-inset"
                                :class="
                                    m.result[t.id] === 'fail'
                                        ? 'bg-red-100 text-red-900 ring-red-300'
                                        : 'text-muted-foreground ring-border'
                                "
                                @click="choose(m, t.id, 'fail')"
                            >
                                Fail
                            </button>
                        </div>
                        <Input
                            v-model="m.notes"
                            placeholder="notes (optional)"
                            class="h-7 text-xs"
                        />
                    </li>
                </ul>

                <DialogFooter>
                    <Button
                        type="button"
                        variant="outline"
                        @click="emit('update:open', false)"
                    >
                        Cancel
                    </Button>
                    <Button type="submit" :disabled="submitting">
                        Complete & record credit
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
