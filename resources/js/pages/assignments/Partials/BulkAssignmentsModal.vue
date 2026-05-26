<script setup lang="ts">
/*
 * Bulk assign / de-assign requirements to the users selected on the
 * assignments page. Pairs = selectedUsers × pickedRequirements.
 *
 *  - assign   → POST /api/bulk-assignments        (Manager+, dedups existing)
 *  - deassign → POST /api/bulk-assignments/detach (Admin+, mode delete|end)
 *
 * The host owns the selected user ids + reloads the assignments store on
 * success (the acting tab doesn't get its own broadcasts).
 */
import axios from 'axios';
import { computed, ref, watch } from 'vue';
import ErrorBanner from '@/components/ErrorBanner.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
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
import { realtimeTabId } from '@/echo';
import { useErrorStore } from '@/stores/errors';
import { useRequirementsStore } from '@/stores/requirements';

type Mode = 'assign' | 'deassign';
type DeassignAction = 'delete' | 'end';

const props = defineProps<{
    open: boolean;
    mode: Mode;
    userIds: string[];
}>();

const emit = defineEmits<{
    (e: 'update:open', v: boolean): void;
    (e: 'applied'): void;
}>();

const FORM_CTX = 'form:bulk-assignments';

const requirements = useRequirementsStore();
const errorStore = useErrorStore();

const selectedReqIds = ref<string[]>([]);
const startDate = ref(new Date().toISOString().slice(0, 10));
const endDate = ref('');
const action = ref<DeassignAction>('delete');
const submitting = ref(false);
const result = ref<string | null>(null);

const isAssign = computed(() => props.mode === 'assign');
const title = computed(() =>
    isAssign.value ? 'Assign requirements' : 'De-assign requirements',
);
const userCount = computed(() => props.userIds.length);

const sortedRequirements = computed(() =>
    [...requirements.library].sort((a, b) => a.name.localeCompare(b.name)),
);

function isChecked(id: string): boolean {
    return selectedReqIds.value.includes(id);
}

function toggleReq(id: string): void {
    selectedReqIds.value = isChecked(id)
        ? selectedReqIds.value.filter((x) => x !== id)
        : [...selectedReqIds.value, id];
}

watch(
    () => props.open,
    (open) => {
        if (!open) {
            return;
        }

        errorStore.clear(FORM_CTX);
        result.value = null;
        selectedReqIds.value = [];
        startDate.value = new Date().toISOString().slice(0, 10);
        endDate.value = '';
        action.value = 'delete';
        requirements.load().catch(() => {
            /* surfaced via store */
        });
    },
);

const submit = async () => {
    if (selectedReqIds.value.length === 0) {
        return;
    }

    submitting.value = true;
    errorStore.clear(FORM_CTX);

    const pairs = props.userIds.flatMap((user_id) =>
        selectedReqIds.value.map((requirement_id) => ({
            user_id,
            requirement_id,
        })),
    );

    try {
        if (isAssign.value) {
            const { data } = await axios.post<{
                created_count: number;
                skipped_count: number;
            }>(
                '/api/bulk-assignments',
                {
                    pairs,
                    start_date: startDate.value,
                    end_date: endDate.value === '' ? null : endDate.value,
                },
                { headers: defaultHeaders() },
            );
            result.value = `Created ${data.created_count}, skipped ${data.skipped_count} (already assigned).`;
        } else {
            const { data } = await axios.post<{ affected_count: number }>(
                '/api/bulk-assignments/detach',
                { pairs, mode: action.value },
                { headers: defaultHeaders() },
            );
            const verb = action.value === 'delete' ? 'Removed' : 'Ended';

            result.value = `${verb} ${data.affected_count} assignment(s).`;
        }

        emit('applied');
    } catch (e) {
        errorStore.reportFromAxios(e, FORM_CTX, {
            fallback: 'Bulk action failed',
        });
    } finally {
        submitting.value = false;
    }
};

function defaultHeaders(): Record<string, string> {
    const csrf = document.querySelector<HTMLMetaElement>(
        'meta[name="csrf-token"]',
    )?.content;

    return {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'X-Origin-Tab': realtimeTabId(),
        ...(csrf ? { 'X-CSRF-TOKEN': csrf } : {}),
    };
}
</script>

<template>
    <Dialog :open="open" @update:open="(v) => emit('update:open', v)">
        <DialogContent class="sm:max-w-lg">
            <DialogHeader>
                <DialogTitle>{{ title }}</DialogTitle>
                <DialogDescription>
                    {{ isAssign ? 'Assign to' : 'De-assign from' }}
                    {{ userCount }} selected user{{
                        userCount === 1 ? '' : 's'
                    }}.
                </DialogDescription>
            </DialogHeader>

            <ErrorBanner :context="FORM_CTX" />

            <div v-if="result" class="space-y-4">
                <p
                    class="rounded bg-muted/50 p-3 text-sm"
                    data-testid="bulk-result"
                >
                    {{ result }}
                </p>
                <DialogFooter>
                    <Button type="button" @click="emit('update:open', false)">
                        Done
                    </Button>
                </DialogFooter>
            </div>

            <form v-else @submit.prevent="submit" class="space-y-4">
                <div class="grid gap-2">
                    <Label class="text-xs">Requirements</Label>
                    <div
                        class="max-h-56 overflow-auto rounded-md border border-border p-1"
                    >
                        <p
                            v-if="sortedRequirements.length === 0"
                            class="px-2 py-1.5 text-xs text-muted-foreground italic"
                        >
                            No requirements yet.
                        </p>
                        <button
                            v-for="r in sortedRequirements"
                            :key="r.id"
                            type="button"
                            class="flex w-full items-center gap-2 rounded px-2 py-1.5 text-left text-sm hover:bg-accent"
                            @click="toggleReq(r.id)"
                        >
                            <Checkbox
                                :model-value="isChecked(r.id)"
                                class="pointer-events-none"
                            />
                            <span class="truncate">{{ r.name }}</span>
                        </button>
                    </div>
                </div>

                <div v-if="isAssign" class="grid grid-cols-2 gap-3">
                    <div class="grid gap-2">
                        <Label for="bulk_start">Start date</Label>
                        <Input
                            id="bulk_start"
                            v-model="startDate"
                            type="date"
                            required
                        />
                    </div>
                    <div class="grid gap-2">
                        <Label for="bulk_end">End date (optional)</Label>
                        <Input id="bulk_end" v-model="endDate" type="date" />
                    </div>
                </div>

                <div v-else class="grid gap-2">
                    <Label class="text-xs">Action</Label>
                    <label class="flex items-center gap-2 text-sm">
                        <input v-model="action" type="radio" value="delete" />
                        Remove (soft-delete the assignment)
                    </label>
                    <label class="flex items-center gap-2 text-sm">
                        <input v-model="action" type="radio" value="end" />
                        End (set end date to today, keep as history)
                    </label>
                </div>

                <DialogFooter>
                    <Button
                        type="button"
                        variant="outline"
                        @click="emit('update:open', false)"
                    >
                        Cancel
                    </Button>
                    <Button
                        type="submit"
                        :variant="
                            !isAssign && action === 'delete'
                                ? 'destructive'
                                : 'default'
                        "
                        :disabled="submitting || selectedReqIds.length === 0"
                    >
                        {{
                            submitting
                                ? 'Working…'
                                : isAssign
                                  ? `Assign to ${userCount}`
                                  : `Apply to ${userCount}`
                        }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
