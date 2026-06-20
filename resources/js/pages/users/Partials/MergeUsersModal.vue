<script setup lang="ts">
/*
 * Combine-users (de-duplication) tool.
 *
 * Two steps in one dialog:
 *   1. Pick the survivor (kept) and the duplicate (folded in + removed).
 *   2. Review the side-by-side diff: for each conflicting field choose which
 *      value the survivor keeps; the other is stashed in the survivor's notes.
 *      A summary shows how many records (completions, assignments, …) move.
 *
 * The merge itself runs server-side in one transaction (UserMerge); this
 * component only orchestrates the choices and calls the store action, which
 * patches the local cache on success (peer tabs reconcile via broadcasts).
 */
import { computed, reactive, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import ErrorBanner from '@/components/ErrorBanner.vue';
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
import { useErrorStore } from '@/stores/errors';
import { useUsersStore } from '@/stores/users';
import type { MergePreview, UserRow } from '@/stores/users';

const FORM_CTX = 'form:user-merge';

const props = defineProps<{ open: boolean }>();
const emit = defineEmits<{
    (e: 'update:open', v: boolean): void;
    (e: 'merged'): void;
}>();

const store = useUsersStore();
const errorStore = useErrorStore();

const step = ref<'pick' | 'review'>('pick');
const survivorId = ref<string | null>(null);
const duplicateId = ref<string | null>(null);
const survivorSearch = ref('');
const duplicateSearch = ref('');
const preview = ref<MergePreview | null>(null);
const choices = reactive<Record<string, 'survivor' | 'duplicate'>>({});
const loadingPreview = ref(false);
const submitting = ref(false);

const COUNT_LABELS: Record<string, string> = {
    completions: 'Completions',
    training_assignments: 'Training assignments',
    class_enrollments: 'Class enrollments',
    comments_authored: 'Comments authored',
    attachments_uploaded: 'Attachments uploaded',
    reports: 'Direct reports',
};

watch(
    () => props.open,
    (open) => {
        if (!open) {
            return;
        }

        // Fresh state each open + make sure the roster is loaded so the
        // pickers have users to choose from.
        step.value = 'pick';
        survivorId.value = null;
        duplicateId.value = null;
        survivorSearch.value = '';
        duplicateSearch.value = '';
        preview.value = null;
        errorStore.clear(FORM_CTX);
        void store.loadPicker();
    },
);

function matches(u: UserRow, q: string): boolean {
    const needle = q.trim().toLowerCase();

    if (needle === '') {
        return true;
    }

    return [u.name, u.email, u.employee_number]
        .filter((s): s is string => Boolean(s))
        .some((s) => s.toLowerCase().includes(needle));
}

const survivorOptions = computed(() =>
    store.users.filter((u) => matches(u, survivorSearch.value)),
);
const duplicateOptions = computed(() =>
    store.users.filter(
        (u) => u.id !== survivorId.value && matches(u, duplicateSearch.value),
    ),
);

const canPreview = computed(
    () =>
        survivorId.value !== null &&
        duplicateId.value !== null &&
        survivorId.value !== duplicateId.value,
);

// Only differing fields need a decision; matching fields carry through.
const conflicts = computed(() =>
    (preview.value?.fields ?? []).filter((f) => f.differs),
);

const counts = computed(() =>
    Object.entries(preview.value?.counts ?? {})
        .filter(([, n]) => n > 0)
        .map(([key, n]) => ({ label: COUNT_LABELS[key] ?? key, n })),
);

const roleDiffers = computed(
    () =>
        preview.value !== null &&
        preview.value.role.duplicate !== null &&
        preview.value.role.survivor !== preview.value.role.duplicate,
);

async function loadPreview(): Promise<void> {
    if (!canPreview.value) {
        return;
    }

    loadingPreview.value = true;
    errorStore.clear(FORM_CTX);

    try {
        const data = await store.mergePreview(
            survivorId.value!,
            duplicateId.value!,
        );
        preview.value = data;

        // Seed each conflicting field with the server's default choice.
        for (const f of data.fields) {
            if (f.differs) {
                choices[f.key] = f.default;
            }
        }

        step.value = 'review';
    } catch (e) {
        errorStore.reportFromAxios(e, FORM_CTX, {
            fallback: 'Failed to build the merge preview',
        });
    } finally {
        loadingPreview.value = false;
    }
}

async function confirm(): Promise<void> {
    if (preview.value === null) {
        return;
    }

    submitting.value = true;
    errorStore.clear(FORM_CTX);

    try {
        await store.merge({
            survivor_id: survivorId.value!,
            duplicate_id: duplicateId.value!,
            fields: { ...choices },
        });
        toast.success('Users merged.');
        emit('merged');
        emit('update:open', false);
    } catch (e) {
        errorStore.reportFromAxios(e, FORM_CTX, {
            fallback: 'Failed to merge the users',
        });
    } finally {
        submitting.value = false;
    }
}
</script>

<template>
    <Dialog :open="open" @update:open="(v) => emit('update:open', v)">
        <DialogContent class="max-h-[85vh] overflow-y-auto sm:max-w-3xl">
            <DialogHeader>
                <DialogTitle>Merge duplicate users</DialogTitle>
                <DialogDescription>
                    Fold a duplicate record into the one you want to keep. The
                    survivor inherits all of the duplicate's training records,
                    assignments, and history; discarded profile values are
                    saved to the survivor's notes. This can't be undone.
                </DialogDescription>
            </DialogHeader>

            <ErrorBanner :context="FORM_CTX" />

            <!-- Step 1: pick the two records -->
            <div v-if="step === 'pick'" class="grid gap-4 sm:grid-cols-2">
                <div class="flex flex-col gap-2">
                    <Label>Keep (survivor)</Label>
                    <Input
                        v-model="survivorSearch"
                        placeholder="Search name / email / emp #"
                        data-testid="survivor-search"
                    />
                    <ul
                        class="max-h-56 divide-y divide-border overflow-y-auto rounded-md border border-border"
                    >
                        <li
                            v-for="u in survivorOptions"
                            :key="u.id"
                            class="cursor-pointer px-3 py-2 text-sm hover:bg-muted"
                            :class="{
                                'bg-primary/10 font-medium':
                                    survivorId === u.id,
                            }"
                            @click="survivorId = u.id"
                        >
                            <div>{{ u.name }}</div>
                            <div class="text-xs text-muted-foreground">
                                {{ u.email ?? 'no email' }}
                                <span v-if="u.employee_number">
                                    · #{{ u.employee_number }}</span
                                >
                            </div>
                        </li>
                    </ul>
                </div>

                <div class="flex flex-col gap-2">
                    <Label>Remove (duplicate)</Label>
                    <Input
                        v-model="duplicateSearch"
                        placeholder="Search name / email / emp #"
                        data-testid="duplicate-search"
                    />
                    <ul
                        class="max-h-56 divide-y divide-border overflow-y-auto rounded-md border border-border"
                    >
                        <li
                            v-for="u in duplicateOptions"
                            :key="u.id"
                            class="cursor-pointer px-3 py-2 text-sm hover:bg-muted"
                            :class="{
                                'bg-destructive/10 font-medium':
                                    duplicateId === u.id,
                            }"
                            @click="duplicateId = u.id"
                        >
                            <div>{{ u.name }}</div>
                            <div class="text-xs text-muted-foreground">
                                {{ u.email ?? 'no email' }}
                                <span v-if="u.employee_number">
                                    · #{{ u.employee_number }}</span
                                >
                            </div>
                        </li>
                    </ul>
                </div>
            </div>

            <!-- Step 2: resolve conflicts + confirm -->
            <div v-else-if="preview" class="flex flex-col gap-4">
                <p class="text-sm">
                    Combining
                    <span class="font-semibold text-destructive">{{
                        preview.duplicate.name
                    }}</span>
                    into
                    <span class="font-semibold text-primary">{{
                        preview.survivor.name
                    }}</span
                    >.
                </p>

                <div v-if="conflicts.length" class="flex flex-col gap-2">
                    <h3 class="text-sm font-semibold">Resolve conflicts</h3>
                    <p class="text-xs text-muted-foreground">
                        Pick the value to keep. The other is appended to notes.
                    </p>
                    <div
                        v-for="f in conflicts"
                        :key="f.key"
                        class="grid grid-cols-[8rem_1fr] items-center gap-2 rounded-md border border-border p-2 text-sm"
                        :data-testid="`conflict-${f.key}`"
                    >
                        <span class="text-xs text-muted-foreground">{{
                            f.label
                        }}</span>
                        <div class="flex flex-wrap gap-2">
                            <label
                                class="flex cursor-pointer items-center gap-1 rounded border border-border px-2 py-1"
                                :class="{
                                    'border-primary bg-primary/10':
                                        choices[f.key] === 'survivor',
                                }"
                            >
                                <input
                                    type="radio"
                                    :name="`merge-${f.key}`"
                                    value="survivor"
                                    :checked="choices[f.key] === 'survivor'"
                                    @change="choices[f.key] = 'survivor'"
                                />
                                <span>{{ f.survivor ?? '—' }}</span>
                            </label>
                            <label
                                class="flex cursor-pointer items-center gap-1 rounded border border-border px-2 py-1"
                                :class="{
                                    'border-primary bg-primary/10':
                                        choices[f.key] === 'duplicate',
                                }"
                            >
                                <input
                                    type="radio"
                                    :name="`merge-${f.key}`"
                                    value="duplicate"
                                    :checked="choices[f.key] === 'duplicate'"
                                    @change="choices[f.key] = 'duplicate'"
                                />
                                <span>{{ f.duplicate ?? '—' }}</span>
                            </label>
                        </div>
                    </div>
                </div>
                <p v-else class="text-sm text-muted-foreground">
                    No conflicting profile fields.
                </p>

                <div
                    v-if="roleDiffers"
                    class="rounded-md border border-amber-300 bg-amber-50 p-2 text-xs text-amber-800 dark:border-amber-700 dark:bg-amber-950/40 dark:text-amber-200"
                >
                    The duplicate's role
                    (<strong>{{ preview.role.duplicate }}</strong
                    >) is not transferred — the survivor keeps
                    <strong>{{ preview.role.survivor ?? 'their role' }}</strong
                    >. The discarded role is noted.
                </div>

                <div v-if="counts.length" class="flex flex-col gap-1">
                    <h3 class="text-sm font-semibold">Records moving</h3>
                    <ul class="text-sm text-muted-foreground">
                        <li v-for="c in counts" :key="c.label">
                            {{ c.n }} {{ c.label }}
                        </li>
                    </ul>
                </div>
            </div>

            <DialogFooter>
                <template v-if="step === 'pick'">
                    <Button
                        type="button"
                        variant="outline"
                        @click="emit('update:open', false)"
                    >
                        Cancel
                    </Button>
                    <Button
                        type="button"
                        :disabled="!canPreview || loadingPreview"
                        data-testid="merge-preview-btn"
                        @click="loadPreview"
                    >
                        Review merge
                    </Button>
                </template>
                <template v-else>
                    <Button
                        type="button"
                        variant="outline"
                        @click="step = 'pick'"
                    >
                        Back
                    </Button>
                    <Button
                        type="button"
                        variant="destructive"
                        :disabled="submitting"
                        data-testid="merge-confirm-btn"
                        @click="confirm"
                    >
                        Merge duplicate users
                    </Button>
                </template>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
