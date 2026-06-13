<script setup lang="ts">
/*
 * Per-row actions menu for the users table — collapses Edit / Disable·Enable /
 * Delete behind a single dropdown. The available actions follow the row's
 * permission flags (and never offer disable/delete on your own row); the
 * parent owns what each action does via emits.
 *
 * The action list is exposed for unit testing (the conditional logic); the
 * dropdown chrome itself is presentational (reuses the shared DropdownMenu).
 */
import { MoreHorizontal } from 'lucide-vue-next';
import { computed } from 'vue';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import type { UserRow } from '@/stores/users';

const props = defineProps<{
    row: UserRow;
    isSelf: boolean;
}>();

const emit = defineEmits<{
    (e: 'edit'): void;
    (e: 'toggleStatus'): void;
    (e: 'delete'): void;
}>();

interface RowAction {
    key: string;
    label: string;
    danger?: boolean;
    run: () => void;
}

const actions = computed<RowAction[]>(() => {
    const list: RowAction[] = [];

    if (props.row.can_edit) {
        list.push({ key: 'edit', label: 'Edit', run: () => emit('edit') });
    }

    if (props.row.can_disable && !props.isSelf) {
        list.push({
            key: 'toggle',
            label: props.row.status === 'active' ? 'Disable' : 'Enable',
            run: () => emit('toggleStatus'),
        });
    }

    if (props.row.can_delete && !props.isSelf) {
        list.push({
            key: 'delete',
            label: 'Delete',
            danger: true,
            run: () => emit('delete'),
        });
    }

    return list;
});

defineExpose({ actions });
</script>

<template>
    <DropdownMenu v-if="actions.length > 0">
        <DropdownMenuTrigger as-child>
            <button
                type="button"
                class="inline-flex size-7 items-center justify-center rounded text-muted-foreground hover:bg-muted hover:text-foreground"
                :aria-label="`Actions for ${row.name}`"
            >
                <MoreHorizontal class="size-4" />
            </button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end">
            <DropdownMenuItem
                v-for="action in actions"
                :key="action.key"
                :class="
                    action.danger
                        ? 'text-destructive focus:text-destructive'
                        : ''
                "
                @select="action.run()"
            >
                {{ action.label }}
            </DropdownMenuItem>
        </DropdownMenuContent>
    </DropdownMenu>
    <span v-else class="text-xs text-muted-foreground">—</span>
</template>
