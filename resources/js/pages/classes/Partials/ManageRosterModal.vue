<script setup lang="ts">
import { computed, ref } from 'vue';
import DualListShuttle from '@/components/DualListShuttle.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { useClassesStore } from '@/stores/classes';

export interface PickerUser {
    id: string;
    f_name: string;
    l_name: string;
    email: string | null;
}

const props = defineProps<{
    open: boolean;
    classId: string;
    users: PickerUser[];
}>();
const emit = defineEmits<{ (e: 'update:open', v: boolean): void }>();

const store = useClassesStore();

const detail = computed(() => store.detail[props.classId] ?? null);
const canEdit = computed(
    () => detail.value?.can_edit === true && detail.value?.status === 'scheduled',
);

const actionError = ref<string | null>(null);
async function run(fn: () => Promise<unknown>): Promise<void> {
    actionError.value = null;

    try {
        await fn();
    } catch (e) {
        actionError.value =
            (e as { response?: { data?: { message?: string } } }).response?.data
                ?.message ?? (e as Error).message;
    }
}

const userLabel = (u: PickerUser) =>
    [u.f_name, u.l_name].filter(Boolean).join(' ') || u.email || u.id;

const columns = [
    { key: 'name', label: 'Name' },
    { key: 'email', label: 'Email' },
];

interface StudentItem {
    id: string;
    name: string;
    email: string;
}

const assigned = computed<StudentItem[]>(() =>
    (detail.value?.enrollments ?? []).map((e) => ({
        id: e.id,
        name: e.user_name ?? '—',
        email: e.user_email ?? '',
    })),
);

const available = computed<StudentItem[]>(() => {
    const enrolled = new Set(
        (detail.value?.enrollments ?? []).map((e) => e.user_id),
    );

    return props.users
        .filter((u) => !enrolled.has(u.id))
        .map((u) => ({ id: u.id, name: userLabel(u), email: u.email ?? '' }));
});

const assign = (item: { id: string }) =>
    run(() => store.enroll(props.classId, item.id));
const unassign = (item: { id: string }) =>
    run(() => store.unenroll(props.classId, item.id));
</script>

<template>
    <Dialog :open="open" @update:open="(v) => emit('update:open', v)">
        <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-4xl">
            <DialogHeader>
                <DialogTitle>Roster</DialogTitle>
                <DialogDescription>
                    Enroll or remove students. Changes save immediately.
                </DialogDescription>
            </DialogHeader>

            <p
                v-if="actionError"
                class="rounded bg-red-50 p-2 text-sm text-red-800 dark:bg-red-900/30 dark:text-red-200"
            >
                {{ actionError }}
            </p>

            <DualListShuttle
                :assigned="assigned"
                :available="available"
                :columns="columns"
                assigned-title="Enrolled"
                available-title="Available students"
                search-placeholder="Search students…"
                add-label="Enroll students"
                :disabled="!canEdit"
                @assign="assign"
                @unassign="unassign"
            />

            <DialogFooter>
                <Button type="button" @click="emit('update:open', false)">
                    Done
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
