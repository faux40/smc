<script setup lang="ts">
/*
 * Embedded attachment viewer (modal).
 *
 *   <AttachmentViewer v-model:open="open" :attachment="row" />
 *
 * Previews PDFs and images inline via the same-origin /view endpoint, which
 * 302-redirects to a short-lived signed URL (inline disposition) — the app
 * never streams the bytes. Unsupported types fall back to a download prompt.
 * A download link is always offered.
 */
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
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
const downloadHref = computed(() =>
    props.attachment ? store.downloadUrl(props.attachment.id) : '',
);

const setOpen = (v: boolean) => emit('update:open', v);
</script>

<template>
    <Dialog :open="open" @update:open="setOpen">
        <DialogContent v-if="attachment" class="max-w-4xl">
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
                    class="h-[70vh] w-full rounded border border-border"
                />
                <img
                    v-else-if="previewKind === 'image'"
                    :src="viewSrc"
                    :alt="attachment.filename"
                    class="mx-auto max-h-[70vh] object-contain"
                />
                <p
                    v-else
                    class="py-12 text-center text-sm text-muted-foreground"
                >
                    No preview available for this file type. Use Download to
                    open it.
                </p>
            </div>

            <DialogFooter>
                <Button
                    as="a"
                    variant="outline"
                    :href="downloadHref"
                    @click="setOpen(false)"
                >
                    Download
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
