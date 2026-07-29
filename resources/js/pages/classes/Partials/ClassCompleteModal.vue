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
import type { ClassDetail, TopicResult } from '@/stores/classes';
import { useErrorStore } from '@/stores/errors';

const FORM_CTX = 'form:class-complete';

type Decision = TopicResult;
const DECISIONS: Decision[] = ['pass', 'fail', 'incomplete'];

const props = defineProps<{ open: boolean; target: ClassDetail }>();
const emit = defineEmits<{ (e: 'update:open', v: boolean): void }>();

const store = useClassesStore();
const errorStore = useErrorStore();
const submitting = ref(false);
const completionDate = ref('');

interface Mark {
    id: string;
    // Roster label, last-name-first ("Reed, Dana Alan").
    name: string | null;
    notes: string;
    // Freshly added on a re-opened class (never graded yet).
    isNew: boolean;
    // Per-topic decision keyed by class_training_id (pass/fail/incomplete).
    result: Record<string, Decision>;
}

const marks = reactive<Mark[]>([]);

/** A topic's current decision, defaulting to incomplete. */
function decisionOf(m: Mark, trainingId: string): Decision {
    return m.result[trainingId] ?? 'incomplete';
}

watch(
    () => props.open,
    (open) => {
        if (!open) {
            return;
        }

        errorStore.clear(FORM_CTX);
        completionDate.value = props.target.scheduled_date ?? '';

        // Pre-fill each topic from its stored result (pass/fail/incomplete),
        // defaulting to incomplete. So re-closing without touching anyone keeps
        // their prior outcome, and nobody is silently credited — only an
        // explicit Pass issues a certificate.
        const previouslyCompleted = props.target.completion_date != null;

        marks.splice(
            0,
            marks.length,
            // Order is the server's (last, first, middle) — kept verbatim.
            ...props.target.enrollments.map((e) => ({
                id: e.id,
                name: e.user_sort_name ?? e.user_name,
                notes: e.notes ?? '',
                isNew: previouslyCompleted && e.status === 'enrolled',
                result: Object.fromEntries(
                    props.target.trainings.map((t) => [
                        t.id,
                        e.results?.[t.id] ?? 'incomplete',
                    ]),
                ) as Record<string, Decision>,
            })),
        );
    },
);

/** Pick a topic's result (pass / fail / incomplete). */
function choose(m: Mark, trainingId: string, value: Decision): void {
    m.result[trainingId] = value;
}

function markAll(value: Decision): void {
    for (const m of marks) {
        for (const t of props.target.trainings) {
            m.result[t.id] = value;
        }
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
                // Every topic is sent with an explicit result.
                results: props.target.trainings.map((t) => ({
                    class_training_id: t.id,
                    result: decisionOf(m, t.id),
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
                        Mark each attendee Pass, Fail, or Incomplete per
                        training — a certificate is issued only for a Pass; Fail
                        and Incomplete grant no credit. Topics default to
                        Incomplete. This locks the class.
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
                            @click="markAll('incomplete')"
                        >
                            Mark all incomplete
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
                        <span
                            data-testid="attendee-name"
                            class="flex items-center gap-2 font-medium"
                        >
                            {{ m.name }}
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
                            <span class="flex-1">{{ t.training_name }}</span>
                            <button
                                v-for="d in DECISIONS"
                                :key="d"
                                type="button"
                                class="rounded px-2 py-0.5 text-xs capitalize ring-1 ring-inset"
                                :class="
                                    decisionOf(m, t.id) === d
                                        ? d === 'pass'
                                            ? 'bg-emerald-100 text-emerald-900 ring-emerald-300'
                                            : d === 'fail'
                                              ? 'bg-red-100 text-red-900 ring-red-300'
                                              : 'bg-muted text-foreground ring-border'
                                        : 'text-muted-foreground ring-border'
                                "
                                :data-testid="`mark-${t.id}-${d}`"
                                @click="choose(m, t.id, d)"
                            >
                                {{ d }}
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
