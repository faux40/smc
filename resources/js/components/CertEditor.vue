<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import CertificatePreviewPane from '@/components/CertificatePreviewPane.vue';
import InputError from '@/components/InputError.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useFieldErrors } from '@/composables/useFieldErrors';

/**
 * Certificate title + body editor with a live, side-by-side preview of the
 * resulting certificate. Self-contained + reusable: drop it into the training
 * form and the per-class cert modal. Two-way binds both fields via v-model.
 */
const title = defineModel<string>('title', { required: true });
const text = defineModel<string>('text', { required: true });

const props = defineProps<{
    context?: string;
    /** Names the certificate being previewed — a class has one per topic. */
    label?: string;
    /**
     * Distinguishes the input ids when more than one editor is on the page —
     * a class shows one per topic. Defaults to the historical ids so the
     * single-editor callers (the training form) are untouched.
     */
    idPrefix?: string;
    disabled?: boolean;
}>();

const id = (field: string) =>
    props.idPrefix ? `${props.idPrefix}_${field}` : field;

const fieldErrors = useFieldErrors(props.context ?? '');

const page = usePage();
const orgName = computed(
    () => (page.props.org as { name?: string } | null)?.name ?? undefined,
);
</script>

<template>
    <div class="flex flex-col items-start gap-4 lg:flex-row">
        <!-- Editor -->
        <div class="min-w-0 flex-1 space-y-3">
            <div class="grid gap-2">
                <Label :for="id('cert_title')">SMC Certificate title</Label>
                <Input
                    :id="id('cert_title')"
                    v-model="title"
                    :disabled="disabled"
                    data-testid="cert-title"
                    placeholder="e.g. Fall Protection Authorized Person"
                />
                <InputError :message="fieldErrors.message('cert_title')" />
            </div>

            <div class="grid gap-2">
                <Label :for="id('cert_text')">SMC Certificate text</Label>
                <textarea
                    :id="id('cert_text')"
                    v-model="text"
                    rows="8"
                    :disabled="disabled"
                    data-testid="cert-text"
                    class="w-full rounded border border-input bg-background p-2 text-sm disabled:opacity-60"
                    placeholder="Satisfies **Cal/OSHA** requirements…"
                ></textarea>
                <p class="text-xs text-muted-foreground">
                    Markdown: press Enter for a line break, a blank line for a
                    new paragraph; <code>**bold**</code> and
                    <code>*italic*</code> are supported. The preview updates as
                    you type; the printed certificate is the downloaded PDF.
                </p>
                <InputError :message="fieldErrors.message('cert_text')" />
            </div>
        </div>

        <!-- Live preview, beside the fields: capped small enough to leave
             them room, with full size a click away. -->
        <div class="shrink-0 lg:sticky lg:top-4">
            <CertificatePreviewPane
                :org-name="orgName"
                :cert-title="title"
                :cert-text="text"
                :label="label"
            />
        </div>
    </div>
</template>
