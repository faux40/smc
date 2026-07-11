<script setup lang="ts">
/*
 * "New document" bar (Phase D2): pick a template + optional
 * location/department variation, see whether the org's data covers the
 * template's placeholders, and queue the generation. Completeness is
 * advisory — generating with gaps is allowed, the output prints
 * visible --KEY-- placeholders.
 */
import { computed, ref } from 'vue';
import { toast } from 'vue-sonner';
import ComboboxInput from '@/components/ComboboxInput.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { useVariationSuggestions } from '@/composables/useVariationSuggestions';
import { data as documentDataRoute } from '@/routes/documents';
import { useDocTemplatesStore } from '@/stores/docTemplates';
import { useErrorStore } from '@/stores/errors';
import { useGeneratedDocumentsStore } from '@/stores/generatedDocuments';
import { useMergeDataStore } from '@/stores/mergeData';

const PAGE_CTX = 'page:documents';

const templatesStore = useDocTemplatesStore();
const generatedStore = useGeneratedDocumentsStore();
const mergeData = useMergeDataStore();
const errorStore = useErrorStore();

const templateId = ref('');
const location = ref('');
const department = ref('');
const submitting = ref(false);

const { location: locationSuggestions, department: departmentSuggestions } =
    useVariationSuggestions();

const selectedTemplate = computed(() =>
    templatesStore.library.find((t) => t.id === templateId.value),
);

const missingKeys = computed(() =>
    selectedTemplate.value
        ? mergeData.missingKeysFor(
              selectedTemplate.value.placeholders,
              location.value,
              department.value,
          )
        : [],
);

const generate = async () => {
    if (!templateId.value) {
        return;
    }

    submitting.value = true;

    try {
        await generatedStore.generate(templateId.value, location.value, department.value);
        toast.success('Document queued — it will appear below when ready');
    } catch (e) {
        errorStore.reportFromAxios(e, PAGE_CTX, {
            fallback: 'Failed to queue the document',
        });
    } finally {
        submitting.value = false;
    }
};
</script>

<template>
    <div class="flex flex-wrap items-end gap-4 rounded-md border border-border bg-muted/30 p-4">
        <div class="grid min-w-72 gap-2">
            <Label for="generate-template">Template</Label>
            <select
                id="generate-template"
                v-model="templateId"
                data-testid="generate-template"
                class="h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs"
            >
                <option value="" disabled>Pick a template…</option>
                <option v-for="t in templatesStore.library" :key="t.id" :value="t.id">
                    {{ t.name }} (v{{ t.version }}, {{ t.extension.toUpperCase() }})
                </option>
            </select>
        </div>

        <div class="grid w-56 gap-2">
            <Label for="generate-location">Location</Label>
            <ComboboxInput
                id="generate-location"
                v-model="location"
                :suggestions="locationSuggestions"
                placeholder="Org-wide"
            />
        </div>
        <div class="grid w-56 gap-2">
            <Label for="generate-department">Department</Label>
            <ComboboxInput
                id="generate-department"
                v-model="department"
                :suggestions="departmentSuggestions"
                placeholder="Org-wide"
            />
        </div>

        <Button
            data-testid="generate-btn"
            :disabled="!templateId || submitting"
            @click="generate"
        >
            {{ submitting ? 'Queueing…' : 'Generate' }}
        </Button>

        <p
            v-if="missingKeys.length"
            data-testid="missing-data-warning"
            class="basis-full text-sm text-amber-600"
        >
            {{ missingKeys.length }} field{{ missingKeys.length === 1 ? ' has' : 's have' }}
            no data for this variation ({{ missingKeys.slice(0, 5).join(', ') }}<span v-if="missingKeys.length > 5">…</span>)
            — the document will print --KEY-- placeholders.
            <a :href="documentDataRoute().url" class="text-primary hover:underline">
                Enter data
            </a>
        </p>
    </div>
</template>
