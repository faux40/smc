<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed, onMounted, reactive, ref, watch } from 'vue';
import AsyncState from '@/components/AsyncState.vue';
import DualListShuttle from '@/components/DualListShuttle.vue';
import ErrorBanner from '@/components/ErrorBanner.vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useFieldErrors } from '@/composables/useFieldErrors';
import { elementTimingLabel } from '@/lib/timing';
import RqmtElementFormModal from '@/pages/requirements/Partials/RqmtElementFormModal.vue';
import { page as requirementsPage } from '@/routes/requirements';
import { useErrorStore } from '@/stores/errors';
import { useRequirementsStore } from '@/stores/requirements';
import { useRqmtElementsStore } from '@/stores/rqmtElements';
import type { RqmtElementRow } from '@/stores/rqmtElements';
import { useTrainingsStore } from '@/stores/trainings';

const FORM_CTX = 'form:requirement';
const TRAINING_TYPE = 'App\\Models\\Training';

const props = defineProps<{
    requirement: {
        id: string;
        name: string;
        description: string | null;
    };
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Requirements', href: requirementsPage() }],
    },
});

const store = useRqmtElementsStore();
const requirementsStore = useRequirementsStore();
const trainings = useTrainingsStore();
const errorStore = useErrorStore();
const fieldErrors = useFieldErrors(FORM_CTX);
const page = usePage();
const authUser = computed(
    () =>
        page.props.auth.user as {
            org_id?: string;
            isOwner?: boolean;
            isSuperAdmin?: boolean;
            isAdmin?: boolean;
        } | null,
);
const canManage = computed(() =>
    Boolean(
        authUser.value?.isOwner ||
        authUser.value?.isSuperAdmin ||
        authUser.value?.isAdmin,
    ),
);

const error = ref<string | null>(null);
const loading = ref(true);

const elements = computed(() => store.listFor(props.requirement.id));

// Prefer the live store row (reflects inline edits + RequirementUpdated
// broadcasts); fall back to the server-rendered Inertia prop on first paint
// before the library has loaded.
const storeRequirement = computed(() =>
    requirementsStore.library.find((r) => r.id === props.requirement.id),
);
const requirement = computed(() => storeRequirement.value ?? props.requirement);

// ── Inline details (name + description) ────────────────────────────────────
const form = reactive({ name: '', description: '' });
const saving = ref(false);

function resetForm(): void {
    form.name = requirement.value.name;
    form.description = requirement.value.description ?? '';
}

const isDirty = computed(
    () =>
        form.name.trim() !== requirement.value.name.trim() ||
        form.description.trim() !==
            (requirement.value.description ?? '').trim(),
);

// Keep the form synced with the server copy unless the user has unsaved edits.
watch(requirement, () => {
    if (!isDirty.value) {
        resetForm();
    }
});

resetForm();

async function saveDetails(): Promise<void> {
    errorStore.clear(FORM_CTX);
    saving.value = true;

    try {
        await requirementsStore.update(props.requirement.id, {
            name: form.name,
            description: form.description.trim() === '' ? null : form.description,
        });
    } catch (e) {
        errorStore.reportFromAxios(e, FORM_CTX, {
            fallback: 'Failed to save requirement',
        });
    } finally {
        saving.value = false;
    }
}

// ── Trainings shuttle ──────────────────────────────────────────────────────
interface ShuttleItem {
    id: string;
    name: string;
    timing: string;
}

const SHUTTLE_COLUMNS = [
    { key: 'name', label: 'Training' },
    { key: 'timing', label: 'Timing' },
];

// Trainings already bound to this requirement (by module identity).
const boundTrainingIds = computed(
    () =>
        new Set(
            elements.value
                .filter((e) => e.module_type.endsWith('Training'))
                .map((e) => e.module_id),
        ),
);

// Assigned (right) — keyed by element id so unassign can delete the element.
const assignedItems = computed<ShuttleItem[]>(() =>
    elements.value.map((e) => ({
        id: e.id,
        name: e.name,
        timing: elementTimingLabel(e),
    })),
);

// Available (left) — keyed by training id; excludes already-bound trainings.
const availableItems = computed<ShuttleItem[]>(() =>
    trainings.library
        .filter((t) => !boundTrainingIds.value.has(t.id))
        .map((t) => ({
            id: t.id,
            name: t.name,
            timing: elementTimingLabel(t),
        })),
);

async function run(fn: () => Promise<unknown>): Promise<void> {
    error.value = null;

    try {
        await fn();
    } catch (e) {
        error.value =
            (e as { response?: { data?: { message?: string } } }).response?.data
                ?.message ?? (e as Error).message;
    }
}

// Add a training: create an element snapped from the training's template
// (mirrors the element form's create flow).
const assignTraining = (item: { id: string }) => {
    const t = trainings.library.find((x) => x.id === item.id);

    if (!t) {
        return;
    }

    return run(() =>
        store.create(props.requirement.id, {
            module_type: TRAINING_TYPE,
            module_id: t.id,
            name: t.name,
            description: t.description ?? null,
            initial_only: t.initial_only,
            repeating: t.repeating,
            std_freq_id: t.std_freq_id,
            as_needed: t.as_needed,
        }),
    );
};

const unassignElement = (item: { id: string }) =>
    run(() => store.destroy(item.id, props.requirement.id));

// ── Per-element timing edit (reuses the element modal) ──────────────────────
const modalOpen = ref(false);
const editing = ref<RqmtElementRow | null>(null);

const editElement = (item: { id: string }) => {
    editing.value = elements.value.find((e) => e.id === item.id) ?? null;
    modalOpen.value = true;
};

onMounted(async () => {
    if (authUser.value?.org_id) {
        store.subscribe(authUser.value.org_id);
        requirementsStore.subscribe(authUser.value.org_id);
    }

    try {
        await Promise.all([
            store.loadFor(props.requirement.id),
            requirementsStore.load(),
            trainings.load(),
        ]);
        resetForm();
    } catch (e) {
        error.value = (e as Error).message;
    } finally {
        loading.value = false;
    }
});
</script>

<template>
    <Head :title="`Requirement: ${requirement.name}`" />

    <div class="flex flex-col gap-6 p-4">
        <Link
            :href="requirementsPage()"
            class="inline-flex w-fit items-center gap-1 text-sm text-muted-foreground hover:text-foreground"
        >
            <span aria-hidden="true">&larr;</span> Back to requirements
        </Link>

        <Heading
            :title="requirement.name"
            description="A named group of trainings. Edit its details and manage which trainings it requires."
        />

        <AsyncState :loading="loading" :error="error">
            <div class="flex flex-col gap-6">
                <!-- Details -->
                <section class="rounded-md border border-border p-4">
                    <h2 class="mb-3 text-sm font-semibold">Details</h2>
                    <ErrorBanner :context="FORM_CTX" />

                    <template v-if="canManage">
                        <form
                            class="flex flex-col gap-4"
                            @submit.prevent="saveDetails"
                        >
                            <div class="grid gap-2">
                                <Label for="r_name">Name</Label>
                                <Input id="r_name" v-model="form.name" required />
                                <p
                                    v-if="fieldErrors.message('name')"
                                    class="text-xs text-destructive"
                                >
                                    {{ fieldErrors.message('name') }}
                                </p>
                            </div>
                            <div class="grid gap-2">
                                <Label for="r_desc">Description</Label>
                                <textarea
                                    id="r_desc"
                                    v-model="form.description"
                                    rows="3"
                                    class="w-full rounded border border-input bg-background p-2 text-sm"
                                ></textarea>
                            </div>
                            <div class="flex justify-end">
                                <Button
                                    type="submit"
                                    :disabled="!isDirty || saving"
                                >
                                    Save changes
                                </Button>
                            </div>
                        </form>
                    </template>

                    <dl v-else class="grid gap-2 text-sm">
                        <div>
                            <dt class="text-xs text-muted-foreground">Name</dt>
                            <dd>{{ requirement.name }}</dd>
                        </div>
                        <div v-if="requirement.description">
                            <dt class="text-xs text-muted-foreground">
                                Description
                            </dt>
                            <dd class="whitespace-pre-line">
                                {{ requirement.description }}
                            </dd>
                        </div>
                    </dl>
                </section>

                <!-- Trainings -->
                <section class="space-y-3 rounded-md border border-border p-4">
                    <div>
                        <h2 class="text-sm font-semibold">Trainings</h2>
                        <p class="text-xs text-muted-foreground">
                            Add a training to require it; timing is copied from
                            the training's template — fine-tune it with "Edit".
                        </p>
                    </div>

                    <DualListShuttle
                        :assigned="assignedItems"
                        :available="availableItems"
                        :columns="SHUTTLE_COLUMNS"
                        assigned-title="On this requirement"
                        available-title="Available trainings"
                        search-placeholder="Search trainings…"
                        always-expanded
                        :disabled="!canManage"
                        @assign="assignTraining"
                        @unassign="unassignElement"
                    >
                        <template #extra-header>Edit</template>
                        <template #extra="{ item }">
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                class="h-7 text-xs"
                                @click="editElement(item)"
                            >
                                Edit
                            </Button>
                        </template>
                    </DualListShuttle>
                </section>
            </div>
        </AsyncState>

        <RqmtElementFormModal
            v-model:open="modalOpen"
            mode="edit"
            :requirement-id="props.requirement.id"
            :target="editing"
        />
    </div>
</template>
