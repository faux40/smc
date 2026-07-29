<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import CertificatePreview from '@/components/CertificatePreview.vue';
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

const props = defineProps<{ context?: string }>();

const fieldErrors = useFieldErrors(props.context ?? '');

const page = usePage();
const orgName = computed(
    () => (page.props.org as { name?: string } | null)?.name ?? undefined,
);
</script>

<template>
    <div class="grid items-start gap-4 lg:grid-cols-2">
        <!-- Editor -->
        <div class="space-y-3">
            <div class="grid gap-2">
                <Label for="cert_title">SMC Certificate title</Label>
                <Input
                    id="cert_title"
                    v-model="title"
                    placeholder="e.g. Fall Protection Authorized Person"
                />
                <InputError :message="fieldErrors.message('cert_title')" />
            </div>

            <div class="grid gap-2">
                <Label for="cert_text">SMC Certificate text</Label>
                <textarea
                    id="cert_text"
                    v-model="text"
                    rows="8"
                    class="w-full rounded border border-input bg-background p-2 text-sm"
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

        <!-- Live preview -->
        <div class="lg:sticky lg:top-4">
            <p class="mb-1 text-xs font-medium text-muted-foreground">
                Preview
            </p>
            <CertificatePreview
                :org-name="orgName"
                :cert-title="title"
                :cert-text="text"
            />
        </div>
    </div>
</template>
