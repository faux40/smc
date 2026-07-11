<script setup lang="ts">
/*
 * Template library tab (Phase D2): system + org templates. Admins
 * upload new org templates (server extracts ${keys} and drafts unknown
 * fields), replace a file (new chained version), rename, delete —
 * system templates are read-only here (console-managed).
 */
import { computed, reactive, ref } from 'vue';
import { toast } from 'vue-sonner';
import DataTable from '@/components/DataTable.vue';
import ErrorBanner from '@/components/ErrorBanner.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useFieldErrors } from '@/composables/useFieldErrors';
import { useDocTemplatesStore } from '@/stores/docTemplates';
import type { DocTemplateRow } from '@/stores/docTemplates';
import { useErrorStore } from '@/stores/errors';
import { useMergeDataStore } from '@/stores/mergeData';

const PAGE_CTX = 'page:documents';
const FORM_CTX = 'form:doc-template';

const props = defineProps<{ canDefine: boolean }>();

const COLUMNS = [
    { key: 'name', label: 'Template', sortable: false },
    { key: 'format', label: 'Format', sortable: false },
    { key: 'fields', label: 'Merge fields', sortable: false },
    { key: 'actions', label: '', sortable: false },
];

const store = useDocTemplatesStore();
const mergeData = useMergeDataStore();
const errorStore = useErrorStore();
const fieldErrors = useFieldErrors(FORM_CTX);

const dialogOpen = ref(false);
const editing = ref<DocTemplateRow | null>(null);
const form = reactive({ name: '', description: '', file: null as File | null, submitting: false });
const replaceTarget = ref<DocTemplateRow | null>(null);
const replaceInput = ref<HTMLInputElement>();

const dialogTitle = computed(() => (editing.value ? 'Edit template' : 'Upload template'));

/** Placeholder keys with no org data at the org-wide default. */
const missingCount = (t: DocTemplateRow): number =>
    mergeData.missingKeysFor(t.placeholders, '', '').length;

const openUpload = () => {
    editing.value = null;
    form.name = '';
    form.description = '';
    form.file = null;
    errorStore.clear(FORM_CTX);
    dialogOpen.value = true;
};

const openEdit = (t: DocTemplateRow) => {
    editing.value = t;
    form.name = t.name;
    form.description = t.description ?? '';
    form.file = null;
    errorStore.clear(FORM_CTX);
    dialogOpen.value = true;
};

const onFilePicked = (event: Event) => {
    form.file = (event.target as HTMLInputElement).files?.[0] ?? null;
};

const submit = async () => {
    form.submitting = true;
    errorStore.clear(FORM_CTX);

    try {
        if (editing.value) {
            await store.rename(editing.value.id, form.name.trim(), form.description.trim() || null);
            toast.success('Template updated');
        } else {
            if (!form.file) {
                return;
            }

            const created = await store.upload(form.file, form.name.trim(), form.description.trim() || null);
            toast.success(`"${created.name}" uploaded — ${created.placeholders.length} merge fields found`);
            // Upload may have drafted new fields; refresh the registry.
            void mergeData.reload();
        }

        dialogOpen.value = false;
    } catch (e) {
        errorStore.reportFromAxios(e, FORM_CTX, {
            fallback: 'Failed to save template',
        });
    } finally {
        form.submitting = false;
    }
};

const startReplace = (t: DocTemplateRow) => {
    replaceTarget.value = t;
    replaceInput.value?.click();
};

const onReplaceFile = async (event: Event) => {
    const file = (event.target as HTMLInputElement).files?.[0];
    const target = replaceTarget.value;
    (event.target as HTMLInputElement).value = '';

    if (!file || !target) {
        return;
    }

    try {
        const next = await store.replace(target.id, file);
        toast.success(`"${next.name}" is now v${next.version}`);
        void mergeData.reload();
    } catch (e) {
        errorStore.reportFromAxios(e, PAGE_CTX, {
            fallback: 'Failed to replace the template file',
        });
    }
};

const remove = async (t: DocTemplateRow) => {
    if (!window.confirm(`Delete the template "${t.name}"? Existing generated documents keep their files.`)) {
        return;
    }

    try {
        await store.destroy(t.id);
    } catch (e) {
        errorStore.reportFromAxios(e, PAGE_CTX, {
            fallback: 'Failed to delete template',
        });
    }
};
</script>

<template>
    <div class="flex flex-col gap-4">
        <div class="flex justify-end">
            <Button v-if="props.canDefine" data-testid="upload-template" @click="openUpload">
                + Upload template
            </Button>
        </div>

        <DataTable
            view-id="doc-templates"
            :default-columns="COLUMNS"
            :rows="store.library"
            :sort-key="null"
            sort-dir="asc"
            :row-key="(row) => row.id"
        >
            <template #col-name="{ row }">
                <div class="font-medium">
                    {{ row.name }}
                    <Badge v-if="row.is_system" variant="secondary" class="ml-1">System</Badge>
                </div>
                <div v-if="row.description" class="text-xs text-muted-foreground">
                    {{ row.description }}
                </div>
                <div class="font-mono text-xs text-muted-foreground">
                    {{ row.original_filename }} · v{{ row.version }}
                </div>
            </template>

            <template #col-format="{ row }">
                <Badge variant="outline">{{ row.extension.toUpperCase() }}</Badge>
            </template>

            <template #col-fields="{ row }">
                <span class="text-sm">{{ row.placeholders.length }} fields</span>
                <span
                    v-if="missingCount(row) > 0"
                    class="ml-2 text-xs text-amber-600"
                    data-testid="missing-count"
                >
                    {{ missingCount(row) }} missing data
                </span>
            </template>

            <template #col-actions="{ row }">
                <div v-if="row.can_edit" class="flex items-center justify-end gap-3 text-xs">
                    <button
                        type="button"
                        class="text-primary hover:underline"
                        data-testid="replace-template"
                        @click="startReplace(row)"
                    >
                        Replace file
                    </button>
                    <button
                        type="button"
                        class="text-primary hover:underline"
                        data-testid="edit-template"
                        @click="openEdit(row)"
                    >
                        Edit
                    </button>
                    <button
                        v-if="row.can_delete"
                        type="button"
                        class="text-destructive hover:underline"
                        data-testid="delete-template"
                        @click="remove(row)"
                    >
                        Delete
                    </button>
                </div>
            </template>

            <template #empty>
                No templates yet.
                <span v-if="props.canDefine">Upload a .docx or .odt master with ${'{'}key{'}'} placeholders.</span>
            </template>
        </DataTable>

        <input
            ref="replaceInput"
            type="file"
            accept=".docx,.odt"
            class="hidden"
            data-testid="replace-file-input"
            @change="onReplaceFile"
        />

        <Dialog v-model:open="dialogOpen">
            <DialogContent class="sm:max-w-2xl">
                <form @submit.prevent="submit" class="space-y-4">
                    <DialogHeader>
                        <DialogTitle>{{ dialogTitle }}</DialogTitle>
                        <DialogDescription>
                            DOCX or ODT master with inline
                            <span class="font-mono">${key}</span> merge tokens.
                            Unknown keys are auto-registered as draft fields on
                            the Document data page.
                        </DialogDescription>
                    </DialogHeader>

                    <ErrorBanner :context="FORM_CTX" />

                    <div class="grid grid-cols-2 gap-4">
                        <div class="grid gap-2">
                            <Label for="template_name">Name</Label>
                            <Input id="template_name" v-model="form.name" data-testid="template-name" required />
                            <InputError :message="fieldErrors.message('name')" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="template_description">Description</Label>
                            <Input id="template_description" v-model="form.description" />
                            <InputError :message="fieldErrors.message('description')" />
                        </div>
                    </div>

                    <div v-if="!editing" class="grid gap-2">
                        <Label for="template_file">Template file</Label>
                        <input
                            id="template_file"
                            type="file"
                            accept=".docx,.odt"
                            data-testid="template-file"
                            class="text-sm"
                            @change="onFilePicked"
                        />
                        <InputError :message="fieldErrors.message('file')" />
                    </div>

                    <DialogFooter>
                        <Button type="button" variant="outline" @click="dialogOpen = false">
                            Cancel
                        </Button>
                        <Button
                            type="submit"
                            :disabled="form.submitting || (!editing && !form.file)"
                        >
                            {{ form.submitting ? 'Saving…' : 'Save' }}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </div>
</template>
