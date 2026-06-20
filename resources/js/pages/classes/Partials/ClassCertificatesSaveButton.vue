<script setup lang="ts">
/*
 * "Save certificates to files" — files a PDF copy of a completed class's
 * certificates into the class's attachments. Opens a confirm popup; on
 * confirm, the attachments store renders + stores the copy and refreshes the
 * class's attachment list (so it appears alongside other class documents).
 */
import { ref } from 'vue';
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

const props = defineProps<{ classId: string }>();

const attachments = useAttachmentsStore();
const open = ref(false);
const saving = ref(false);

async function confirm(): Promise<void> {
    saving.value = true;

    try {
        await attachments.fileClassCertificates(props.classId);
        toast.success('Certificates saved to this class’s files.');
        open.value = false;
    } catch {
        toast.error('Could not save the certificates. Please try again.');
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <Button
        variant="outline"
        data-testid="save-certs-btn"
        @click="open = true"
    >
        Save certificates to files
    </Button>

    <Dialog v-model:open="open">
        <DialogContent>
            <DialogHeader>
                <DialogTitle>Save certificates to this class’s files?</DialogTitle>
                <DialogDescription>
                    A single PDF of all issued certificates will be filed under
                    this class’s attachments, named with the class and the
                    current date &amp; time so you can find it later. A new copy
                    is saved each time.
                </DialogDescription>
            </DialogHeader>
            <DialogFooter>
                <Button
                    variant="outline"
                    :disabled="saving"
                    @click="open = false"
                >
                    Cancel
                </Button>
                <Button
                    :disabled="saving"
                    data-testid="save-certs-confirm"
                    @click="confirm"
                >
                    {{ saving ? 'Saving…' : 'Save' }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
