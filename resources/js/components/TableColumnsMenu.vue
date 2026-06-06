<script setup lang="ts">
/*
 * "Columns" dropdown — show/hide only.
 *
 * Drag the column headers themselves to reorder (via useColumnDrag).
 * This component is intentionally dumb: it renders the resolved column list
 * from useTableView and emits `toggle`; the page wires that back to the
 * composable (which persists via the prefs store).
 *
 *   <TableColumnsMenu :columns="columnDefs" @toggle="toggleColumn" />
 */
import { onMounted, onUnmounted, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import type { ResolvedColumn } from '@/composables/useTableView';

defineProps<{ columns: ResolvedColumn[] }>();
const emit = defineEmits<{
    (e: 'toggle', key: string): void;
    (e: 'reset'): void;
    (e: 'reset-all'): void;
}>();

const open = ref(false);
const root = ref<HTMLElement | null>(null);

function onDocClick(e: Event): void {
    if (root.value && !root.value.contains(e.target as Node)) {
        open.value = false;
    }
}
onMounted(() => document.addEventListener('click', onDocClick));
onUnmounted(() => document.removeEventListener('click', onDocClick));
</script>

<template>
    <div ref="root" class="relative">
        <Button
            type="button"
            variant="outline"
            size="sm"
            @click="open = !open"
        >
            Columns
        </Button>

        <div
            v-if="open"
            class="absolute right-0 z-50 mt-1 w-52 rounded-md border border-border bg-background p-2 shadow-md"
        >
            <p class="px-1 pb-1 text-xs font-medium text-muted-foreground">
                Show / hide · drag headers to reorder
            </p>
            <ul class="space-y-0.5">
                <li
                    v-for="col in columns"
                    :key="col.key"
                    class="flex items-center gap-2 rounded px-1 py-1 hover:bg-accent"
                >
                    <Checkbox
                        :id="`col-${col.key}`"
                        :model-value="col.visible"
                        @update:model-value="emit('toggle', col.key)"
                    />
                    <label
                        :for="`col-${col.key}`"
                        class="flex-1 cursor-pointer text-sm"
                    >
                        {{ col.label }}
                    </label>
                </li>
            </ul>

            <div class="mt-1 space-y-0.5 border-t border-border pt-1">
                <button
                    type="button"
                    data-action="reset"
                    class="w-full rounded px-1 py-1 text-left text-xs text-muted-foreground hover:bg-accent hover:text-foreground"
                    @click="emit('reset'); open = false"
                >
                    Reset this table
                </button>
                <button
                    type="button"
                    data-action="reset-all"
                    class="w-full rounded px-1 py-1 text-left text-xs text-muted-foreground hover:bg-accent hover:text-foreground"
                    @click="emit('reset-all'); open = false"
                >
                    Reset all tables
                </button>
            </div>
        </div>
    </div>
</template>
