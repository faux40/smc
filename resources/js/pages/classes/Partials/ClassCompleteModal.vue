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
    status: 'passed' | 'incomplete';
    notes: string;
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
            // Default everyone to passed; the instructor flips failures.
            ...props.target.enrollments.map((e) => ({
                id: e.id,
                user_name: e.user_name,
                status: 'passed' as const,
                notes: e.notes ?? '',
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
                status: m.status,
                notes: m.notes.trim() === '' ? null : m.notes,
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
        <DialogContent>
            <form @submit.prevent="submit" class="space-y-4">
                <DialogHeader>
                    <DialogTitle>Complete class</DialogTitle>
                    <DialogDescription>
                        Mark who passed. Passed attendees get a completion for
                        each of this class's trainings (dated below); expiries
                        are computed per training. This locks the class.
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

                <ul v-else class="space-y-2">
                    <li
                        v-for="m in marks"
                        :key="m.id"
                        class="flex flex-wrap items-center gap-2 border-b border-border pb-2 text-sm"
                    >
                        <span class="min-w-32 flex-1 font-medium">{{
                            m.user_name
                        }}</span>
                        <div class="flex gap-1">
                            <button
                                type="button"
                                class="rounded px-2 py-0.5 text-xs ring-1 ring-inset"
                                :class="
                                    m.status === 'passed'
                                        ? 'bg-emerald-100 text-emerald-900 ring-emerald-300 dark:bg-emerald-900/40 dark:text-emerald-100'
                                        : 'text-muted-foreground ring-border'
                                "
                                @click="m.status = 'passed'"
                            >
                                Passed
                            </button>
                            <button
                                type="button"
                                class="rounded px-2 py-0.5 text-xs ring-1 ring-inset"
                                :class="
                                    m.status === 'incomplete'
                                        ? 'bg-red-100 text-red-900 ring-red-300 dark:bg-red-900/40 dark:text-red-100'
                                        : 'text-muted-foreground ring-border'
                                "
                                @click="m.status = 'incomplete'"
                            >
                                Incomplete
                            </button>
                        </div>
                        <Input
                            v-model="m.notes"
                            placeholder="notes (optional)"
                            class="h-7 flex-1 text-xs"
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
