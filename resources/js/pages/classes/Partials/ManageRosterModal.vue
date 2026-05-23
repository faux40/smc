<script setup lang="ts">
import { computed, ref, watch } from 'vue';
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
const saving = ref(false);

// Local working set of enrolled user-ids — moves are instant client-side and
// only persisted (diffed against the server roster) when the modal closes.
const selected = ref<Set<string>>(new Set());

watch(
    () => props.open,
    (open) => {
        if (open) {
            actionError.value = null;
            selected.value = new Set(
                (detail.value?.enrollments ?? []).map((e) => e.user_id),
            );
        }
    },
);

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

const toItem = (u: PickerUser): StudentItem => ({
    id: u.id,
    name: userLabel(u),
    email: u.email ?? '',
});

const assigned = computed<StudentItem[]>(() =>
    props.users.filter((u) => selected.value.has(u.id)).map(toItem),
);
const available = computed<StudentItem[]>(() =>
    props.users.filter((u) => !selected.value.has(u.id)).map(toItem),
);

function assign(item: { id: string }): void {
    const next = new Set(selected.value);
    next.add(item.id);
    selected.value = next;
}
function unassign(item: { id: string }): void {
    const next = new Set(selected.value);
    next.delete(item.id);
    selected.value = next;
}

// Persist the queued add/removes (diff vs the server roster) on close.
async function commit(): Promise<void> {
    const d = detail.value;

    if (!d || !canEdit.value) {
        return;
    }

    const original = new Map(d.enrollments.map((e) => [e.user_id, e.id]));
    const toEnroll = props.users
        .filter((u) => selected.value.has(u.id) && !original.has(u.id))
        .map((u) => u.id);
    const toUnenroll = d.enrollments
        .filter((e) => !selected.value.has(e.user_id))
        .map((e) => e.id);

    if (toEnroll.length === 0 && toUnenroll.length === 0) {
        return;
    }

    saving.value = true;

    try {
        for (const id of toEnroll) {
            await store.enroll(props.classId, id);
        }

        for (const enrollmentId of toUnenroll) {
            await store.unenroll(props.classId, enrollmentId);
        }
    } catch (e) {
        actionError.value =
            (e as { response?: { data?: { message?: string } } }).response?.data
                ?.message ?? (e as Error).message;
    } finally {
        saving.value = false;
    }
}

function onOpenChange(value: boolean): void {
    if (!value) {
        void commit();
    }

    emit('update:open', value);
}
</script>

<template>
    <Dialog :open="open" @update:open="onOpenChange">
        <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-4xl">
            <DialogHeader>
                <DialogTitle>Roster</DialogTitle>
                <DialogDescription>
                    Move students between the lists; changes save when you close.
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
                always-expanded
                :disabled="!canEdit"
                @assign="assign"
                @unassign="unassign"
            />

            <DialogFooter>
                <Button
                    type="button"
                    :disabled="saving"
                    @click="onOpenChange(false)"
                >
                    Done
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
