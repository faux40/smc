<script setup lang="ts">
/*
 * Generic multi-select list filter: a native "add" dropdown + removable
 * chips + a FilterModeToggle (&/||/!). Host owns state via two v-models
 * (`selected` ids + `mode`) and applies the and/any/none logic. Mirrors
 * TagFilter's shape for non-tag option lists (users, requirements, …).
 */
import { computed } from 'vue';
import FilterModeToggle from '@/components/FilterModeToggle.vue';
import type { FilterMode } from '@/components/FilterModeToggle.vue';

interface Option {
    id: string;
    label: string;
}

const props = withDefaults(
    defineProps<{
        options: Option[];
        selected: string[];
        mode: FilterMode;
        modes?: FilterMode[];
        addLabel?: string;
    }>(),
    { modes: () => ['and', 'or', 'not'], addLabel: 'Add…' },
);

const emit = defineEmits<{
    (e: 'update:selected', ids: string[]): void;
    (e: 'update:mode', mode: FilterMode): void;
}>();

const available = computed(() => {
    const sel = new Set(props.selected);

    return props.options.filter((o) => !sel.has(o.id));
});

const selectedOptions = computed(() =>
    props.selected
        .map((id) => props.options.find((o) => o.id === id))
        .filter((o): o is Option => o !== undefined),
);

function onAdd(event: Event): void {
    const el = event.target as HTMLSelectElement;
    const id = el.value;

    if (id && !props.selected.includes(id)) {
        emit('update:selected', [...props.selected, id]);
    }

    el.value = ''; // reset back to the "Add…" placeholder
}

function onRemove(id: string): void {
    emit(
        'update:selected',
        props.selected.filter((x) => x !== id),
    );
}
</script>

<template>
    <div class="inline-flex flex-wrap items-center gap-1.5">
        <select
            class="rounded border border-input bg-background px-2 py-1 text-sm"
            @change="onAdd"
        >
            <option value="">{{ addLabel }}</option>
            <option v-for="o in available" :key="o.id" :value="o.id">
                {{ o.label }}
            </option>
        </select>

        <button
            v-for="o in selectedOptions"
            :key="o.id"
            type="button"
            class="inline-flex items-center gap-1 rounded-full bg-muted px-2 py-0.5 text-xs hover:bg-muted/70"
            :title="`Remove ${o.label} from filter`"
            @click="onRemove(o.id)"
        >
            {{ o.label }}
            <span class="text-muted-foreground">×</span>
        </button>

        <FilterModeToggle
            v-if="selected.length > 0"
            :mode="mode"
            :modes="modes"
            @update:mode="(m) => emit('update:mode', m)"
        />
    </div>
</template>
