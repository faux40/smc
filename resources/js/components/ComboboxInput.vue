<script setup lang="ts">
/*
 * Reusable free-text combobox: a normal text input that surfaces a filtered
 * dropdown of existing values as you type, so users reuse known entries
 * instead of coining variants. Free text is always allowed — suggestions
 * standardize, they don't constrain.
 *
 *   <ComboboxInput v-model="form.job_title" :suggestions="options.job_title" />
 */
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { Input } from '@/components/ui/input';

const props = withDefaults(
    defineProps<{
        modelValue: string;
        suggestions: string[];
        id?: string;
        placeholder?: string;
        max?: number;
    }>(),
    { id: undefined, placeholder: '', max: 10 },
);

const emit = defineEmits<{ (e: 'update:modelValue', v: string): void }>();

const open = ref(false);
const highlighted = ref(-1);
const root = ref<HTMLElement | null>(null);

const filtered = computed(() => {
    const q = props.modelValue.trim().toLowerCase();

    return props.suggestions
        .filter((s) => (q === '' ? true : s.toLowerCase().includes(q)))
        // Don't suggest the value the user has already typed in full.
        .filter((s) => s.toLowerCase() !== q)
        .slice(0, props.max);
});

const showList = computed(() => open.value && filtered.value.length > 0);

function onType(v: string | number): void {
    emit('update:modelValue', String(v));
    open.value = true;
    highlighted.value = -1;
}

function select(value: string): void {
    emit('update:modelValue', value);
    open.value = false;
    highlighted.value = -1;
}

function onKeydown(e: KeyboardEvent): void {
    if (e.key === 'ArrowDown') {
        e.preventDefault();
        open.value = true;
        highlighted.value = Math.min(
            highlighted.value + 1,
            filtered.value.length - 1,
        );
    } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        highlighted.value = Math.max(highlighted.value - 1, 0);
    } else if (e.key === 'Enter') {
        if (showList.value && highlighted.value >= 0) {
            e.preventDefault();
            select(filtered.value[highlighted.value]);
        }
    } else if (e.key === 'Escape') {
        open.value = false;
        highlighted.value = -1;
    }
}

// Close when focus/click leaves the component.
function onDocPointer(e: Event): void {
    if (root.value && !root.value.contains(e.target as Node)) {
        open.value = false;
    }
}
onMounted(() => document.addEventListener('click', onDocPointer));
onUnmounted(() => document.removeEventListener('click', onDocPointer));
</script>

<template>
    <div ref="root" class="relative">
        <Input
            :id="id"
            :model-value="modelValue"
            :placeholder="placeholder"
            autocomplete="off"
            @update:model-value="onType"
            @focus="open = true"
            @keydown="onKeydown"
        />

        <ul
            v-if="showList"
            class="absolute z-50 mt-1 max-h-56 w-full overflow-auto rounded-md border border-border bg-background py-1 shadow-md"
        >
            <li v-for="(s, i) in filtered" :key="s">
                <button
                    type="button"
                    data-slot="suggestion"
                    class="block w-full px-3 py-1.5 text-left text-sm hover:bg-accent"
                    :class="{ 'bg-accent': i === highlighted }"
                    @mousedown.prevent="select(s)"
                    @click="select(s)"
                    @mouseenter="highlighted = i"
                >
                    {{ s }}
                </button>
            </li>
        </ul>
    </div>
</template>
