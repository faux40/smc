<script setup lang="ts">
/*
 * Admin+ add/edit dialog for a merge-field definition. Key grammar and
 * uniqueness (incl. the no-shadowing-system-keys rule) are enforced
 * server-side; 422s surface through the shared error store.
 */
import { computed, reactive, watch } from 'vue';
import ComboboxInput from '@/components/ComboboxInput.vue';
import ErrorBanner from '@/components/ErrorBanner.vue';
import InputError from '@/components/InputError.vue';
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
import { useErrorStore } from '@/stores/errors';
import { useMergeDataStore } from '@/stores/mergeData';
import type { MergeFieldRow, MergeFieldType } from '@/stores/mergeData';

const FORM_CTX = 'form:merge-field';

const props = defineProps<{
    open: boolean;
    editing: MergeFieldRow | null;
}>();

const emit = defineEmits<{ (e: 'update:open', v: boolean): void }>();

const store = useMergeDataStore();
const errorStore = useErrorStore();
const fieldErrors = useFieldErrors(FORM_CTX);

const TYPE_OPTIONS: Array<{ value: MergeFieldType; label: string }> = [
    { value: 'text', label: 'Text (single line)' },
    { value: 'multiline', label: 'Text (multiple lines)' },
    { value: 'date', label: 'Date' },
    { value: 'list', label: 'List (repeats in templates)' },
];

interface FormState {
    key: string;
    label: string;
    type: MergeFieldType;
    field_group: string;
    help: string;
    submitting: boolean;
}

const form = reactive<FormState>({
    key: '',
    label: '',
    type: 'text',
    field_group: '',
    help: '',
    submitting: false,
});

watch(
    () => [props.open, props.editing] as const,
    ([open]) => {
        if (!open) {
            return;
        }

        form.key = props.editing?.key ?? '';
        form.label = props.editing?.label ?? '';
        form.type = props.editing?.type ?? 'text';
        form.field_group = props.editing?.field_group ?? '';
        form.help = props.editing?.help ?? '';
        errorStore.clear(FORM_CTX);
    },
    { immediate: true },
);

const groupSuggestions = computed(() =>
    store.groupedFields
        .map((g) => g.group)
        .filter((g): g is string => g !== null),
);

const title = computed(() => (props.editing ? 'Edit field' : 'New field'));

const submit = async () => {
    form.submitting = true;
    errorStore.clear(FORM_CTX);

    const payload = {
        key: form.key.trim(),
        label: form.label.trim(),
        type: form.type,
        field_group: form.field_group.trim() || null,
        help: form.help.trim() || null,
    };

    try {
        if (props.editing) {
            await store.updateField(props.editing.id, payload);
        } else {
            await store.createField(payload);
        }

        emit('update:open', false);
    } catch (e) {
        errorStore.reportFromAxios(e, FORM_CTX, {
            fallback: 'Failed to save field',
        });
    } finally {
        form.submitting = false;
    }
};
</script>

<template>
    <Dialog :open="open" @update:open="emit('update:open', $event)">
        <DialogContent class="sm:max-w-2xl">
            <form @submit.prevent="submit" class="space-y-4">
                <DialogHeader>
                    <DialogTitle>{{ title }}</DialogTitle>
                    <DialogDescription>
                        Defines a <span class="font-mono">${key}</span> merge
                        token document templates can use. System fields cannot
                        be shadowed — pick a distinct key.
                    </DialogDescription>
                </DialogHeader>

                <ErrorBanner :context="FORM_CTX" />

                <div class="grid grid-cols-2 gap-4">
                    <div class="grid gap-2">
                        <Label for="merge_field_key">Key</Label>
                        <Input
                            id="merge_field_key"
                            v-model="form.key"
                            data-testid="field-key"
                            class="font-mono"
                            placeholder="union_rep"
                            required
                        />
                        <p class="text-xs text-muted-foreground">
                            Lowercase letters, digits, underscores; starts with
                            a letter.
                        </p>
                        <InputError :message="fieldErrors.message('key')" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="merge_field_label">Label</Label>
                        <Input
                            id="merge_field_label"
                            v-model="form.label"
                            data-testid="field-label"
                            required
                        />
                        <InputError :message="fieldErrors.message('label')" />
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div class="grid gap-2">
                        <Label for="merge_field_type">Type</Label>
                        <select
                            id="merge_field_type"
                            v-model="form.type"
                            data-testid="field-type"
                            class="h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs"
                        >
                            <option
                                v-for="opt in TYPE_OPTIONS"
                                :key="opt.value"
                                :value="opt.value"
                            >
                                {{ opt.label }}
                            </option>
                        </select>
                        <InputError :message="fieldErrors.message('type')" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="merge_field_group">Group</Label>
                        <ComboboxInput
                            id="merge_field_group"
                            v-model="form.field_group"
                            :suggestions="groupSuggestions"
                            placeholder="e.g. Agency profile"
                        />
                        <InputError
                            :message="fieldErrors.message('field_group')"
                        />
                    </div>
                </div>

                <div class="grid gap-2">
                    <Label for="merge_field_help">Help text</Label>
                    <Input
                        id="merge_field_help"
                        v-model="form.help"
                        placeholder="Shown under the input on this page"
                    />
                    <InputError :message="fieldErrors.message('help')" />
                </div>

                <DialogFooter>
                    <Button
                        type="button"
                        variant="outline"
                        @click="emit('update:open', false)"
                    >
                        Cancel
                    </Button>
                    <Button type="submit" :disabled="form.submitting">
                        {{ form.submitting ? 'Saving…' : 'Save' }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
