<script setup lang="ts">
/*
 * User detail page (Phase 13.3).
 *
 * Renders the user's compliance posture grouped by status (Overdue /
 * Due soon / Current / Not started / Inactive) plus the full completion
 * history. The Inertia render carries the user-header subject; the
 * compliance + completion data streams in via /api/users/{user}/compliance
 * so the page can refresh without an Inertia round-trip.
 */
import { Head, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, onMounted, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import TagsField from '@/components/TagsField.vue';
import TrainingAssignmentPill from '@/components/TrainingAssignmentPill.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { realtimeTabId } from '@/echo';
import TrainingAssignmentFormModal from '@/pages/assignments/Partials/TrainingAssignmentFormModal.vue';
import ComplianceStatusBadge from '@/pages/users/Partials/ComplianceStatusBadge.vue';
import { index as usersIndex } from '@/routes/users';
import { useOrgSettingsStore } from '@/stores/orgSettings';
import { useTrainingAssignmentsStore } from '@/stores/trainingAssignments';
import type { TrainingAssignmentRow } from '@/stores/trainingAssignments';

interface Subject {
    id: string;
    name: string;
    f_name: string | null;
    m_name: string | null;
    l_name: string | null;
    prefix_name: string | null;
    suffix_name: string | null;
    email: string | null;
    status: 'active' | 'disabled';
    role: string | null;
    department: string | null;
    location: string | null;
    job_title: string | null;
    supervisor_name: string | null;
    start_date: string | null;
    end_date: string | null;
}

interface CompliancePayload {
    groups: {
        overdue: AssignmentStatusRow[];
        due_soon: AssignmentStatusRow[];
        current: AssignmentStatusRow[];
        never_started: AssignmentStatusRow[];
        inactive: AssignmentStatusRow[];
    };
    completions: CompletionHistoryRow[];
}

interface AssignmentStatusRow {
    assignment_id: string;
    requirement_id: string;
    requirement_name: string;
    assignment_name: string;
    timing: string;
    start_date: string | null;
    end_date: string | null;
    status: 'overdue' | 'due_soon' | 'current' | 'never_started' | 'inactive';
    last_completion_date: string | null;
    next_due_date: string | null;
    days_until_due: number | null;
}

interface CompletionHistoryRow {
    id: string;
    module_type: string;
    module_id: string;
    completion_date: string | null;
    certification_date: string | null;
    expire_date: string | null;
    cert_ident: string | null;
    notes: string | null;
    rqmt_element_ids: string[];
}

const props = defineProps<{ subject: Subject; tagIds: string[] }>();

const page = usePage();
const authUser = computed(
    () =>
        page.props.auth.user as {
            isOwner?: boolean;
            isSuperAdmin?: boolean;
            isAdmin?: boolean;
            isManager?: boolean;
        } | null,
);
const canManageTagLibrary = computed(() =>
    Boolean(
        authUser.value?.isOwner ||
        authUser.value?.isSuperAdmin ||
        authUser.value?.isAdmin,
    ),
);
const canAssign = computed(() =>
    Boolean(
        authUser.value?.isOwner ||
        authUser.value?.isSuperAdmin ||
        authUser.value?.isAdmin ||
        authUser.value?.isManager,
    ),
);

const taStore = useTrainingAssignmentsStore();
const orgSettings = useOrgSettingsStore();
const userTas = computed<TrainingAssignmentRow[]>(() =>
    taStore.forUser(props.subject.id),
);

const taModalOpen = ref(false);
const taModalMode = ref<'create' | 'view'>('create');
const taModalTarget = ref<TrainingAssignmentRow | null>(null);

function openTaCreate(): void {
    taModalMode.value = 'create';
    taModalTarget.value = null;
    taModalOpen.value = true;
}
function openTaView(row: TrainingAssignmentRow): void {
    taModalMode.value = 'view';
    taModalTarget.value = row;
    taModalOpen.value = true;
}

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Users', href: usersIndex() },
            { title: 'Detail', href: '#' },
        ],
    },
});

const data = ref<CompliancePayload | null>(null);
const loading = ref(true);
const error = ref<string | null>(null);

onMounted(async () => {
    await Promise.all([
        load(),
        taStore.loadFor({ user_id: props.subject.id }),
    ]);
});

async function load(): Promise<void> {
    loading.value = true;
    error.value = null;

    try {
        const { data: resp } = await axios.get<CompliancePayload>(
            `/api/users/${props.subject.id}/compliance`,
            { headers: defaultHeaders() },
        );
        data.value = resp;
    } catch (e) {
        error.value = (e as Error).message;
    } finally {
        loading.value = false;
    }
}

const fullName = computed(() => {
    const parts = [
        props.subject.prefix_name,
        props.subject.f_name,
        props.subject.m_name,
        props.subject.l_name,
        props.subject.suffix_name,
    ].filter((s): s is string => Boolean(s));

    return parts.length > 0
        ? parts.join(' ')
        : (props.subject.email ?? 'Unnamed user');
});

const ORDER: Array<{
    key: keyof CompliancePayload['groups'];
    label: string;
    description: string;
}> = [
    {
        key: 'overdue',
        label: 'Overdue',
        description: 'Past due. Address first.',
    },
    {
        key: 'due_soon',
        label: 'Due soon',
        description: 'Within the next 60 days.',
    },
    { key: 'current', label: 'Current', description: 'Satisfied for now.' },
    {
        key: 'never_started',
        label: 'Not started',
        description: 'Future start_date or no clock yet.',
    },
    {
        key: 'inactive',
        label: 'Inactive',
        description: 'end_date has passed — deactivated.',
    },
];

const groupCount = (key: keyof CompliancePayload['groups']): number =>
    data.value?.groups[key].length ?? 0;

const formatDueLabel = (row: AssignmentStatusRow): string => {
    if (row.next_due_date === null) {
        return '—';
    }

    if (row.days_until_due === null) {
        return row.next_due_date;
    }

    if (row.days_until_due < 0) {
        return `${row.next_due_date} (${Math.abs(row.days_until_due)}d overdue)`;
    }

    if (row.days_until_due === 0) {
        return `${row.next_due_date} (today)`;
    }

    return `${row.next_due_date} (${row.days_until_due}d)`;
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
    <Head :title="fullName" />

    <div class="flex flex-col gap-6 p-4">
        <Heading
            :title="fullName"
            :description="subject.email ?? 'No email on file.'"
        />

        <div
            class="flex flex-wrap items-center gap-2 text-sm text-muted-foreground"
        >
            <Badge variant="secondary">{{ subject.role ?? 'No role' }}</Badge>
            <Badge
                :variant="subject.status === 'active' ? 'default' : 'secondary'"
            >
                {{ subject.status === 'active' ? 'Active' : 'Disabled' }}
            </Badge>
        </div>

        <dl
            v-if="
                subject.job_title ||
                subject.department ||
                subject.location ||
                subject.supervisor_name ||
                subject.start_date ||
                subject.end_date
            "
            class="grid grid-cols-2 gap-x-6 gap-y-2 text-sm sm:grid-cols-3"
        >
            <div v-if="subject.job_title">
                <dt class="text-xs text-muted-foreground">Job title</dt>
                <dd>{{ subject.job_title }}</dd>
            </div>
            <div v-if="subject.department">
                <dt class="text-xs text-muted-foreground">Department</dt>
                <dd>{{ subject.department }}</dd>
            </div>
            <div v-if="subject.location">
                <dt class="text-xs text-muted-foreground">Location</dt>
                <dd>{{ subject.location }}</dd>
            </div>
            <div v-if="subject.supervisor_name">
                <dt class="text-xs text-muted-foreground">Supervisor</dt>
                <dd>{{ subject.supervisor_name }}</dd>
            </div>
            <div v-if="subject.start_date">
                <dt class="text-xs text-muted-foreground">Start date</dt>
                <dd>{{ subject.start_date }}</dd>
            </div>
            <div v-if="subject.end_date">
                <dt class="text-xs text-muted-foreground">End date</dt>
                <dd>{{ subject.end_date }}</dd>
            </div>
        </dl>

        <div class="space-y-2">
            <h2 class="text-sm font-semibold">Tags</h2>
            <TagsField
                morphable-type="App\Models\User"
                :morphable-id="subject.id"
                :initial-tag-ids="props.tagIds"
                :can-manage-library="canManageTagLibrary"
            />
        </div>

        <!-- Training assignments (Phase E2) -->
        <section class="flex flex-col gap-2" data-testid="training-assignments-section">
            <div class="flex items-center justify-between">
                <h2 class="text-base font-semibold">Training assignments</h2>
                <Button
                    v-if="canAssign"
                    size="sm"
                    variant="outline"
                    data-testid="ta-assign-btn"
                    @click="openTaCreate"
                >
                    + Assign
                </Button>
            </div>

            <div
                v-if="userTas.length === 0"
                class="rounded border border-dashed border-border p-3 text-xs text-muted-foreground"
            >
                No training assignments yet.
            </div>

            <div v-else class="flex flex-wrap gap-1.5">
                <TrainingAssignmentPill
                    v-for="ta in userTas"
                    :key="ta.id"
                    :row="ta"
                    :expiring-soon-days="orgSettings.expiringSoonDays"
                    @click="openTaView(ta)"
                />
            </div>
        </section>

        <TrainingAssignmentFormModal
            v-model:open="taModalOpen"
            :mode="taModalMode"
            :target="taModalTarget"
            :initial-user-id="taModalMode === 'create' ? subject.id : null"
        />

        <p
            v-if="error"
            class="rounded bg-red-50 p-2 text-sm text-red-800 dark:bg-red-900/30 dark:text-red-200"
        >
            {{ error }}
        </p>

        <p v-if="loading" class="text-sm text-muted-foreground">
            Loading compliance…
        </p>

        <template v-if="data && !loading">
            <section
                v-for="group in ORDER"
                :key="group.key"
                class="flex flex-col gap-2"
            >
                <div class="flex items-baseline gap-2">
                    <h2 class="text-base font-semibold">{{ group.label }}</h2>
                    <Badge variant="secondary">{{
                        groupCount(group.key)
                    }}</Badge>
                    <span class="text-xs text-muted-foreground">{{
                        group.description
                    }}</span>
                </div>

                <div
                    v-if="groupCount(group.key) === 0"
                    class="rounded border border-dashed border-border p-3 text-xs text-muted-foreground"
                >
                    Nothing here.
                </div>

                <div
                    v-else
                    class="overflow-hidden rounded-md border border-border"
                >
                    <table class="min-w-full divide-y divide-border text-sm">
                        <thead class="bg-muted/40">
                            <tr>
                                <th class="px-3 py-2 text-left font-medium">
                                    Requirement
                                </th>
                                <th class="px-3 py-2 text-left font-medium">
                                    Timing
                                </th>
                                <th class="px-3 py-2 text-left font-medium">
                                    Last completion
                                </th>
                                <th class="px-3 py-2 text-left font-medium">
                                    Next due
                                </th>
                                <th class="px-3 py-2 text-left font-medium">
                                    Status
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border">
                            <tr
                                v-for="row in data.groups[group.key]"
                                :key="row.assignment_id"
                            >
                                <td class="px-3 py-2">
                                    {{ row.requirement_name }}
                                    <div
                                        v-if="row.start_date || row.end_date"
                                        class="text-xs text-muted-foreground"
                                    >
                                        {{ row.start_date ?? '—' }}
                                        <template v-if="row.end_date">
                                            → {{ row.end_date }}</template
                                        >
                                    </div>
                                </td>
                                <td class="px-3 py-2 text-xs">
                                    {{ row.timing }}
                                </td>
                                <td class="px-3 py-2 text-xs">
                                    {{ row.last_completion_date ?? '—' }}
                                </td>
                                <td class="px-3 py-2 text-xs">
                                    {{ formatDueLabel(row) }}
                                </td>
                                <td class="px-3 py-2">
                                    <ComplianceStatusBadge
                                        :status="row.status"
                                    />
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="flex flex-col gap-2">
                <div class="flex items-baseline gap-2">
                    <h2 class="text-base font-semibold">Completion history</h2>
                    <Badge variant="secondary">{{
                        data.completions.length
                    }}</Badge>
                    <span class="text-xs text-muted-foreground">
                        Every completion on file (including any that don't
                        credit a current assignment).
                    </span>
                </div>

                <div
                    v-if="data.completions.length === 0"
                    class="rounded border border-dashed border-border p-3 text-xs text-muted-foreground"
                >
                    No completions on file yet.
                </div>

                <div
                    v-else
                    class="overflow-hidden rounded-md border border-border"
                >
                    <table class="min-w-full divide-y divide-border text-sm">
                        <thead class="bg-muted/40">
                            <tr>
                                <th class="px-3 py-2 text-left font-medium">
                                    Completion date
                                </th>
                                <th class="px-3 py-2 text-left font-medium">
                                    Expires
                                </th>
                                <th class="px-3 py-2 text-left font-medium">
                                    Cert
                                </th>
                                <th class="px-3 py-2 text-left font-medium">
                                    Credits
                                </th>
                                <th class="px-3 py-2 text-left font-medium">
                                    Notes
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border">
                            <tr v-for="c in data.completions" :key="c.id">
                                <td class="px-3 py-2 text-xs">
                                    {{ c.completion_date ?? '—' }}
                                </td>
                                <td class="px-3 py-2 text-xs">
                                    {{ c.expire_date ?? '—' }}
                                </td>
                                <td class="px-3 py-2 text-xs">
                                    {{ c.cert_ident ?? '—' }}
                                </td>
                                <td class="px-3 py-2 text-xs">
                                    {{ c.rqmt_element_ids.length }} element(s)
                                </td>
                                <td
                                    class="px-3 py-2 text-xs text-muted-foreground"
                                >
                                    {{ c.notes ?? '' }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </template>
    </div>
</template>
