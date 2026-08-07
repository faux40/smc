<script setup lang="ts">
/*
 * Form-flavored dropdown multi-select: the CLOSED trigger shows the chosen
 * options as removable chips (or a placeholder when none), the open panel is
 * a searchable checkbox list.
 *
 * Sibling of MultiSelectFilter, deliberately not merged with it: the filter
 * summarises ("All users" / "2 users") and carries an and/or/not mode toggle,
 * while a form field must SHOW what was picked — the closed state is the
 * record the admin reviews before saving.
 */
import { ChevronDown, X } from 'lucide-vue-next';
import { computed, ref } from 'vue';
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

const props = defineProps<{
    options: Option[];
    modelValue: string[];
    /** Trigger copy when nothing is selected. */
    placeholder?: string;
    searchPlaceholder?: string;
}>();

const emit = defineEmits<{
    (e: 'update:modelValue', ids: string[]): void;
}>();

const open = ref(false);
const query = ref('');

const labelById = computed(
    () => new Map(props.options.map((o) => [o.id, o.label])),
);

// Stale ids (selected before an option was excluded) render nothing rather
// than an undefined-labeled chip; they still clear normally via their row.
const chips = computed(() =>
    props.modelValue
        .filter((id) => labelById.value.has(id))
        .map((id) => ({ id, label: labelById.value.get(id)! })),
);

const filteredOptions = computed(() => {
    const q = query.value.trim().toLowerCase();

    if (!q) {
        return props.options;
    }

    return props.options.filter((o) => o.label.toLowerCase().includes(q));
});

function isChecked(id: string): boolean {
    return props.modelValue.includes(id);
}

function toggle(id: string): void {
    emit(
        'update:modelValue',
        isChecked(id)
            ? props.modelValue.filter((x) => x !== id)
            : [...props.modelValue, id],
    );
}

function remove(id: string): void {
    emit(
        'update:modelValue',
        props.modelValue.filter((x) => x !== id),
    );
}
</script>

<template>
    <Popover v-model:open="open">
        <PopoverTrigger as-child>
            <button
                type="button"
                data-slot="trigger"
                class="flex min-h-9 w-full flex-wrap items-center gap-1 rounded-md border border-input bg-background px-2 py-1.5 text-left text-sm"
            >
                <span
                    v-if="chips.length === 0"
                    class="text-muted-foreground"
                >
                    {{ placeholder ?? 'None selected' }}
                </span>
                <span
                    v-for="chip in chips"
                    :key="chip.id"
                    class="inline-flex items-center gap-1 rounded-full border border-border bg-muted/50 px-2 py-0.5 text-xs"
                >
                    {{ chip.label }}
                    <span
                        :data-slot="`chip-remove-${chip.id}`"
                        role="button"
                        class="rounded-full text-muted-foreground hover:text-foreground"
                        :aria-label="`Remove ${chip.label}`"
                        @click.stop.prevent="remove(chip.id)"
                    >
                        <X class="size-3" />
                    </span>
                </span>
                <ChevronDown
                    class="ml-auto size-3.5 shrink-0 text-muted-foreground"
                />
            </button>
        </PopoverTrigger>

        <PopoverContent
            side="bottom"
            align="start"
            :side-offset="6"
            class="w-[--reka-popover-trigger-width] min-w-64 p-0"
        >
            <div class="border-b border-border p-1.5">
                <Input
                    v-model="query"
                    type="search"
                    :placeholder="searchPlaceholder ?? 'Search…'"
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
                    data-slot="option"
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
        </PopoverContent>
    </Popover>
</template>
