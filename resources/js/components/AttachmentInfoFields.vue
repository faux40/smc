<script setup lang="ts">
/*
 * Reusable Type + Description fields for an attachment. Type is a free-text
 * combobox backed by the org's previously-used types (cached in the
 * attachments store); Description is freeform. Both optional.
 *
 *   <AttachmentInfoFields v-model:type="row.type" v-model:description="row.description" />
 */
import { onMounted } from 'vue';
import ComboboxInput from '@/components/ComboboxInput.vue';
import { Label } from '@/components/ui/label';
import { useAttachmentsStore } from '@/stores/attachments';

const props = withDefaults(
    defineProps<{ type: string; description: string; idPrefix?: string }>(),
    { idPrefix: 'att' },
);
const emit = defineEmits<{
    (e: 'update:type', v: string): void;
    (e: 'update:description', v: string): void;
}>();

const store = useAttachmentsStore();

onMounted(() => {
    void store.loadTypes();
});

const fieldId = (suffix: string) => `${props.idPrefix}_${suffix}`;
</script>

<template>
    <div class="grid gap-3 sm:grid-cols-2">
        <div class="grid gap-1.5">
            <Label :for="fieldId('type')">Type (optional)</Label>
            <ComboboxInput
                :id="fieldId('type')"
                :model-value="type"
                :suggestions="store.types"
                placeholder="e.g. Sign-in sheet, Test"
                @update:model-value="(v) => emit('update:type', v)"
            />
        </div>
        <div class="grid gap-1.5">
            <Label :for="fieldId('desc')">Description (optional)</Label>
            <textarea
                :id="fieldId('desc')"
                :value="description"
                rows="2"
                class="w-full rounded border border-input bg-background p-2 text-sm"
                placeholder="Freeform notes about this file"
                @input="
                    emit(
                        'update:description',
                        ($event.target as HTMLTextAreaElement).value,
                    )
                "
            ></textarea>
        </div>
    </div>
</template>
