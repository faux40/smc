<script setup lang="ts">
/*
 * Popover-based color picker. Replaces the native <input type="color">
 * in places where the requirement is "close when the user picks a color"
 * — native pickers are OS-controlled and can't be programmatically
 * dismissed.
 *
 * Picking a preset swatch (or a color from the inner native input)
 * commits the value and closes the popover.
 */

import { PopoverContent, PopoverPortal, PopoverRoot, PopoverTrigger } from 'reka-ui';
import { ref } from 'vue';

const props = withDefaults(
    defineProps<{
        modelValue: string;
        disabled?: boolean;
        ariaLabel?: string;
    }>(),
    {
        disabled: false,
        ariaLabel: 'Color',
    },
);

const emit = defineEmits<{ (e: 'update:modelValue', v: string): void }>();

const open = ref(false);

// 24 presets covering a useful spectrum. Tailwind-derived palette so
// colors compose with the surrounding UI.
const SWATCHES = [
    '#ef4444', '#f97316', '#f59e0b', '#eab308', '#84cc16', '#22c55e',
    '#10b981', '#14b8a6', '#06b6d4', '#0ea5e9', '#3b82f6', '#6366f1',
    '#8b5cf6', '#a855f7', '#d946ef', '#ec4899', '#f43f5e', '#64748b',
    '#475569', '#1f2937', '#000000', '#ffffff', '#dc2626', '#ca8a04',
];

function pick(color: string): void {
    emit('update:modelValue', color);
    open.value = false;
}
</script>

<template>
    <PopoverRoot v-model:open="open">
        <PopoverTrigger as-child>
            <button
                type="button"
                :disabled="disabled"
                class="h-9 w-12 cursor-pointer rounded border border-input disabled:opacity-50"
                :style="{ backgroundColor: modelValue }"
                :aria-label="ariaLabel"
            />
        </PopoverTrigger>
        <PopoverPortal>
            <PopoverContent
                side="bottom"
                align="start"
                :side-offset="6"
                class="z-50 w-56 rounded-md border border-border bg-popover p-2 text-popover-foreground shadow-md outline-none"
            >
                <div class="grid grid-cols-6 gap-1.5">
                    <button
                        v-for="c in SWATCHES"
                        :key="c"
                        type="button"
                        class="h-6 w-6 cursor-pointer rounded ring-1 ring-border hover:ring-2 hover:ring-primary"
                        :style="{ backgroundColor: c }"
                        :aria-label="c"
                        @click="pick(c)"
                    />
                </div>
                <div class="mt-2 flex items-center gap-2 border-t border-border pt-2">
                    <input
                        type="color"
                        :value="modelValue"
                        class="h-7 w-10 cursor-pointer rounded border border-input bg-background"
                        :aria-label="`Custom ${ariaLabel.toLowerCase()}`"
                        @change="(e) => pick((e.target as HTMLInputElement).value)"
                    />
                    <span class="text-xs text-muted-foreground">Custom</span>
                </div>
            </PopoverContent>
        </PopoverPortal>
    </PopoverRoot>
</template>
