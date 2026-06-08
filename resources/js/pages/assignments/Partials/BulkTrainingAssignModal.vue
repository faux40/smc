<script setup lang="ts">
/*
 * Bulk training assign modal — assign a training (or all trainings from a
 * requirement) to a pre-selected list of users in one request.
 *
 * Props:
 *   open       — dialog visibility (v-model:open)
 *   userIds    — array of user IDs to assign to (maintained by parent)
 *
 * Emits:
 *   update:open  — close request
 *   applied      — after a successful bulk assign; parent refreshes data
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
import { realtimeTabId } from '@/echo';
import { useErrorStore } from '@/stores/errors';
import { useRequirementsStore } from '@/stores/requirements';
import { useTrainingsStore } from '@/stores/trainings';
import axios from 'axios';

const FORM_CTX = 'form:bulk-training-assign';

type SourceType = 'direct' | 'requirement';

const props = defineProps<{
    open: boolean;
    userIds: string[];
}>();

const emit = defineEmits<{
    (e: 'update:open', v: boolean): void;
    (e: 'applied', result: { created_count: number; skipped_count: number }): void;
}>();

const trainings = useTrainingsStore();
const requirements = useRequirementsStore();
const errorStore = useErrorStore();
const fieldErrors = useFieldErrors(FORM_CTX);

const form = reactive({
    source_type: 'direct' as SourceType,
    training_id: '',
    requirement_id: '',
});
const submitting = ref(false);

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
    (open) => {
        if (!open) return;
        errorStore.clear(FORM_CTX);
        form.source_type = 'direct';
        form.training_id = '';
        form.requirement_id = '';
    },
    { immediate: true },
);

function defaultHeaders(): Record<string, string> {
    const csrf = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content;
    return {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'X-Origin-Tab': realtimeTabId(),
        ...(csrf ? { 'X-CSRF-TOKEN': csrf } : {}),
    };
}

const submit = async () => {
    submitting.value = true;
    errorStore.clear(FORM_CTX);

    try {
        const payload: Record<string, unknown> = {
            user_ids: props.userIds,
            source_type: form.source_type,
        };
        if (form.source_type === 'direct') {
            payload.training_id = form.training_id;
        } else {
            payload.requirement_id = form.requirement_id;
        }

        const { data } = await axios.post<{ created_count: number; skipped_count: number }>(
            '/api/bulk-training-assignments',
            payload,
            { headers: defaultHeaders() },
        );

        emit('applied', data);
        emit('update:open', false);
    } catch (e) {
        errorStore.reportFromAxios(e, FORM_CTX, {
            fallback: 'Bulk assignment failed',
        });
    } finally {
        submitting.value = false;
    }
};
</script>

<template>
    <Dialog :open="open" @update:open="(v) => emit('update:open', v)">
        <DialogContent class="sm:max-w-md">
            <form @submit.prevent="submit">
                <DialogHeader>
                    <DialogTitle>Assign training to {{ userIds.length }} users</DialogTitle>
                    <DialogDescription>
                        Pick a training or requirement to assign to all selected users.
                    </DialogDescription>
                </DialogHeader>

                <ErrorBanner :context="FORM_CTX" class="mt-4" />

                <div class="mt-4 space-y-4">
                    <div class="grid gap-2">
                        <Label for="bulk_source_type">Assignment type</Label>
                        <select
                            id="bulk_source_type"
                            v-model="form.source_type"
                            data-testid="source-type-select"
                            class="h-9 w-full rounded border border-input bg-background px-3 text-sm"
                        >
                            <option value="direct">Assign training directly</option>
                            <option value="requirement">Pull all trainings from a requirement</option>
                        </select>
                    </div>

                    <div v-if="form.source_type === 'direct'" class="grid gap-2">
                        <Label for="bulk_training">Training</Label>
                        <select
                            id="bulk_training"
                            v-model="form.training_id"
                            data-testid="training-select"
                            required
                            class="h-9 w-full rounded border border-input bg-background px-3 text-sm"
                        >
                            <option value="" disabled>Pick a training…</option>
                            <option v-for="t in sortedTrainings" :key="t.id" :value="t.id">
                                {{ t.name }}
                            </option>
                        </select>
                        <InputError :message="fieldErrors.message('training_id')" />
                    </div>

                    <div v-else class="grid gap-2">
                        <Label for="bulk_req">Requirement</Label>
                        <select
                            id="bulk_req"
                            v-model="form.requirement_id"
                            data-testid="requirement-select"
                            required
                            class="h-9 w-full rounded border border-input bg-background px-3 text-sm"
                        >
                            <option value="" disabled>Pick a requirement…</option>
                            <option v-for="r in sortedRequirements" :key="r.id" :value="r.id">
                                {{ r.name }}
                            </option>
                        </select>
                        <InputError :message="fieldErrors.message('requirement_id')" />
                    </div>
                </div>

                <DialogFooter class="mt-6">
                    <Button type="button" variant="outline" @click="emit('update:open', false)">
                        Cancel
                    </Button>
                    <Button type="submit" :disabled="submitting || userIds.length === 0">
                        {{ submitting ? 'Assigning…' : `Assign to ${userIds.length} users` }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
