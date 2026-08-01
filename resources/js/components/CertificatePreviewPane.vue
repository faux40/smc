<script setup lang="ts">
import { Maximize2 } from 'lucide-vue-next';
import { ref } from 'vue';
import CertificatePreview from '@/components/CertificatePreview.vue';
import type { CertificatePreviewProps } from '@/components/CertificatePreview.vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';

/**
 * A certificate preview sized to sit beside the fields it previews, plus a
 * full-size copy on request.
 *
 * The preview is a fixed 11:8.5 sheet, so its height follows its width — left
 * unbounded it becomes the tallest thing on the form, which is backwards for
 * something that only confirms roughly how the title and body will lay out.
 * The thumbnail is capped; the dialog is where it gets read.
 *
 * Both copies render the same live component, so what the dialog shows is
 * what the form shows, larger — never a snapshot taken when it opened.
 */
const props = defineProps<
    CertificatePreviewProps & {
        /** Names the thing being previewed; a class shows one per topic. */
        label?: string;
    }
>();

const open = ref(false);
</script>

<template>
    <div class="space-y-1.5">
        <div class="flex items-baseline justify-between gap-2">
            <p class="text-xs font-medium text-muted-foreground">Preview</p>
            <button
                type="button"
                data-testid="cert-preview-open"
                class="inline-flex items-center gap-1 text-xs text-primary hover:underline"
                :aria-label="
                    props.label
                        ? `Enlarge the certificate preview for ${props.label}`
                        : 'Enlarge the certificate preview'
                "
                @click="open = true"
            >
                <Maximize2 class="size-3" />
                Enlarge
            </button>
        </div>

        <!-- 11:8.5, so height follows width: 280px wide ≈ 216px tall. Sized
             to sit beside the fields rather than under them. -->
        <div data-testid="cert-thumbnail" class="w-full max-w-[280px]">
            <CertificatePreview v-bind="props" />
        </div>

        <Dialog v-model:open="open">
            <DialogContent class="max-h-[92vh] overflow-y-auto sm:max-w-5xl">
                <DialogHeader>
                    <DialogTitle>
                        Certificate preview<template v-if="props.label">
                            — {{ props.label }}</template
                        >
                    </DialogTitle>
                    <DialogDescription>
                        Roughly how the title and body will lay out. The
                        printed certificate is the downloaded PDF.
                    </DialogDescription>
                </DialogHeader>

                <CertificatePreview v-bind="props" />
            </DialogContent>
        </Dialog>
    </div>
</template>
