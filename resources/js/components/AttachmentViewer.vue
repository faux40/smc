<script setup lang="ts">
/*
 * Embedded attachment viewer (modal).
 *
 *   <AttachmentViewer v-model:open="open" :attachment="row" />
 *
 * Previews PDFs and images inline via the same-origin /view endpoint, which
 * 302-redirects to a short-lived signed URL (inline disposition) — the app
 * never streams the bytes. Unsupported types fall back to a message pointing
 * at the file's menu. No own Download button — the inline preview / the
 * actions menu handle downloading.
 */
import { computed } from 'vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { useAttachmentsStore } from '@/stores/attachments';
import type { AttachmentRow } from '@/stores/attachments';

const props = defineProps<{
    open: boolean;
    attachment: AttachmentRow | null;
}>();
const emit = defineEmits<{ (e: 'update:open', v: boolean): void }>();

const store = useAttachmentsStore();

type PreviewKind = 'pdf' | 'image' | 'other';

const previewKind = computed<PreviewKind>(() => {
    const mime = props.attachment?.mime ?? '';

    if (mime === 'application/pdf') {
        return 'pdf';
    }

    if (mime.startsWith('image/')) {
        return 'image';
    }

    return 'other';
});

const viewSrc = computed(() =>
    props.attachment ? store.viewUrl(props.attachment.id) : '',
);

const setOpen = (v: boolean) => emit('update:open', v);
</script>

<template>
    <Dialog :open="open" @update:open="setOpen">
        <DialogContent
            v-if="attachment"
            class="max-h-[92vh] w-[95vw] overflow-y-auto sm:max-w-[1400px]"
        >
            <DialogHeader>
                <DialogTitle class="truncate">{{
                    attachment.filename
                }}</DialogTitle>
                <DialogDescription>
                    <span v-if="attachment.mime">{{ attachment.mime }}</span>
                    <span v-if="attachment.uploaded_by_name">
                        · uploaded by {{ attachment.uploaded_by_name }}
                    </span>
                </DialogDescription>
            </DialogHeader>

            <div class="min-h-[40vh]">
                <iframe
                    v-if="previewKind === 'pdf'"
                    :src="viewSrc"
                    :title="attachment.filename"
                    class="h-[78vh] w-full rounded border border-border"
                />
                <img
                    v-else-if="previewKind === 'image'"
                    :src="viewSrc"
                    :alt="attachment.filename"
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
        </DialogContent>
    </Dialog>
</template>
