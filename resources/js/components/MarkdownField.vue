<script setup lang="ts">
import { computed, ref } from 'vue';
import { renderMarkdown } from '@/lib/markdown';

const props = withDefaults(
    defineProps<{
        modelValue: string;
        id?: string;
        rows?: number;
        placeholder?: string;
    }>(),
    { rows: 4, placeholder: '' },
);

const emit = defineEmits<{ 'update:modelValue': [value: string] }>();

const tab = ref<'write' | 'preview'>('write');

const previewHtml = computed(() => renderMarkdown(props.modelValue));

function onInput(event: Event) {
    emit('update:modelValue', (event.target as HTMLTextAreaElement).value);
}
</script>

<template>
    <div class="space-y-1.5">
        <div
            class="flex gap-1 text-xs"
            role="tablist"
            aria-label="Markdown editor"
        >
            <button
                type="button"
                role="tab"
                :aria-selected="tab === 'write'"
                class="rounded px-2 py-1"
                :class="
                    tab === 'write'
                        ? 'bg-muted font-medium text-foreground'
                        : 'text-muted-foreground hover:text-foreground'
                "
                @click="tab = 'write'"
            >
                Write
            </button>
            <button
                type="button"
                role="tab"
                :aria-selected="tab === 'preview'"
                class="rounded px-2 py-1"
                :class="
                    tab === 'preview'
                        ? 'bg-muted font-medium text-foreground'
                        : 'text-muted-foreground hover:text-foreground'
                "
                @click="tab = 'preview'"
            >
                Preview
            </button>
        </div>

        <textarea
            v-show="tab === 'write'"
            :id="id"
            :value="modelValue"
            :rows="rows"
            :placeholder="placeholder"
            class="w-full rounded border border-input bg-background p-2 text-sm"
            @input="onInput"
        ></textarea>

        <div
            v-show="tab === 'preview'"
            data-testid="markdown-preview"
            class="prose-cert min-h-[6rem] w-full rounded border border-input bg-background p-2 text-sm"
        >
            <div v-if="modelValue" v-html="previewHtml"></div>
            <p v-else class="text-muted-foreground">Nothing to preview yet.</p>
        </div>
    </div>
</template>

<style scoped>
/* Light typographic defaults so the preview reads like the rendered cert
   without pulling in a full prose plugin. */
.prose-cert :deep(p) {
    margin: 0 0 0.5rem;
}
.prose-cert :deep(p:last-child) {
    margin-bottom: 0;
}
.prose-cert :deep(strong) {
    font-weight: 600;
}
.prose-cert :deep(em) {
    font-style: italic;
}
.prose-cert :deep(ul),
.prose-cert :deep(ol) {
    margin: 0 0 0.5rem 1.25rem;
    list-style: revert;
}
</style>
