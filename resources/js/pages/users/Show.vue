<script setup lang="ts">
/*
 * User detail page (Phase 13.3; lists rewired to the TA engine in L1/L2).
 *
 * Renders the user's training assignments grouped by canonical status
 * (Overdue / Due soon / Not started / Current / As needed — mutually
 * exclusive and complete, same engine as the pills) plus the full
 * completion history. The Inertia render carries the user-header subject;
 * the compliance + completion data streams in via
 * /api/users/{user}/training-compliance so the page can refresh without
 * an Inertia round-trip.
 */
import { Head, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, onMounted, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import RequirementAssignmentChip from '@/components/RequirementAssignmentChip.vue';
import TagsField from '@/components/TagsField.vue';
import TrainingAssignmentPill from '@/components/TrainingAssignmentPill.vue';
import TrainingAssignmentPillLegend from '@/components/TrainingAssignmentPillLegend.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { realtimeTabId } from '@/echo';
import TrainingAssignmentFormModal from '@/pages/assignments/Partials/TrainingAssignmentFormModal.vue';
import ComplianceStatusBadge from '@/pages/users/Partials/ComplianceStatusBadge.vue';
import UserFormModal from '@/pages/users/Partials/UserFormModal.vue';
import { index as usersIndex } from '@/routes/users';
import { useOrgSettingsStore } from '@/stores/orgSettings';
import { useRequirementAssignmentsStore } from '@/stores/requirementAssignments';
import { useRequirementsStore } from '@/stores/requirements';
import { useTrainingAssignmentsStore } from '@/stores/trainingAssignments';
import type { TrainingAssignmentRow } from '@/stores/trainingAssignments';
import { useUsersStore } from '@/stores/users';
import type { UserRow } from '@/stores/users';

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
    employee_number: string | null;
    supervisor_id: string | null;
    supervisor_name: string | null;
    start_date: string | null;
    end_date: string | null;
    notes: string | null;
    can_edit: boolean;
}

interface CompliancePayload {
    groups: {
        overdue: TrainingComplianceRow[];
        due_soon: TrainingComplianceRow[];
        not_started: TrainingComplianceRow[];
        current: TrainingComplianceRow[];
        as_needed: TrainingComplianceRow[];
    };
    completions: CompletionHistoryRow[];
}

interface SourceChip {
    type: 'direct' | 'requirement';
    id: string | null;
    name: string | null;
}

interface TrainingComplianceRow {
    id: string;
    training_id: string;
    training_name: string;
    status:
        | 'overdue'
        | 'due_soon'
        | 'not_started'
        | 'current'
        | 'as_needed';
    expires_at: string | null;
    last_completed_at: string | null;
    days_until_due: number | null;
    sources: SourceChip[];
}

interface CompletionHistoryRow {
    id: string;
    module_type: string;
    module_id: string;
    training_name: string | null;
    completion_date: string | null;
    certification_date: string | null;
    expire_date: string | null;
    cert_ident: string | null;
    cert_id: string | null;
    hours: number | null;
    class_training_id: string | null;
    class_id: string | null;
    class_name: string | null;
    notes: string | null;
    rqmt_element_ids: string[];
}

const props = defineProps<{ subject: Subject; tagIds: string[] }>();

const page = usePage();
const authUser = computed(
    () =>
        page.props.auth.user as {
            org_id?: string;
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
const reqAssignStore = useRequirementAssignmentsStore();
const requirements = useRequirementsStore();
const orgSettings = useOrgSettingsStore();
const usersStore = useUsersStore();
const userTas = computed<TrainingAssignmentRow[]>(() =>
    taStore.forUser(props.subject.id),
);

// ── Profile edit (reuses the canonical UserFormModal) ──────────────────────
const editOpen = ref(false);

// The modal populates its form from a UserRow; the detail subject carries
// every field it reads (name parts, contact, role/status, profile,
// supervisor_id). Pad the cache-only fields it never touches on edit.
const editTarget = computed<UserRow>(() => ({
    id: props.subject.id,
    name: props.subject.name,
    // The edit modal reads the name parts, not the sortable display name —
    // the detail subject doesn't carry it, so mirror `name` here.
    sort_name: props.subject.name,
    f_name: props.subject.f_name ?? '',
    m_name: props.subject.m_name,
    l_name: props.subject.l_name ?? '',
    prefix_name: props.subject.prefix_name,
    suffix_name: props.subject.suffix_name,
    email: props.subject.email,
    status: props.subject.status,
    role: props.subject.role,
    department: props.subject.department,
    location: props.subject.location,
    job_title: props.subject.job_title,
    employee_number: props.subject.employee_number,
    supervisor_id: props.subject.supervisor_id,
    supervisor_name: props.subject.supervisor_name,
    supervisor_sort_name: props.subject.supervisor_name,
    start_date: props.subject.start_date,
    end_date: props.subject.end_date,
    notes: props.subject.notes,
    created_at: null,
    tag_ids: props.tagIds,
    can_edit: props.subject.can_edit,
    can_disable: false,
    can_delete: false,
}));

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
    if (authUser.value?.org_id) {
        usersStore.subscribe(authUser.value.org_id);
    }

    await Promise.all([
        load(),
        taStore.loadFor({ user_id: props.subject.id }),
        requirements.load(),
        // Roster for the edit modal's supervisor dropdown (lazy — skipped if
        // the cache is already warm). Only fetched when the actor can edit.
        props.subject.can_edit
            ? usersStore.loadPicker()
            : Promise.resolve(),
    ]);
});

async function load(): Promise<void> {
    loading.value = true;
    error.value = null;

    try {
        const { data: resp } = await axios.get<CompliancePayload>(
            `/api/users/${props.subject.id}/training-compliance`,
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
        description: "Expiring within the org's amber window.",
    },
    {
        key: 'not_started',
        label: 'Not started',
        description: 'Assigned, never completed.',
    },
    { key: 'current', label: 'Current', description: 'Satisfied for now.' },
    {
        key: 'as_needed',
        label: 'As needed',
        description: 'Visible on the user; not scheduled or required.',
    },
];

const groupCount = (key: keyof CompliancePayload['groups']): number =>
    data.value?.groups[key].length ?? 0;

const formatDueLabel = (row: TrainingComplianceRow): string => {
    if (row.expires_at === null) {
        return '—';
    }

    if (row.days_until_due === null) {
        return row.expires_at;
    }

    if (row.days_until_due < 0) {
        return `${row.expires_at} (${Math.abs(row.days_until_due)}d overdue)`;
    }

    if (row.days_until_due === 0) {
        return `${row.expires_at} (today)`;
    }

    return `${row.expires_at} (${row.days_until_due}d)`;
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
        <div class="flex items-start justify-between gap-3">
            <Heading
                :title="fullName"
                :description="subject.email ?? 'No email on file.'"
            />
            <div class="flex items-center gap-2">
                <Button
                    v-if="canAssign"
                    as-child
                    size="sm"
                    variant="outline"
                >
                    <a
                        :href="`/api/reports/user/${subject.id}/record`"
                        target="_blank"
                        rel="noopener"
                        data-testid="export-user-record"
                    >
                        Print record (PDF)
                    </a>
                </Button>
                <Button
                    v-if="subject.can_edit"
                    size="sm"
                    variant="outline"
                    data-testid="edit-user-btn"
                    @click="editOpen = true"
                >
                    Edit profile
                </Button>
            </div>
        </div>

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

        <div v-if="subject.notes" class="space-y-1">
            <h2 class="text-xs text-muted-foreground">Notes</h2>
            <p
                class="whitespace-pre-line rounded-md border border-border bg-muted/30 p-3 text-sm"
            >
                {{ subject.notes }}
            </p>
        </div>

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

            <div v-else class="flex flex-col gap-2">
                <div
                    v-if="reqAssignStore.forUser(subject.id).length"
                    class="flex flex-wrap gap-1"
                >
                    <RequirementAssignmentChip
                        v-for="ra in reqAssignStore.forUser(subject.id)"
                        :key="ra.requirement_id"
                        :row="ra"
                        :can-delete="canAssign"
                        @remove="reqAssignStore.destroyByRequirement(ra.user_id, ra.requirement_id)"
                    />
                </div>
                <TrainingAssignmentPillLegend />
                <div class="flex flex-wrap gap-1.5">
                    <TrainingAssignmentPill
                        v-for="ta in userTas"
                        :key="ta.id"
                        :row="ta"
                        :expiring-soon-days="orgSettings.expiringSoonDays"
                        @click="openTaView(ta)"
                    />
                </div>
            </div>
        </section>

        <TrainingAssignmentFormModal
            v-model:open="taModalOpen"
            :mode="taModalMode"
            :target="taModalTarget"
            :initial-user-id="taModalMode === 'create' ? subject.id : null"
        />

        <UserFormModal
            v-if="subject.can_edit"
            v-model:open="editOpen"
            mode="edit"
            :target="editTarget"
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
                                    Training
                                </th>
                                <th class="px-3 py-2 text-left font-medium">
                                    Source
                                </th>
                                <th class="px-3 py-2 text-left font-medium">
                                    Last completed
                                </th>
                                <th class="px-3 py-2 text-left font-medium">
                                    Due
                                </th>
                                <th class="px-3 py-2 text-left font-medium">
                                    Status
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border">
                            <tr
                                v-for="row in data.groups[group.key]"
                                :key="row.id"
                            >
                                <td class="px-3 py-2 font-medium">
                                    {{ row.training_name }}
                                </td>
                                <td class="px-3 py-2 text-xs">
                                    <span class="flex flex-wrap gap-1">
                                        <span
                                            v-for="(chip, i) in row.sources"
                                            :key="i"
                                            class="rounded-full border border-border px-1.5 py-0.5 text-muted-foreground"
                                        >
                                            {{
                                                chip.type === 'requirement'
                                                    ? (chip.name ??
                                                      'Requirement')
                                                    : 'Direct'
                                            }}
                                        </span>
                                    </span>
                                </td>
                                <td class="px-3 py-2 text-xs">
                                    {{ row.last_completed_at ?? '—' }}
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
                    data-testid="completion-history"
                    class="overflow-hidden rounded-md border border-border"
                >
                    <table class="min-w-full divide-y divide-border text-sm">
                        <thead class="bg-muted/40">
                            <tr>
                                <th class="px-3 py-2 text-left font-medium">
                                    Training
                                </th>
                                <th class="px-3 py-2 text-left font-medium">
                                    Completed
                                </th>
                                <th class="px-3 py-2 text-left font-medium">
                                    Expires
                                </th>
                                <th class="px-3 py-2 text-left font-medium">
                                    Cert
                                </th>
                                <th class="px-3 py-2 text-left font-medium">
                                    Hours
                                </th>
                                <th class="px-3 py-2 text-left font-medium">
                                    Source
                                </th>
                                <th class="px-3 py-2 text-left font-medium">
                                    Notes
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border">
                            <tr v-for="c in data.completions" :key="c.id">
                                <td class="px-3 py-2 font-medium">
                                    {{ c.training_name ?? '—' }}
                                </td>
                                <td class="px-3 py-2 text-xs">
                                    {{ c.completion_date ?? '—' }}
                                </td>
                                <td class="px-3 py-2 text-xs">
                                    {{ c.expire_date ?? '—' }}
                                </td>
                                <td class="px-3 py-2 text-xs">
                                    <a
                                        :href="`/api/completions/${c.id}/certificate`"
                                        target="_blank"
                                        class="text-primary hover:underline"
                                    >
                                        {{
                                            c.cert_ident ??
                                            c.cert_id ??
                                            'Certificate'
                                        }}
                                    </a>
                                </td>
                                <td class="px-3 py-2 text-xs">
                                    {{ c.hours ?? '—' }}
                                </td>
                                <td class="px-3 py-2 text-xs">
                                    <a
                                        v-if="c.class_id"
                                        :href="`/classes/${c.class_id}`"
                                        class="text-primary hover:underline"
                                    >
                                        {{ c.class_name ?? 'Class' }}
                                    </a>
                                    <span v-else class="text-muted-foreground">
                                        Manual entry
                                    </span>
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
