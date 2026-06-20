<script setup lang="ts">
/*
 * Controlled "save document to files" confirm popup, shared by the
 * Certificates and Summary buttons. Each of those opens the matching document
 * in a new tab AND pops this modal so the admin is prompted to file a copy
 * into the class's attachments every time.
 */
import { computed, ref } from 'vue';
import { toast } from 'vue-sonner';
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

const props = defineProps<{
    open: boolean;
    classId: string;
    kind: 'certificates' | 'summary';
}>();
const emit = defineEmits<{ (e: 'update:open', v: boolean): void }>();

const attachments = useAttachmentsStore();
const saving = ref(false);

const label = computed(() =>
    props.kind === 'certificates' ? 'certificates' : 'summary',
);

async function confirm(): Promise<void> {
    saving.value = true;

    try {
        await attachments.fileClassDocument(props.classId, props.kind);
        toast.success(`Saved the ${label.value} to this class’s files.`);
        emit('update:open', false);
    } catch {
        toast.error(`Could not save the ${label.value}. Please try again.`);
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <Dialog :open="open" @update:open="(v) => emit('update:open', v)">
        <DialogContent>
            <DialogHeader>
                <DialogTitle>Save {{ label }} to this class’s files?</DialogTitle>
                <DialogDescription>
                    A PDF of the {{ label }} will be filed under this class’s
                    attachments, named with the class and the current date &amp;
                    time so you can find it later. A new copy is saved each time.
                </DialogDescription>
            </DialogHeader>
            <DialogFooter>
                <Button
                    variant="outline"
                    :disabled="saving"
                    @click="emit('update:open', false)"
                >
                    Cancel
                </Button>
                <Button
                    :disabled="saving"
                    data-testid="doc-save-modal-confirm"
                    @click="confirm"
                >
                    {{ saving ? 'Saving…' : 'Save' }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
