<script setup lang="ts">
import { reactive, ref, watch } from 'vue';
import ErrorBanner from '@/components/ErrorBanner.vue';
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
    // Per-topic pass/fail, keyed by class_training_id.
    passed: Record<string, boolean>;
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
        marks.splice(
            0,
            marks.length,
            // Default every topic to passed; the instructor flips failures.
            ...props.target.enrollments.map((e) => ({
                id: e.id,
                user_name: e.user_name,
                notes: e.notes ?? '',
                passed: Object.fromEntries(
                    props.target.trainings.map((t) => [t.id, true]),
                ),
            })),
        );
    },
);

async function submit(): Promise<void> {
    submitting.value = true;
    errorStore.clear(FORM_CTX);

    try {
        await store.complete(props.target.id, {
            completion_date: completionDate.value,
            enrollments: marks.map((m) => ({
                id: m.id,
                notes: m.notes.trim() === '' ? null : m.notes,
                results: props.target.trainings.map((t) => ({
                    class_training_id: t.id,
                    passed: m.passed[t.id] ?? false,
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
                        Mark each attendee passed or failed per training. A
                        certificate is issued for every passed pairing (dated
                        below; expiries computed per training). This locks the
                        class.
                    </DialogDescription>
                </DialogHeader>

                <ErrorBanner :context="FORM_CTX" />

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
                        <span class="font-medium">{{ m.user_name }}</span>
                        <div class="flex flex-wrap gap-1.5">
                            <button
                                v-for="t in target.trainings"
                                :key="t.id"
                                type="button"
                                class="rounded px-2 py-0.5 text-xs ring-1 ring-inset"
                                :class="
                                    m.passed[t.id]
                                        ? 'bg-emerald-100 text-emerald-900 ring-emerald-300'
                                        : 'bg-red-50 text-red-800 line-through ring-red-200'
                                "
                                :title="
                                    m.passed[t.id]
                                        ? 'Passed — click to fail'
                                        : 'Failed — click to pass'
                                "
                                @click="m.passed[t.id] = !m.passed[t.id]"
                            >
                                {{ m.passed[t.id] ? '✓' : '✗' }}
                                {{ t.training_name }}
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
