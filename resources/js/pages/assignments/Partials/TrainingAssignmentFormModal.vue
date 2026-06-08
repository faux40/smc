<script setup lang="ts">
/*
 * Training assignment form modal.
 *
 * create — pick user + source type (direct training OR from requirement).
 *   - direct      → one training_assignment row per training
 *   - requirement → one training_assignment row per training element
 *   No dates — expiry is computed from completion history by the observer.
 *
 * view — read-only display; offers Delete (Admin+).
 *
 * Picker data:
 *   - Trainings from useTrainingsStore
 *   - Requirements from useRequirementsStore
 */
import { computed, onMounted, reactive, ref, watch } from 'vue';
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
import { Label } from '@/components/ui/label';
import { useFieldErrors } from '@/composables/useFieldErrors';
import { useTrainingAssignmentsStore } from '@/stores/trainingAssignments';
import type { TrainingAssignmentRow } from '@/stores/trainingAssignments';
import { useErrorStore } from '@/stores/errors';
import { useRequirementsStore } from '@/stores/requirements';
import { useTrainingsStore } from '@/stores/trainings';

const FORM_CTX = 'form:training-assignment';

type Mode = 'create' | 'view';
type SourceType = 'direct' | 'requirement';

const props = defineProps<{
    open: boolean;
    mode: Mode;
    target?: TrainingAssignmentRow | null;
    initialUserId?: string | null;
}>();

const emit = defineEmits<{ (e: 'update:open', v: boolean): void }>();

const taStore = useTrainingAssignmentsStore();
const requirements = useRequirementsStore();
const trainings = useTrainingsStore();
const errorStore = useErrorStore();
const fieldErrors = useFieldErrors(FORM_CTX);

const form = reactive({
    user_id: '' as string,
    source_type: 'direct' as SourceType,
    training_id: '' as string,
    requirement_id: '' as string,
});
const submitting = ref(false);
const deleting = ref(false);

const isCreate = computed(() => props.mode === 'create');
const isView = computed(() => props.mode === 'view');
const title = computed(() =>
    isCreate.value ? 'Assign training' : props.target?.name ?? 'Training assignment',
);
const canDelete = computed(() => isView.value && props.target?.can_delete === true);

const sortedTrainings = computed(() =>
    [...trainings.library].sort((a, b) => a.name.localeCompare(b.name)),
);
const sortedRequirements = computed(() =>
    [...requirements.library].sort((a, b) => a.name.localeCompare(b.name)),
);

onMounted(async () => {
    await Promise.all([trainings.load(), requirements.load()]);
});

watch(
    () => props.open,
    async (open) => {
        if (!open) return;

        errorStore.clear(FORM_CTX);

        if (isCreate.value) {
            form.user_id = props.initialUserId ?? '';
            form.source_type = 'direct';
            form.training_id = '';
            form.requirement_id = '';
        }
    },
    { immediate: true },
);

const submit = async () => {
    if (!isCreate.value) return;

    submitting.value = true;
    errorStore.clear(FORM_CTX);

    try {
        if (form.source_type === 'direct') {
            await taStore.assignDirect(form.user_id, form.training_id);
        } else {
            await taStore.assignFromRequirement(form.user_id, form.requirement_id);
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
                            Assign a training directly or pull all trainings from a
                            requirement.
                        </template>
                        <template v-else>
                            Training assignment details.
                        </template>
                    </DialogDescription>
                </DialogHeader>

                <ErrorBanner :context="FORM_CTX" class="mt-4" />

                <!-- CREATE MODE -->
                <div v-if="isCreate" class="mt-4 space-y-4">
                    <!-- Source type -->
                    <div class="grid gap-2">
                        <Label for="ta_source_type">Assignment type</Label>
                        <select
                            id="ta_source_type"
                            v-model="form.source_type"
                            data-testid="source-type-select"
                            class="h-9 w-full rounded border border-input bg-background px-3 text-sm"
                        >
                            <option value="direct">Assign training directly</option>
                            <option value="requirement">
                                Pull all trainings from a requirement
                            </option>
                        </select>
                    </div>

                    <!-- Training picker (direct) -->
                    <div v-if="form.source_type === 'direct'" class="grid gap-2">
                        <Label for="ta_training">Training</Label>
                        <select
                            id="ta_training"
                            v-model="form.training_id"
                            data-testid="training-select"
                            required
                            class="h-9 w-full rounded border border-input bg-background px-3 text-sm"
                        >
                            <option value="" disabled>Pick a training…</option>
                            <option
                                v-for="t in sortedTrainings"
                                :key="t.id"
                                :value="t.id"
                            >
                                {{ t.name }}
                            </option>
                        </select>
                        <InputError :message="fieldErrors.message('training_id')" />
                    </div>

                    <!-- Requirement picker (requirement-exploded) -->
                    <div v-else class="grid gap-2">
                        <Label for="ta_req">Requirement</Label>
                        <select
                            id="ta_req"
                            v-model="form.requirement_id"
                            data-testid="requirement-select"
                            required
                            class="h-9 w-full rounded border border-input bg-background px-3 text-sm"
                        >
                            <option value="" disabled>Pick a requirement…</option>
                            <option
                                v-for="r in sortedRequirements"
                                :key="r.id"
                                :value="r.id"
                            >
                                {{ r.name }}
                            </option>
                        </select>
                        <InputError :message="fieldErrors.message('requirement_id')" />
                    </div>
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
                        <p class="font-medium mb-1">Assigned via</p>
                        <ul class="space-y-0.5">
                            <li
                                v-for="s in target.active_sources"
                                :key="s.id"
                            >
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
                        <Button v-if="isCreate" type="submit" :disabled="submitting">
                            {{ submitting ? 'Saving…' : 'Assign' }}
                        </Button>
                    </div>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
