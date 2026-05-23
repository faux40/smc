<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, onMounted, ref, watch } from 'vue';
import AsyncState from '@/components/AsyncState.vue';
import ClassFieldset from '@/components/ClassFieldset.vue';
import DualListShuttle from '@/components/DualListShuttle.vue';
import ErrorBanner from '@/components/ErrorBanner.vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useClassForm } from '@/composables/useClassForm';
import { realtimeTabId } from '@/echo';
import { optionalNumber } from '@/lib/forms';
import ClassCompleteModal from '@/pages/classes/Partials/ClassCompleteModal.vue';
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

interface PickerUser {
    id: string;
    f_name: string;
    l_name: string;
    email: string | null;
}

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
const enrollUserId = ref('');
const actionError = ref<string | null>(null);

// Inline edit of the class's core fields (scheduled, editable classes).
const { form, setFrom, validate, payload } = useClassForm(FORM_CTX);
const saving = ref(false);

const canEditDetails = computed(
    () => detail.value?.can_edit === true && detail.value?.status === 'scheduled',
);

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
        text(f.location) !== text(d.location) ||
        text(f.instructor) !== text(d.instructor) ||
        optionalNumber(f.total_hours) !== optionalNumber(d.total_hours) ||
        text(f.notes) !== text(d.notes)
    );
});

// Keep the form in sync with the server copy unless the user has unsaved
// edits in flight (so a realtime update doesn't clobber typing).
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

// Enrollable users = org users not already on the roster.
const enrollableUsers = computed(() => {
    const enrolled = new Set(detail.value?.enrollments.map((e) => e.user_id));

    return userPicker.value.filter((u) => !enrolled.has(u.id));
});

const userLabel = (u: PickerUser) =>
    [u.f_name, u.l_name].filter(Boolean).join(' ') || u.email || u.id;

async function run(fn: () => Promise<unknown>): Promise<void> {
    actionError.value = null;

    try {
        await fn();
    } catch (e) {
        actionError.value =
            (e as { response?: { data?: { message?: string } } }).response?.data
                ?.message ?? (e as Error).message;
    }
}

// --- Training topics: dual-list shuttle (assigned | available) ---
const topicColumns = [
    { key: 'name', label: 'Topic' },
    { key: 'freq', label: 'Frequency' },
];

const freqLabel = (t: {
    std_freq_name: string | null;
    as_needed: boolean;
    initial_only: boolean;
}) =>
    t.std_freq_name ?? (t.as_needed ? 'as-needed' : t.initial_only ? 'initial' : '—');

interface TopicItem {
    id: string;
    name: string;
    freq: string;
    hours: string | null;
}

const assignedTopics = computed<TopicItem[]>(() =>
    (detail.value?.trainings ?? []).map((t) => ({
        id: t.id, // class_training id (used to detach + edit hours)
        name: t.training_name,
        freq: freqLabel(t),
        hours: t.hours,
    })),
);

const availableTopics = computed<TopicItem[]>(() => {
    const taken = new Set(
        (detail.value?.trainings ?? []).map((t) => t.training_id),
    );

    return trainings.library
        .filter((t) => !taken.has(t.id))
        .map((t) => ({
            id: t.id,
            name: t.name,
            freq: freqLabel(t),
            hours: t.default_hours,
        }));
});

const assignTopic = (item: { id: string }) =>
    run(() =>
        store.attachTraining(props.classId, {
            training_id: item.id,
            hours: null, // backend defaults from the topic's default_hours
        }),
    );

const unassignTopic = (item: { id: string }) =>
    run(() => store.detachTraining(props.classId, item.id));

const changeTopicHours = (item: { id: string }, value: string) =>
    run(() =>
        store.updateTrainingHours(props.classId, item.id, optionalNumber(value)),
    );

const enroll = () =>
    run(async () => {
        if (!enrollUserId.value) {
            return;
        }

        await store.enroll(props.classId, enrollUserId.value);
        enrollUserId.value = '';
    });
</script>

<template>
    <Head :title="detail?.name ?? 'Class'" />

    <div class="flex flex-col gap-6 p-4">
        <AsyncState :loading="loading" :error="error">
            <template v-if="detail">
                <div class="flex items-start justify-between gap-4">
                    <Heading
                        :title="detail.name"
                        :description="
                            detail.status === 'completed'
                                ? 'Completed — view only'
                                : 'Scheduled class'
                        "
                    />
                    <div class="flex items-center gap-2">
                        <Badge
                            :variant="
                                detail.status === 'completed'
                                    ? 'secondary'
                                    : 'default'
                            "
                        >
                            {{ detail.status }}
                        </Badge>
                        <Button
                            v-if="
                                detail.can_edit &&
                                detail.status === 'scheduled' &&
                                detail.enrollments.length > 0 &&
                                detail.trainings.length > 0
                            "
                            @click="completeOpen = true"
                        >
                            Complete class
                        </Button>
                    </div>
                </div>

                <!-- Class details: always-editable inline form (scheduled) -->
                <section
                    v-if="canEditDetails"
                    class="rounded-md border border-border p-4"
                >
                    <h2 class="mb-3 text-sm font-semibold">Details</h2>
                    <ErrorBanner :context="FORM_CTX" />
                    <form @submit.prevent="saveDetails" novalidate>
                        <ClassFieldset
                            v-model="form"
                            :context="FORM_CTX"
                            id-prefix="edit"
                        />
                        <div class="mt-4 flex justify-end">
                            <Button
                                type="submit"
                                :disabled="!isDirty || saving"
                            >
                                Save changes
                            </Button>
                        </div>
                    </form>
                </section>

                <!-- Read-only details for completed (locked) classes -->
                <dl
                    v-else
                    class="grid grid-cols-2 gap-x-6 gap-y-2 rounded-md border border-border p-4 text-sm sm:grid-cols-3"
                >
                    <div>
                        <dt class="text-xs text-muted-foreground">
                            Scheduled date
                        </dt>
                        <dd>{{ detail.scheduled_date || '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-muted-foreground">
                            Completed
                        </dt>
                        <dd>{{ detail.completion_date || '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-muted-foreground">Location</dt>
                        <dd>{{ detail.location || '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-muted-foreground">Instructor</dt>
                        <dd>{{ detail.instructor || '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-muted-foreground">
                            Total hours
                        </dt>
                        <dd>{{ detail.total_hours ?? '—' }}</dd>
                    </div>
                    <div v-if="detail.notes" class="col-span-2 sm:col-span-3">
                        <dt class="text-xs text-muted-foreground">Notes</dt>
                        <dd class="whitespace-pre-line">{{ detail.notes }}</dd>
                    </div>
                </dl>

                <p
                    v-if="actionError"
                    class="rounded bg-red-50 p-2 text-sm text-red-800 dark:bg-red-900/30 dark:text-red-200"
                >
                    {{ actionError }}
                </p>

                <!-- Trainings -->
                <section class="space-y-2">
                    <h2 class="text-sm font-semibold">Training topics</h2>
                    <DualListShuttle
                        :assigned="assignedTopics"
                        :available="availableTopics"
                        :columns="topicColumns"
                        assigned-title="Assigned topics"
                        available-title="Available topics"
                        search-placeholder="Search topics…"
                        add-label="Add topic"
                        :disabled="!canEditDetails"
                        @assign="assignTopic"
                        @unassign="unassignTopic"
                    >
                        <template #extra-header>Hours</template>
                        <template #extra="{ item, side }">
                            <Input
                                v-if="side === 'assigned'"
                                type="number"
                                step="0.25"
                                min="0"
                                :model-value="item.hours ?? ''"
                                :disabled="!canEditDetails"
                                class="h-7 w-20 text-xs"
                                @change="
                                    changeTopicHours(
                                        item,
                                        ($event.target as HTMLInputElement)
                                            .value,
                                    )
                                "
                            />
                            <span v-else class="text-xs text-muted-foreground">
                                {{ item.hours ?? '—' }}
                            </span>
                        </template>
                    </DualListShuttle>
                </section>

                <!-- Roster -->
                <section class="space-y-2">
                    <h2 class="text-sm font-semibold">Roster</h2>
                    <div
                        v-if="detail.can_edit && detail.status === 'scheduled'"
                        class="flex flex-wrap items-end gap-2"
                    >
                        <div class="grid gap-1">
                            <Label class="text-xs">Enroll user</Label>
                            <Select v-model="enrollUserId">
                                <SelectTrigger class="w-64">
                                    <SelectValue placeholder="Pick a user…" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem
                                        v-for="u in enrollableUsers"
                                        :key="u.id"
                                        :value="u.id"
                                    >
                                        {{ userLabel(u) }}
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                        <Button type="button" @click="enroll">Enroll</Button>
                    </div>

                    <div
                        v-if="detail.enrollments.length === 0"
                        class="rounded border border-dashed border-border p-3 text-xs text-muted-foreground"
                    >
                        Nobody enrolled yet.
                    </div>
                    <ul
                        v-else
                        class="divide-y divide-border rounded-md border border-border"
                    >
                        <li
                            v-for="e in detail.enrollments"
                            :key="e.id"
                            class="flex items-center justify-between gap-3 px-3 py-2 text-sm"
                        >
                            <span>
                                {{ e.user_name }}
                                <Badge
                                    variant="secondary"
                                    class="ml-1 text-[10px]"
                                >
                                    {{ e.status }}
                                </Badge>
                            </span>
                            <button
                                v-if="
                                    detail.can_edit &&
                                    detail.status === 'scheduled'
                                "
                                type="button"
                                class="text-xs text-destructive hover:underline"
                                @click="
                                    run(() =>
                                        store.unenroll(props.classId, e.id),
                                    )
                                "
                            >
                                Remove
                            </button>
                        </li>
                    </ul>
                </section>

                <ClassCompleteModal
                    v-model:open="completeOpen"
                    :target="detail"
                />
            </template>
        </AsyncState>
    </div>
</template>
