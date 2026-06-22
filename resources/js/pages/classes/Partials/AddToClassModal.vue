<script setup lang="ts">
/*
 * "Add to existing class" picker — used from the compliance training detail when
 * a scheduled class already includes the training. Lists those classes; picking
 * one bulk-enrolls the selected users into it.
 */
import { ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { useClassesStore } from '@/stores/classes';
import type { ClassRow } from '@/stores/classes';

const props = defineProps<{
    open: boolean;
    trainingId: string;
    trainingName: string;
    userIds: string[];
}>();
const emit = defineEmits<{
    (e: 'update:open', v: boolean): void;
    (e: 'added', classId: string): void;
}>();

const classes = useClassesStore();
const list = ref<ClassRow[]>([]);
const loading = ref(false);
const error = ref<string | null>(null);
const submittingId = ref<string | null>(null);

watch(
    () => props.open,
    async (open) => {
        if (!open) {
            return;
        }
        error.value = null;
        list.value = [];
        loading.value = true;
        try {
            list.value = await classes.fetchForTraining(props.trainingId);
        } catch (e) {
            error.value = (e as Error).message;
        } finally {
            loading.value = false;
        }
    },
);

async function addTo(row: ClassRow): Promise<void> {
    submittingId.value = row.id;
    try {
        await classes.bulkEnroll(row.id, { enroll: props.userIds, unenroll: [] });
        emit('added', row.id);
        emit('update:open', false);
    } catch (e) {
        error.value = (e as Error).message;
    } finally {
        submittingId.value = null;
    }
}
</script>

<template>
    <Dialog :open="open" @update:open="(v) => emit('update:open', v)">
        <DialogContent class="w-[92vw] sm:max-w-2xl">
            <DialogHeader>
                <DialogTitle>
                    Add {{ userIds.length }}
                    {{ userIds.length === 1 ? 'user' : 'users' }} to a class
                </DialogTitle>
                <DialogDescription>
                    Scheduled classes that already include {{ trainingName }}.
                </DialogDescription>
            </DialogHeader>

            <p
                v-if="error"
                class="rounded bg-red-50 p-2 text-sm text-red-800 dark:bg-red-900/30 dark:text-red-200"
            >
                {{ error }}
            </p>

            <p v-if="loading" class="py-6 text-center text-sm text-muted-foreground">
                Loading classes…
            </p>
            <p
                v-else-if="list.length === 0"
                class="rounded border border-dashed border-border p-6 text-center text-sm text-muted-foreground"
            >
                No scheduled classes include this training yet — use “Create
                class with selected” instead.
            </p>
            <ul v-else class="max-h-[60vh] divide-y divide-border overflow-y-auto">
                <li
                    v-for="row in list"
                    :key="row.id"
                    data-testid="add-to-class-row"
                    class="flex items-center justify-between gap-3 py-2"
                >
                    <div class="min-w-0">
                        <div class="truncate font-medium">{{ row.name }}</div>
                        <div class="text-xs text-muted-foreground">
                            {{ row.scheduled_date ?? '—' }} ·
                            {{ row.enrollments_count }} enrolled
                            <template v-if="row.instructor">
                                · {{ row.instructor }}
                            </template>
                        </div>
                    </div>
                    <Button
                        size="sm"
                        :disabled="submittingId !== null"
                        :data-testid="`add-to-class-${row.id}`"
                        @click="addTo(row)"
                    >
                        {{ submittingId === row.id ? 'Adding…' : 'Add' }}
                    </Button>
                </li>
            </ul>
        </DialogContent>
    </Dialog>
</template>
