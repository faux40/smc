<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, onMounted, ref, watch } from 'vue';
import AsyncState from '@/components/AsyncState.vue';
import AttachmentsList from '@/components/AttachmentsList.vue';
import ClassFieldset from '@/components/ClassFieldset.vue';
import ErrorBanner from '@/components/ErrorBanner.vue';
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
import { useClassForm } from '@/composables/useClassForm';
import { realtimeTabId } from '@/echo';
import ClassCompleteModal from '@/pages/classes/Partials/ClassCompleteModal.vue';
import ManageRosterModal from '@/pages/classes/Partials/ManageRosterModal.vue';
import type { PickerUser } from '@/pages/classes/Partials/ManageRosterModal.vue';
import ManageTopicsModal from '@/pages/classes/Partials/ManageTopicsModal.vue';
import { page as classesPage } from '@/routes/classes';
import { useClassesStore } from '@/stores/classes';
import { useErrorStore } from '@/stores/errors';
import { useTrainingsStore } from '@/stores/trainings';

const props = defineProps<{ classId: string }>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Classes', href: classesPage() }],
    },
});

const FORM_CTX = 'form:class';

const store = useClassesStore();
const trainings = useTrainingsStore();
const errorStore = useErrorStore();
const page = usePage();
const orgId = computed(
    () => (page.props.auth.user as { org_id?: string } | null)?.org_id ?? null,
);

const loading = ref(true);
const error = ref<string | null>(null);
const detail = computed(() => store.detail[props.classId] ?? null);

const userPicker = ref<PickerUser[]>([]);
const completeOpen = ref(false);
const topicsOpen = ref(false);
const rosterOpen = ref(false);
const reopenOpen = ref(false);
const reopening = ref(false);
// Dedicated context: the main form's ErrorBanner only renders while the class
// is editable (scheduled), so a reopen failure on a completed class would have
// nowhere to show — surface it inside the reopen dialog instead.
const REOPEN_CTX = 'form:class-reopen';

// Inline edit of the class's core fields (scheduled, editable classes).
const { form, setFrom, validate, payload } = useClassForm(FORM_CTX);
const saving = ref(false);

const canEditDetails = computed(
    () => detail.value?.can_edit === true && detail.value?.status === 'scheduled',
);

const canComplete = computed(
    () =>
        canEditDetails.value &&
        (detail.value?.enrollments.length ?? 0) > 0 &&
        (detail.value?.trainings.length ?? 0) > 0,
);

const canReopen = computed(
    () => detail.value?.can_edit === true && detail.value?.status === 'completed',
);

async function reopen(): Promise<void> {
    reopening.value = true;
    errorStore.clear(REOPEN_CTX);

    try {
        await store.reopen(props.classId);
        reopenOpen.value = false;
    } catch (e) {
        errorStore.reportFromAxios(e, REOPEN_CTX, {
            fallback: 'Failed to re-open the class.',
        });
    } finally {
        reopening.value = false;
    }
}

const isDirty = computed(() => {
    const d = detail.value;

    if (!d) {
        return false;
    }

    const f = form.value;
    const text = (v: string | null | undefined) => (v ?? '').toString().trim();

    return (
        text(f.name) !== text(d.name) ||
        text(f.scheduled_date) !== text(d.scheduled_date) ||
        text(f.start_time) !== text(d.start_time) ||
        text(f.end_time) !== text(d.end_time) ||
        text(f.location) !== text(d.location) ||
        text(f.address) !== text(d.address) ||
        text(f.instructor) !== text(d.instructor) ||
        f.show_signature !== d.show_signature ||
        text(f.notes) !== text(d.notes)
    );
});

// Keep the form in sync with the server copy unless the user has unsaved edits.
watch(detail, (d) => {
    if (d && !isDirty.value) {
        setFrom(d);
    }
});

async function saveDetails(): Promise<void> {
    errorStore.clear(FORM_CTX);

    if (!validate()) {
        return;
    }

    saving.value = true;

    try {
        await store.update(props.classId, payload());
    } catch (e) {
        errorStore.reportFromAxios(e, FORM_CTX, {
            fallback: 'Failed to save the class.',
        });
    } finally {
        saving.value = false;
    }
}

function headers(): Record<string, string> {
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

onMounted(async () => {
    if (orgId.value) {
        store.subscribe(orgId.value);
    }

    try {
        await Promise.all([
            store.loadDetail(props.classId),
            trainings.load(),
            loadUsers(),
        ]);
        setFrom(detail.value);
    } catch (e) {
        error.value = (e as Error).message;
    } finally {
        loading.value = false;
    }
});

async function loadUsers(): Promise<void> {
    const { data } = await axios.get<PickerUser[]>('/api/users', {
        headers: headers(),
    });
    userPicker.value = data;
}

const hoursLabel = (h: string | null) => `${Number(h ?? 0)}h`;

const totalHoursLabel = computed(
    () => `${Number(detail.value?.total_hours ?? 0).toFixed(1)} hours`,
);
</script>

<template>
    <Head :title="detail?.name ?? 'Class'" />

    <div class="flex flex-col gap-6 p-4">
        <AsyncState :loading="loading" :error="error">
            <template v-if="detail">
                <div class="flex items-center justify-end gap-2">
                    <Badge
                        :variant="
                            detail.status === 'completed'
                                ? 'secondary'
                                : 'default'
                        "
                    >
                        {{ detail.status }}
                    </Badge>
                    <Button v-if="canComplete" @click="completeOpen = true">
                        Complete class
                    </Button>
                    <Button
                        v-if="canReopen"
                        variant="outline"
                        @click="reopenOpen = true"
                    >
                        Re-open
                    </Button>
                </div>

                <div class="grid gap-6 lg:grid-cols-3">
                    <!-- Main column: details (name → topics → rest) + documents -->
                    <div class="space-y-6 lg:col-span-2">
                        <section class="rounded-md border border-border p-4">
                            <!-- Editable: one form; topics sit under the name -->
                            <template v-if="canEditDetails">
                                <ErrorBanner :context="FORM_CTX" />
                                <form @submit.prevent="saveDetails" novalidate>
                                    <ClassFieldset
                                        v-model="form"
                                        :context="FORM_CTX"
                                        id-prefix="edit"
                                        :class-hours="detail.total_hours"
                                    >
                                        <template #after-name>
                                            <div
                                                class="rounded-md border border-border bg-muted/20 p-3"
                                            >
                                                <div class="mb-1 flex items-center justify-between">
                                                    <h3 class="text-sm font-semibold">
                                                        Included training courses
                                                    </h3>
                                                    <Button
                                                        type="button"
                                                        variant="outline"
                                                        size="sm"
                                                        @click="topicsOpen = true"
                                                    >
                                                        Manage
                                                    </Button>
                                                </div>
                                                <ul
                                                    v-if="detail.trainings.length"
                                                    class="text-sm"
                                                >
                                                    <li
                                                        v-for="t in detail.trainings"
                                                        :key="t.id"
                                                    >
                                                        {{ t.training_name }}
                                                        <span class="text-muted-foreground">
                                                            ({{ hoursLabel(t.hours) }})
                                                        </span>
                                                    </li>
                                                </ul>
                                                <p
                                                    v-else
                                                    class="text-sm text-muted-foreground"
                                                >
                                                    No courses yet. Use “Manage”.
                                                </p>
                                            </div>
                                        </template>
                                    </ClassFieldset>
                                    <div class="mt-4 flex justify-end">
                                        <Button
                                            type="submit"
                                            :disabled="!isDirty || saving"
                                        >
                                            Save changes
                                        </Button>
                                    </div>
                                </form>
                            </template>

                            <!-- Completed (read-only) -->
                            <template v-else>
                                <h2 class="text-lg font-semibold">
                                    {{ detail.name }}
                                </h2>
                                <div
                                    class="mt-3 rounded-md border border-border bg-muted/20 p-3"
                                >
                                    <h3 class="mb-1 text-sm font-semibold">
                                        Included training courses
                                    </h3>
                                    <ul
                                        v-if="detail.trainings.length"
                                        class="text-sm"
                                    >
                                        <li
                                            v-for="t in detail.trainings"
                                            :key="t.id"
                                        >
                                            {{ t.training_name }}
                                            <span class="text-muted-foreground">
                                                ({{ hoursLabel(t.hours) }})
                                            </span>
                                        </li>
                                    </ul>
                                    <p
                                        v-else
                                        class="text-sm text-muted-foreground"
                                    >
                                        No courses.
                                    </p>
                                </div>
                                <dl
                                    class="mt-3 grid grid-cols-2 gap-x-6 gap-y-2 text-sm"
                                >
                                    <div>
                                        <dt class="text-xs text-muted-foreground">
                                            Scheduled date
                                        </dt>
                                        <dd>{{ detail.scheduled_date || '—' }}</dd>
                                    </div>
                                    <div>
                                        <dt class="text-xs text-muted-foreground">
                                            Class Hours
                                        </dt>
                                        <dd>{{ totalHoursLabel }}</dd>
                                    </div>
                                    <div>
                                        <dt class="text-xs text-muted-foreground">
                                            Completed
                                        </dt>
                                        <dd>{{ detail.completion_date || '—' }}</dd>
                                    </div>
                                    <div>
                                        <dt class="text-xs text-muted-foreground">
                                            Location
                                        </dt>
                                        <dd>{{ detail.location || '—' }}</dd>
                                    </div>
                                    <div>
                                        <dt class="text-xs text-muted-foreground">
                                            Instructor
                                        </dt>
                                        <dd>{{ detail.instructor || '—' }}</dd>
                                    </div>
                                    <div v-if="detail.notes" class="col-span-2">
                                        <dt class="text-xs text-muted-foreground">
                                            Notes
                                        </dt>
                                        <dd class="whitespace-pre-line">
                                            {{ detail.notes }}
                                        </dd>
                                    </div>
                                </dl>
                            </template>
                        </section>

                        <!-- Documents -->
                        <section class="space-y-2">
                            <h2 class="text-sm font-semibold">Documents</h2>
                            <div class="flex flex-wrap gap-2">
                                <Button
                                    as="a"
                                    variant="outline"
                                    :href="`/api/classes/${props.classId}/sign-in-sheet`"
                                    target="_blank"
                                >
                                    Sign-in sheet
                                </Button>
                                <Button
                                    v-if="detail.status === 'completed'"
                                    as="a"
                                    variant="outline"
                                    :href="`/api/classes/${props.classId}/certificates`"
                                    target="_blank"
                                >
                                    Certificates
                                </Button>
                                <Button
                                    v-if="detail.status === 'completed'"
                                    as="a"
                                    variant="outline"
                                    :href="`/api/classes/${props.classId}/summary`"
                                    target="_blank"
                                >
                                    Class summary
                                </Button>
                            </div>
                            <p
                                v-if="detail.status !== 'completed'"
                                class="text-xs text-muted-foreground"
                            >
                                Certificates and the class summary are available
                                once the class is completed.
                            </p>

                            <!-- Uploaded files (distinct from the generated PDFs above) -->
                            <div class="space-y-2 border-t border-border pt-4">
                                <h3 class="text-xs font-semibold text-muted-foreground">
                                    Uploaded files
                                </h3>
                                <AttachmentsList
                                    morphable-type="App\Models\TrainingClass"
                                    :morphable-id="props.classId"
                                />
                            </div>
                        </section>
                    </div>

                    <!-- Right column: enrolled students (names only, scrollable) -->
                    <aside class="space-y-2">
                        <div class="flex items-center justify-between">
                            <h2 class="text-sm font-semibold">
                                Enrolled ({{ detail.enrollments.length }})
                            </h2>
                            <Button
                                v-if="canEditDetails"
                                variant="outline"
                                size="sm"
                                @click="rosterOpen = true"
                            >
                                Manage
                            </Button>
                        </div>
                        <div
                            class="max-h-[65vh] overflow-y-auto rounded-md border border-border"
                        >
                            <ul
                                v-if="detail.enrollments.length"
                                class="divide-y divide-border text-sm"
                            >
                                <li
                                    v-for="e in detail.enrollments"
                                    :key="e.id"
                                    class="flex items-center justify-between gap-2 px-3 py-1.5"
                                >
                                    <span>{{ e.user_name }}</span>
                                    <Badge
                                        v-if="e.status !== 'enrolled'"
                                        variant="secondary"
                                        class="text-[10px]"
                                    >
                                        {{ e.status }}
                                    </Badge>
                                </li>
                            </ul>
                            <p
                                v-else
                                class="px-3 py-4 text-sm text-muted-foreground"
                            >
                                Nobody enrolled yet.
                            </p>
                        </div>
                    </aside>
                </div>

                <ManageTopicsModal
                    v-model:open="topicsOpen"
                    :class-id="props.classId"
                />
                <ManageRosterModal
                    v-model:open="rosterOpen"
                    :class-id="props.classId"
                    :users="userPicker"
                />
                <ClassCompleteModal
                    v-model:open="completeOpen"
                    :target="detail"
                />

                <Dialog v-model:open="reopenOpen">
                    <DialogContent>
                        <DialogHeader>
                            <DialogTitle>Re-open this class?</DialogTitle>
                            <DialogDescription>
                                This unlocks the class for editing. Issued
                                certificates are kept — fix a typo, or add or
                                remove people, then complete it again. Removing
                                a person de-issues only their certificate;
                                everyone else's is preserved.
                            </DialogDescription>
                        </DialogHeader>
                        <ErrorBanner :context="REOPEN_CTX" />
                        <DialogFooter>
                            <Button
                                variant="outline"
                                :disabled="reopening"
                                @click="reopenOpen = false"
                            >
                                Cancel
                            </Button>
                            <Button :disabled="reopening" @click="reopen">
                                Re-open
                            </Button>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>
            </template>
        </AsyncState>
    </div>
</template>
