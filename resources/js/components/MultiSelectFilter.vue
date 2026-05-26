<script setup lang="ts">
/*
 * Compact multi-select list filter: a single dropdown button that opens a
 * checkbox list (multiple at once), with an optional in-list search and a
 * FilterModeToggle (&/||/!) in the footer. Host owns state via two v-models
 * (`selected` ids + `mode`) and applies the and/any/none logic.
 *
 * Replaces the wide chips+add-one-at-a-time layout — the trigger stays a
 * single control regardless of how many options are picked.
 */
import { ChevronDown } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import FilterModeToggle from '@/components/FilterModeToggle.vue';
import type { FilterMode } from '@/components/FilterModeToggle.vue';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';

interface Option {
    id: string;
    label: string;
}

const props = withDefaults(
    defineProps<{
        options: Option[];
        selected: string[];
        mode: FilterMode;
        /** Plural noun for the trigger, e.g. "users" → "All users" / "2 users". */
        label: string;
        modes?: FilterMode[];
        searchable?: boolean;
    }>(),
    { modes: () => ['and', 'or', 'not'], searchable: true },
);

const emit = defineEmits<{
    (e: 'update:selected', ids: string[]): void;
    (e: 'update:mode', mode: FilterMode): void;
}>();

const open = ref(false);
const query = ref('');

const MODE_GLYPH: Record<FilterMode, string> = { and: '&', or: '||', not: '!' };

const triggerLabel = computed(() =>
    props.selected.length === 0
        ? `All ${props.label}`
        : `${props.selected.length} ${props.label}`,
);

const filteredOptions = computed(() => {
    const q = query.value.trim().toLowerCase();

    if (!q) {
        return props.options;
    }

    return props.options.filter((o) => o.label.toLowerCase().includes(q));
});

function isChecked(id: string): boolean {
    return props.selected.includes(id);
}

function toggle(id: string): void {
    emit(
        'update:selected',
        isChecked(id)
            ? props.selected.filter((x) => x !== id)
            : [...props.selected, id],
    );
}

function clear(): void {
    emit('update:selected', []);
}
</script>

<template>
    <Popover v-model:open="open">
        <PopoverTrigger as-child>
            <button
                type="button"
                class="inline-flex h-8 items-center gap-1.5 rounded border border-input bg-background px-2 text-sm"
                :class="
                    selected.length > 0
                        ? 'font-medium'
                        : 'text-muted-foreground'
                "
            >
                <span>{{ triggerLabel }}</span>
                <span
                    v-if="selected.length > 0"
                    class="font-mono text-xs font-bold text-muted-foreground"
                >
                    {{ MODE_GLYPH[mode] }}
                </span>
                <ChevronDown class="size-3.5 shrink-0 text-muted-foreground" />
            </button>
        </PopoverTrigger>

        <PopoverContent
            side="bottom"
            align="start"
            :side-offset="6"
            class="w-64 p-0"
        >
            <div v-if="searchable" class="border-b border-border p-1.5">
                <Input
                    v-model="query"
                    type="search"
                    :placeholder="`Filter ${label}…`"
                    class="h-7 text-sm"
                />
            </div>

            <div class="max-h-64 overflow-auto p-1">
                <p
                    v-if="filteredOptions.length === 0"
                    class="px-2 py-1.5 text-xs text-muted-foreground italic"
                >
                    No matches.
                </p>
                <button
                    v-for="o in filteredOptions"
                    :key="o.id"
                    type="button"
                    class="flex w-full items-center gap-2 rounded px-2 py-1.5 text-left text-sm hover:bg-accent"
                    @click="toggle(o.id)"
                >
                    <Checkbox
                        :model-value="isChecked(o.id)"
                        class="pointer-events-none"
                    />
                    <span class="truncate">{{ o.label }}</span>
                </button>
            </div>

            <div
                v-if="selected.length > 0"
                class="flex items-center justify-between border-t border-border px-2 py-1.5"
            >
                <span class="inline-flex items-center gap-1.5 text-xs">
                    <span class="text-muted-foreground">Match</span>
                    <FilterModeToggle
                        :mode="mode"
                        :modes="modes"
                        @update:mode="(m) => emit('update:mode', m)"
                    />
                </span>
                <button
                    type="button"
                    class="text-xs text-muted-foreground hover:text-foreground hover:underline"
                    @click="clear"
                >
                    Clear
                </button>
            </div>
        </PopoverContent>
    </Popover>
</template>
