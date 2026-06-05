<script setup lang="ts">
/*
 * Reusable "Columns" control for table views: show/hide each column and
 * reorder it horizontally. Dumb component — it renders the resolved columns
 * from useTableView and emits toggle/move; the page wires those back to the
 * composable (which persists via the prefs store).
 *
 *   <TableColumnsMenu :columns="view.columns.value"
 *     @toggle="view.toggle" @move="view.move" />
 */
import { ChevronDown, ChevronUp } from 'lucide-vue-next';
import { onMounted, onUnmounted, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import type { MoveDir, ResolvedColumn } from '@/composables/useTableView';

defineProps<{ columns: ResolvedColumn[] }>();
const emit = defineEmits<{
    (e: 'toggle', key: string): void;
    (e: 'move', key: string, dir: MoveDir): void;
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
            class="absolute right-0 z-50 mt-1 w-60 rounded-md border border-border bg-background p-2 shadow-md"
        >
            <p class="px-1 pb-1 text-xs font-medium text-muted-foreground">
                Show / reorder columns
            </p>
            <ul class="space-y-0.5">
                <li
                    v-for="(col, i) in columns"
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
                    <button
                        type="button"
                        class="text-muted-foreground hover:text-foreground disabled:opacity-30"
                        :disabled="i === 0"
                        aria-label="Move left"
                        @click="emit('move', col.key, 'left')"
                    >
                        <ChevronUp class="size-4" />
                    </button>
                    <button
                        type="button"
                        class="text-muted-foreground hover:text-foreground disabled:opacity-30"
                        :disabled="i === columns.length - 1"
                        aria-label="Move right"
                        @click="emit('move', col.key, 'right')"
                    >
                        <ChevronDown class="size-4" />
                    </button>
                </li>
            </ul>
        </div>
    </div>
</template>
