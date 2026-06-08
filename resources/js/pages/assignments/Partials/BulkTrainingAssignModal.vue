<script setup lang="ts">
/*
 * Bulk training assign modal — assign a training or requirement to a
 * pre-selected list of users in one request.
 *
 * The user picks from a single combined picker (requirements first, then
 * trainings). source_type is inferred from the selection prefix and never
 * exposed as a concept in the UI.
 */
import { reactive, ref, watch } from 'vue';
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
import { realtimeTabId } from '@/echo';
import { useErrorStore } from '@/stores/errors';
import axios from 'axios';

const FORM_CTX = 'form:bulk-training-assign';

const props = defineProps<{
    open: boolean;
    userIds: string[];
}>();

const emit = defineEmits<{
    (e: 'update:open', v: boolean): void;
    (e: 'applied', result: { created_count: number; skipped_count: number }): void;
}>();

const errorStore = useErrorStore();

const form = reactive({ selectedItem: '' });
const submitting = ref(false);

watch(
    () => props.open,
    (open) => {
        if (!open) return;
        errorStore.clear(FORM_CTX);
        form.selectedItem = '';
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
        const idx = form.selectedItem.indexOf(':');
        const type = form.selectedItem.slice(0, idx);
        const id = form.selectedItem.slice(idx + 1);

        const payload: Record<string, unknown> = { user_ids: props.userIds };

        if (type === 'requirement') {
            payload.source_type = 'requirement';
            payload.requirement_id = id;
        } else {
            payload.source_type = 'direct';
            payload.training_id = id;
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
                        Pick a requirement or individual training to assign to all selected users.
                    </DialogDescription>
                </DialogHeader>

                <ErrorBanner :context="FORM_CTX" class="mt-4" />

                <div class="mt-4 grid gap-2">
                    <Label for="bulk_item">Training or requirement</Label>
                    <TrainingOrRequirementPicker
                        id="bulk_item"
                        v-model="form.selectedItem"
                        :disabled="submitting"
                    />
                </div>

                <DialogFooter class="mt-6">
                    <Button type="button" variant="outline" @click="emit('update:open', false)">
                        Cancel
                    </Button>
                    <Button
                        type="submit"
                        :disabled="submitting || userIds.length === 0 || !form.selectedItem"
                    >
                        {{ submitting ? 'Assigning…' : `Assign to ${userIds.length} users` }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
