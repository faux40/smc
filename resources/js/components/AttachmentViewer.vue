<script setup lang="ts">
/*
 * Doc viewer (modal). Previews either:
 *  - a stored attachment (via the same-origin /view endpoint), showing its
 *    Type + Description with inline editing when permitted (can_edit); or
 *  - a freshly generated class document ({ title, src, classId, kind }),
 *    with a "Save to this class's files" action.
 *
 * PDFs/images render inline; the browser's PDF toolbar provides download +
 * print, so the viewer carries no Download button of its own.
 */
import { computed, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import AttachmentInfoFields from '@/components/AttachmentInfoFields.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { useAttachmentsStore } from '@/stores/attachments';
import type { AttachmentRow } from '@/stores/attachments';

export interface GeneratedDoc {
    title: string;
    src: string;
    classId: string;
    kind: 'certificates' | 'summary' | 'sign-in';
}

const props = defineProps<{
    open: boolean;
    attachment?: AttachmentRow | null;
    generated?: GeneratedDoc | null;
}>();
const emit = defineEmits<{ (e: 'update:open', v: boolean): void }>();

const store = useAttachmentsStore();

const isGenerated = computed(() => props.generated != null);
const title = computed(
    () => props.generated?.title ?? props.attachment?.filename ?? '',
);
const mime = computed(() => props.attachment?.mime ?? 'application/pdf');
const previewKind = computed<'pdf' | 'image' | 'other'>(() => {
    if (isGenerated.value || mime.value === 'application/pdf') {
        return 'pdf';
    }

    return mime.value.startsWith('image/') ? 'image' : 'other';
});
const previewSrc = computed(() => {
    if (props.generated) {
        return props.generated.src;
    }

    return props.attachment ? store.viewUrl(props.attachment.id) : '';
});

// Details form (edit a stored attachment OR set info while saving a generated
// doc). Shown on demand.
const formOpen = ref(false);
const formType = ref('');
const formDescription = ref('');
const saving = ref(false);

watch(
    () => props.open,
    (open) => {
        if (!open) {
            formOpen.value = false;
            saving.value = false;
        }
    },
);

function openForm(): void {
    formType.value = props.attachment?.type ?? '';
    formDescription.value = props.attachment?.description ?? '';
    formOpen.value = true;
}

async function submitForm(): Promise<void> {
    saving.value = true;

    try {
        if (props.generated) {
            await store.fileClassDocument(
                props.generated.classId,
                props.generated.kind,
                { type: formType.value, description: formDescription.value },
            );
            toast.success('Saved to this class’s files.');
            emit('update:open', false);
        } else if (props.attachment) {
            await store.updateInfo(props.attachment.id, {
                type: formType.value,
                description: formDescription.value,
            });
            toast.success('Details updated.');
            formOpen.value = false;
        }
    } catch {
        toast.error('Could not save. Please try again.');
    } finally {
        saving.value = false;
    }
}

const setOpen = (v: boolean) => emit('update:open', v);
</script>

<template>
    <Dialog :open="open" @update:open="setOpen">
        <DialogContent
            v-if="attachment || generated"
            class="max-h-[92vh] w-[95vw] overflow-y-auto sm:max-w-[1400px]"
        >
            <DialogHeader>
                <DialogTitle class="truncate">{{ title }}</DialogTitle>
                <DialogDescription>
                    <template v-if="generated">Generated document</template>
                    <template v-else>
                        <span v-if="attachment?.mime">{{ attachment.mime }}</span>
                        <span v-if="attachment?.uploaded_by_name">
                            · uploaded by {{ attachment.uploaded_by_name }}
                        </span>
                    </template>
                </DialogDescription>
            </DialogHeader>

            <!-- Stored-attachment type/description (read) + edit affordance. -->
            <div
                v-if="attachment && !formOpen"
                class="flex items-start justify-between gap-3 text-sm"
            >
                <div class="min-w-0">
                    <span
                        v-if="attachment.type"
                        class="rounded bg-muted px-1.5 py-0.5 text-[10px] font-medium uppercase tracking-wide text-muted-foreground"
                    >
                        {{ attachment.type }}
                    </span>
                    <p
                        v-if="attachment.description"
                        class="mt-1 text-muted-foreground"
                    >
                        {{ attachment.description }}
                    </p>
                </div>
                <Button
                    v-if="attachment.can_edit"
                    variant="outline"
                    size="sm"
                    data-testid="viewer-edit"
                    @click="openForm"
                >
                    Edit details
                </Button>
            </div>

            <div class="min-h-[40vh]">
                <iframe
                    v-if="previewKind === 'pdf'"
                    :src="previewSrc"
                    :title="title"
                    class="h-[78vh] w-full rounded border border-border"
                />
                <img
                    v-else-if="previewKind === 'image'"
                    :src="previewSrc"
                    :alt="title"
                    class="mx-auto max-h-[78vh] object-contain"
                />
                <p
                    v-else
                    class="py-12 text-center text-sm text-muted-foreground"
                >
                    No preview available for this file type. Use the download
                    action in the file’s menu.
                </p>
            </div>

            <!-- Save-to-files (generated docs) trigger. -->
            <div v-if="generated && !formOpen" class="flex justify-end">
                <Button
                    variant="outline"
                    data-testid="viewer-save-to-files"
                    @click="openForm"
                >
                    Save to this class’s files
                </Button>
            </div>

            <!-- Shared details form: edits an attachment, or sets info on save. -->
            <div v-if="formOpen" class="space-y-3 rounded border border-border p-3">
                <AttachmentInfoFields
                    v-model:type="formType"
                    v-model:description="formDescription"
                    id-prefix="viewer"
                />
                <div class="flex justify-end gap-2">
                    <Button
                        variant="outline"
                        size="sm"
                        :disabled="saving"
                        @click="formOpen = false"
                    >
                        Cancel
                    </Button>
                    <Button
                        size="sm"
                        :disabled="saving"
                        data-testid="viewer-save"
                        @click="submitForm"
                    >
                        {{ saving ? 'Saving…' : 'Save' }}
                    </Button>
                </div>
            </div>
        </DialogContent>
    </Dialog>
</template>
