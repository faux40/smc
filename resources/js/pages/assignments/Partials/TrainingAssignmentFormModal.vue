<script setup lang="ts">
/*
 * Training assignment form modal.
 *
 * create — pick user + a single training-or-requirement from the combined
 *   picker. The picker value prefix ("training:" / "requirement:") drives
 *   which store action fires. No source_type concept is exposed to the user.
 *
 * view — read-only display; offers Delete (Admin+).
 */
import { computed, reactive, ref, watch } from 'vue';
import ErrorBanner from '@/components/ErrorBanner.vue';
import TrainingOrRequirementPicker from '@/components/TrainingOrRequirementPicker.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { useFieldErrors } from '@/composables/useFieldErrors';
import { useTrainingAssignmentsStore } from '@/stores/trainingAssignments';
import type { TrainingAssignmentRow } from '@/stores/trainingAssignments';
import { useErrorStore } from '@/stores/errors';

const FORM_CTX = 'form:training-assignment';

type Mode = 'create' | 'view';

const props = defineProps<{
    open: boolean;
    mode: Mode;
    target?: TrainingAssignmentRow | null;
    initialUserId?: string | null;
}>();

const emit = defineEmits<{ (e: 'update:open', v: boolean): void }>();

const taStore = useTrainingAssignmentsStore();
const errorStore = useErrorStore();
const fieldErrors = useFieldErrors(FORM_CTX);

const form = reactive({
    user_id: '' as string,
    selectedItem: '' as string,
});
const submitting = ref(false);
const deleting = ref(false);

const isCreate = computed(() => props.mode === 'create');
const isView = computed(() => props.mode === 'view');
const title = computed(() =>
    isCreate.value ? 'Assign training' : props.target?.name ?? 'Training assignment',
);
const canDelete = computed(() => isView.value && props.target?.can_delete === true);

watch(
    () => props.open,
    (open) => {
        if (!open) return;
        errorStore.clear(FORM_CTX);
        if (isCreate.value) {
            form.user_id = props.initialUserId ?? '';
            form.selectedItem = '';
        }
    },
    { immediate: true },
);

const submit = async () => {
    if (!isCreate.value) return;

    submitting.value = true;
    errorStore.clear(FORM_CTX);

    try {
        const idx = form.selectedItem.indexOf(':');
        const type = form.selectedItem.slice(0, idx);
        const id = form.selectedItem.slice(idx + 1);

        if (type === 'requirement') {
            await taStore.assignFromRequirement(form.user_id, id);
        } else {
            await taStore.assignDirect(form.user_id, id);
        }
        emit('update:open', false);
    } catch (e) {
        errorStore.reportFromAxios(e, FORM_CTX, {
            fallback: 'Failed to save assignment',
        });
    } finally {
        submitting.value = false;
    }
};

const remove = async () => {
    if (!props.target) return;

    if (!window.confirm(`Delete the "${props.target.name}" training assignment?`)) {
        return;
    }

    deleting.value = true;
    errorStore.clear(FORM_CTX);

    try {
        await taStore.destroy(props.target.id);
        emit('update:open', false);
    } catch (e) {
        errorStore.reportFromAxios(e, FORM_CTX, {
            fallback: 'Failed to delete assignment',
        });
    } finally {
        deleting.value = false;
    }
};
</script>

<template>
    <Dialog :open="open" @update:open="(v) => emit('update:open', v)">
        <DialogContent class="sm:max-w-md">
            <form @submit.prevent="submit">
                <DialogHeader>
                    <DialogTitle>{{ title }}</DialogTitle>
                    <DialogDescription>
                        <template v-if="isCreate">
                            Pick a requirement or individual training to assign.
                        </template>
                        <template v-else>
                            Training assignment details.
                        </template>
                    </DialogDescription>
                </DialogHeader>

                <ErrorBanner :context="FORM_CTX" class="mt-4" />

                <!-- CREATE MODE -->
                <div v-if="isCreate" class="mt-4 grid gap-2">
                    <Label for="ta_item">Training or requirement</Label>
                    <TrainingOrRequirementPicker
                        id="ta_item"
                        v-model="form.selectedItem"
                        :disabled="submitting"
                    />
                    <InputError :message="fieldErrors.message('training_id') || fieldErrors.message('requirement_id')" />
                </div>

                <!-- VIEW MODE -->
                <div v-else class="mt-4 space-y-3">
                    <div class="text-sm">
                        <p class="font-medium">{{ target?.name }}</p>
                        <p v-if="target?.expires_at" class="text-xs text-muted-foreground">
                            Expires {{ target.expires_at }}
                        </p>
                        <p v-else class="text-xs text-muted-foreground">No expiry set</p>
                    </div>

                    <div v-if="target?.active_sources?.length" class="text-xs text-muted-foreground">
                        <p class="mb-1 font-medium">Assigned via</p>
                        <ul class="space-y-0.5">
                            <li v-for="s in target.active_sources" :key="s.id">
                                {{ s.sourceable_type ? 'Requirement' : 'Direct assignment' }}
                            </li>
                        </ul>
                    </div>
                </div>

                <DialogFooter class="mt-6 sm:justify-between">
                    <Button
                        v-if="canDelete"
                        type="button"
                        variant="destructive"
                        :disabled="deleting"
                        data-testid="delete-btn"
                        @click="remove"
                    >
                        {{ deleting ? 'Deleting…' : 'Delete' }}
                    </Button>
                    <span v-else />
                    <div class="flex gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            @click="emit('update:open', false)"
                        >
                            Cancel
                        </Button>
                        <Button
                            v-if="isCreate"
                            type="submit"
                            :disabled="submitting || !form.selectedItem"
                        >
                            {{ submitting ? 'Saving…' : 'Assign' }}
                        </Button>
                    </div>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
