<script setup lang="ts">
/*
 * Controlled "save certificates to files" confirm popup. Opened by the
 * Certificates button (which also opens the cert PDF in a new tab) so the
 * admin is prompted to file a copy every time they generate certificates.
 *
 * NOTE: ClassCertificatesSaveButton currently carries a parallel inline copy
 * of this popup (left as-is during dev); unify onto this component later.
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

const props = defineProps<{ open: boolean; classId: string }>();
const emit = defineEmits<{ (e: 'update:open', v: boolean): void }>();

const attachments = useAttachmentsStore();
const saving = ref(false);

async function confirm(): Promise<void> {
    saving.value = true;

    try {
        await attachments.fileClassCertificates(props.classId);
        toast.success('Certificates saved to this class’s files.');
        emit('update:open', false);
    } catch {
        toast.error('Could not save the certificates. Please try again.');
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <Dialog :open="open" @update:open="(v) => emit('update:open', v)">
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
                    @click="emit('update:open', false)"
                >
                    Cancel
                </Button>
                <Button
                    :disabled="saving"
                    data-testid="cert-save-modal-confirm"
                    @click="confirm"
                >
                    {{ saving ? 'Saving…' : 'Save' }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
