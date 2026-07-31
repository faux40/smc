<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { ChevronDown } from 'lucide-vue-next';
import { computed, onMounted, ref, watch } from 'vue';
import AsyncState from '@/components/AsyncState.vue';
import AttachmentsList from '@/components/AttachmentsList.vue';
import AttachmentViewer from '@/components/AttachmentViewer.vue';
import type { GeneratedDoc } from '@/components/AttachmentViewer.vue';
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
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useClassForm } from '@/composables/useClassForm';
import CardPrintRunsList from '@/pages/classes/Partials/CardPrintRunsList.vue';
import ClassCardFieldsModal from '@/pages/classes/Partials/ClassCardFieldsModal.vue';
import ClassCertEditModal from '@/pages/classes/Partials/ClassCertEditModal.vue';
import ClassCompleteModal from '@/pages/classes/Partials/ClassCompleteModal.vue';
import ClassFormModal from '@/pages/classes/Partials/ClassFormModal.vue';
import ManageRosterModal from '@/pages/classes/Partials/ManageRosterModal.vue';
import ManageTopicsModal from '@/pages/classes/Partials/ManageTopicsModal.vue';
import PrintCardsModal from '@/pages/classes/Partials/PrintCardsModal.vue';
import { page as classesPage, showPage } from '@/routes/classes';
import { useCardStocksStore } from '@/stores/cardStocks';
import { useCardTemplatesStore } from '@/stores/cardTemplates';
import { useClassesStore } from '@/stores/classes';
import type { ClassDetail, ClassTrainingRow } from '@/stores/classes';
import { useErrorStore } from '@/stores/errors';
import { useTrainingsStore } from '@/stores/trainings';
import { useUsersStore } from '@/stores/users';

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
const users = useUsersStore();
const page = usePage();
const orgId = computed(
    () => (page.props.auth.user as { org_id?: string } | null)?.org_id ?? null,
);

const loading = ref(true);
const error = ref<string | null>(null);
const detail = computed(() => store.detail[props.classId] ?? null);

const completeOpen = ref(false);
const topicsOpen = ref(false);

// Actions → Duplicate: reuse the create modal seeded from this class, then
// jump to the freshly created copy.
const duplicateOpen = ref(false);

function openDuplicate(): void {
    duplicateOpen.value = true;
}

function onDuplicateSaved(created: ClassDetail): void {
    duplicateOpen.value = false;
    router.visit(showPage(created.id));
}
// Doc generators open in the in-app viewer (preview + browser download/print,
// plus "save to this class's files"), instead of a second tab.
const docOpen = ref(false);
const activeDoc = ref<GeneratedDoc | null>(null);

const DOC_PATHS: Record<GeneratedDoc['kind'], string> = {
    certificates: 'certificates',
    summary: 'summary',
    'sign-in': 'sign-in-sheet',
};

function openDoc(kind: GeneratedDoc['kind'], title: string): void {
    activeDoc.value = {
        kind,
        title,
        classId: props.classId,
        src: `/api/classes/${props.classId}/${DOC_PATHS[kind]}`,
    };
    docOpen.value = true;
}
// Per-topic certificate editor (scheduled classes): which class_training row.
const certOpen = ref(false);
const certTopicId = ref<string | null>(null);
function openCertEditor(topicId: string) {
    certTopicId.value = topicId;
    certOpen.value = true;
}

// Per-topic custom card fields (C3): the values this class prints on cards.
// Viewable on a completed class too — the modal locks itself there.
const cardFieldsOpen = ref(false);
const cardFieldsTopicId = ref<string | null>(null);
function openCardFields(topicId: string) {
    cardFieldsTopicId.value = topicId;
    cardFieldsOpen.value = true;
}

// Printing a topic's cards (C4e). One run is one topic, so the topic is
// chosen by which button was pressed rather than inside the dialog.
const cardTemplates = useCardTemplatesStore();
const cardStocks = useCardStocksStore();
const printOpen = ref(false);
const printTopicId = ref<string | null>(null);

/**
 * A topic is printable when its training carries a card design. Without one
 * there is nothing to print, and orgs that never use cards see no button at
 * all. (A design can still be swapped per run inside the dialog.)
 */
function hasCardDesign(t: ClassTrainingRow): boolean {
    if (!t.training_id) {
        return false;
    }

    return (
        trainings.library.find((x) => x.id === t.training_id)
            ?.card_template_id != null
    );
}

async function openPrintCards(topicId: string): Promise<void> {
    printTopicId.value = topicId;
    printOpen.value = true;

    // Designs and stocks are only needed once someone actually prints, so
    // they're fetched here rather than on every class view. Both stores
    // no-op when already loaded.
    await Promise.all([cardTemplates.load(), cardStocks.load()]);
}
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
    () =>
        detail.value?.can_edit === true && detail.value?.status === 'scheduled',
);

const canComplete = computed(
    () =>
        canEditDetails.value &&
        (detail.value?.enrollments.length ?? 0) > 0 &&
        (detail.value?.trainings.length ?? 0) > 0,
);

const canReopen = computed(
    () =>
        detail.value?.can_edit === true && detail.value?.status === 'completed',
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

// Re-close (keep as-is) — one click, no marking modal. Only offered on a
// re-opened class that was previously completed (canManageCerts): the class
// is already correct (typo fix / single-cert revoke-issue applied
// immediately), so this just re-locks it without running the full
// reconciliation that "Complete class" does.
const reclosing = ref(false);
const RECLOSE_CTX = 'form:class-reclose';

async function reclose(): Promise<void> {
    reclosing.value = true;
    errorStore.clear(RECLOSE_CTX);

    try {
        await store.reclose(props.classId);
    } catch (e) {
        errorStore.reportFromAxios(e, RECLOSE_CTX, {
            fallback: 'Failed to re-close the class.',
        });
    } finally {
        reclosing.value = false;
    }
}

// Re-issue (deliberate renumbering) — only on a re-opened class that was
// previously completed (i.e. it holds issued credit). Scope: ALL_TOPICS =
// whole class, else a specific class_training id. The number change lands on
// the next re-close, which re-mints the cleared cert_ids from the current
// cert_code. (reka's Select reserves '' for the placeholder, so whole-class
// uses a sentinel value.)
const ALL_TOPICS = '__all__';
const reissueOpen = ref(false);
const reissuing = ref(false);
const reissueScope = ref<string>(ALL_TOPICS);
const REISSUE_CTX = 'form:class-reissue';

const hasIssuedCerts = computed(() =>
    (detail.value?.trainings ?? []).some((t) => t.credits.length > 0),
);

const canReissue = computed(() => canEditDetails.value && hasIssuedCerts.value);

const reissueScopeLabel = computed(() => {
    if (reissueScope.value === ALL_TOPICS) {
        return 'all topics';
    }

    const topic = detail.value?.trainings.find(
        (t) => t.id === reissueScope.value,
    );

    return topic ? `“${topic.training_name}”` : 'this topic';
});

function openReissue(): void {
    reissueScope.value = ALL_TOPICS;
    errorStore.clear(REISSUE_CTX);
    reissueOpen.value = true;
}

async function reissue(): Promise<void> {
    reissuing.value = true;
    errorStore.clear(REISSUE_CTX);

    try {
        await store.reissueCertificates(
            props.classId,
            reissueScope.value === ALL_TOPICS ? null : reissueScope.value,
        );
        reissueOpen.value = false;
    } catch (e) {
        errorStore.reportFromAxios(e, REISSUE_CTX, {
            fallback: 'Failed to re-issue certificates.',
        });
    } finally {
        reissuing.value = false;
    }
}

// Single-cert corrections (revoke / issue) — only on a re-opened class that was
// previously completed (it holds a completion_date + issued credit). Both keep
// the enrollment results map in step server-side so the next re-close preserves
// the change.
const canManageCerts = computed(
    () => canEditDetails.value && detail.value?.completion_date != null,
);

// Revoke a single awarded credit.
const revokeOpen = ref(false);
const revoking = ref(false);
const revokeReason = ref('');
const revokeTarget = ref<{
    completionId: string;
    userId: string;
    userName: string | null;
    topicName: string;
} | null>(null);
const REVOKE_CTX = 'form:class-revoke';

function openRevoke(
    topicName: string,
    cr: { completion_id: string; user_id: string; user_name: string | null },
): void {
    revokeTarget.value = {
        completionId: cr.completion_id,
        userId: cr.user_id,
        userName: cr.user_name,
        topicName,
    };
    revokeReason.value = '';
    errorStore.clear(REVOKE_CTX);
    revokeOpen.value = true;
}

async function revoke(): Promise<void> {
    if (!revokeTarget.value) {
        return;
    }

    revoking.value = true;
    errorStore.clear(REVOKE_CTX);

    try {
        await store.revokeCertificate(
            props.classId,
            revokeTarget.value.completionId,
            revokeReason.value.trim() || null,
        );
        revokeOpen.value = false;
    } catch (e) {
        errorStore.reportFromAxios(e, REVOKE_CTX, {
            fallback: 'Failed to revoke the certificate.',
        });
    } finally {
        revoking.value = false;
    }
}

// Issue a single certificate to a missed person (user + topic).
const issueOpen = ref(false);
const issuing = ref(false);
const issueUserId = ref('');
const issueTopicId = ref('');
const ISSUE_CTX = 'form:class-issue';

// Everyone in the org is a candidate — the point is to credit someone who was
// missed (possibly never rostered). Sorted last-name-first for scanning.
const issueUserOptions = computed(() =>
    [...users.users]
        .map((u) => ({
            id: u.id,
            label: u.sort_name || u.name || u.email || u.id,
        }))
        .sort((a, b) => a.label.localeCompare(b.label)),
);

function openIssue(): void {
    issueUserId.value = '';
    issueTopicId.value = detail.value?.trainings[0]?.id ?? '';
    errorStore.clear(ISSUE_CTX);
    issueOpen.value = true;
}

const canSubmitIssue = computed(
    () => issueUserId.value !== '' && issueTopicId.value !== '',
);

async function issue(): Promise<void> {
    if (!canSubmitIssue.value) {
        return;
    }

    issuing.value = true;
    errorStore.clear(ISSUE_CTX);

    try {
        await store.issueCertificate(props.classId, {
            user_id: issueUserId.value,
            class_training_id: issueTopicId.value,
        });
        issueOpen.value = false;
    } catch (e) {
        errorStore.reportFromAxios(e, ISSUE_CTX, {
            fallback: 'Failed to issue the certificate.',
        });
    } finally {
        issuing.value = false;
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
        Number(f.total_hours || 0) !== Number(d.total_hours || 0) ||
        Number(f.min_students || 0) !== Number(d.min_students || 0) ||
        Number(f.max_students || 0) !== Number(d.max_students || 0) ||
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

onMounted(async () => {
    if (orgId.value) {
        store.subscribe(orgId.value);
        users.subscribe(orgId.value);
    }

    try {
        await Promise.all([
            store.loadDetail(props.classId),
            trainings.load(),
            // Force + include disabled: the roster (and the missed-person
            // certificate picker below) both need the full active+disabled
            // pool, regardless of whether an earlier page in this session
            // already warmed the shared cache with the active-only default.
            users.loadPicker(true, true),
        ]);
        setFrom(detail.value);
    } catch (e) {
        error.value = (e as Error).message;
    } finally {
        loading.value = false;
    }
});

const hoursLabel = (h: string | null) => (h ? `${Number(h)}h` : '—');

const totalHoursLabel = computed(
    () => `${Number(detail.value?.total_hours ?? 0).toFixed(1)} hours`,
);

// "8:00 AM" from a stored "HH:MM" (24h), or null. Mirrors the sign-in sheet's
// PHP timeRange so the on-screen detail reads the same as the printed doc.
function clockLabel(t: string | null | undefined): string | null {
    if (!t) {
        return null;
    }

    const [h, m] = t.split(':').map(Number);

    if (Number.isNaN(h) || Number.isNaN(m)) {
        return null;
    }

    const period = h < 12 ? 'AM' : 'PM';
    const h12 = h % 12 === 0 ? 12 : h % 12;

    return `${h12}:${String(m).padStart(2, '0')} ${period}`;
}

const timeLabel = computed(() => {
    const s = clockLabel(detail.value?.start_time);
    const e = clockLabel(detail.value?.end_time);

    if (s && e) {
        return `${s} – ${e}`;
    }

    return s ? `from ${s}` : e ? `until ${e}` : '—';
});
</script>

<template>
    <Head :title="detail?.name ?? 'Class'" />

    <div class="flex flex-col gap-6 p-4">
        <AsyncState :loading="loading" :error="error">
            <template v-if="detail">
                <Link
                    :href="classesPage()"
                    class="inline-flex w-fit items-center gap-1 text-sm text-muted-foreground hover:text-foreground"
                >
                    <span aria-hidden="true">&larr;</span> Back to class list
                </Link>

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
                        v-if="canManageCerts"
                        variant="outline"
                        data-testid="reclose-btn"
                        title="Re-lock the class with no changes to certificates or credit."
                        :disabled="reclosing"
                        @click="reclose"
                    >
                        Re-close (keep as-is)
                    </Button>
                    <Button
                        v-if="canManageCerts"
                        variant="outline"
                        data-testid="issue-open"
                        @click="openIssue"
                    >
                        Issue certificate
                    </Button>
                    <Button
                        v-if="canReissue"
                        variant="outline"
                        data-testid="reissue-open"
                        @click="openReissue"
                    >
                        Re-issue certificates
                    </Button>
                    <Button
                        v-if="canReopen"
                        variant="outline"
                        @click="reopenOpen = true"
                    >
                        Re-open
                    </Button>
                    <DropdownMenu>
                        <DropdownMenuTrigger as-child>
                            <Button
                                variant="outline"
                                data-testid="class-actions-trigger"
                            >
                                Actions
                                <ChevronDown class="size-4" />
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end">
                            <DropdownMenuItem @click="openDuplicate">
                                Duplicate class…
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                </div>

                <ErrorBanner v-if="canManageCerts" :context="RECLOSE_CTX" />

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
                                    >
                                        <template #after-name>
                                            <div
                                                class="rounded-md border border-border bg-muted/20 p-3"
                                            >
                                                <div
                                                    class="mb-1 flex items-center justify-between"
                                                >
                                                    <h3
                                                        class="text-sm font-semibold"
                                                    >
                                                        Included training
                                                        courses
                                                    </h3>
                                                    <Button
                                                        type="button"
                                                        variant="outline"
                                                        size="sm"
                                                        @click="
                                                            topicsOpen = true
                                                        "
                                                    >
                                                        Manage
                                                    </Button>
                                                </div>
                                                <ul
                                                    v-if="
                                                        detail.trainings.length
                                                    "
                                                    class="text-sm"
                                                >
                                                    <li
                                                        v-for="t in detail.trainings"
                                                        :key="t.id"
                                                        class="flex items-center justify-between gap-2 py-0.5"
                                                    >
                                                        <span>
                                                            {{
                                                                t.training_name
                                                            }}
                                                            <span
                                                                class="text-muted-foreground"
                                                            >
                                                                ({{
                                                                    hoursLabel(
                                                                        t.hours,
                                                                    )
                                                                }})
                                                            </span>
                                                        </span>
                                                        <span
                                                            class="flex items-center gap-1"
                                                        >
                                                            <Button
                                                                v-if="
                                                                    t
                                                                        .card_fields
                                                                        .length
                                                                "
                                                                type="button"
                                                                variant="ghost"
                                                                size="sm"
                                                                class="h-6 px-2 text-xs"
                                                                @click="
                                                                    openCardFields(
                                                                        t.id,
                                                                    )
                                                                "
                                                            >
                                                                Card fields
                                                            </Button>
                                                            <Button
                                                                v-if="
                                                                    hasCardDesign(
                                                                        t,
                                                                    )
                                                                "
                                                                type="button"
                                                                variant="ghost"
                                                                size="sm"
                                                                class="h-6 px-2 text-xs"
                                                                @click="
                                                                    openPrintCards(
                                                                        t.id,
                                                                    )
                                                                "
                                                            >
                                                                Print cards
                                                            </Button>
                                                            <Button
                                                                type="button"
                                                                variant="ghost"
                                                                size="sm"
                                                                class="h-6 px-2 text-xs"
                                                                @click="
                                                                    openCertEditor(
                                                                        t.id,
                                                                    )
                                                                "
                                                            >
                                                                Edit certificate
                                                            </Button>
                                                        </span>
                                                    </li>
                                                </ul>
                                                <p
                                                    v-else
                                                    class="text-sm text-muted-foreground"
                                                >
                                                    No courses yet. Use
                                                    “Manage”.
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
                                            class="flex items-center justify-between gap-2 py-0.5"
                                        >
                                            <span>
                                                {{ t.training_name }}
                                                <span
                                                    class="text-muted-foreground"
                                                >
                                                    ({{ hoursLabel(t.hours) }})
                                                </span>
                                            </span>
                                            <!--
                                                Read-only here: the values that
                                                will print are worth seeing
                                                before cards are generated.
                                            -->
                                            <span
                                                class="flex items-center gap-1"
                                            >
                                                <Button
                                                    v-if="t.card_fields.length"
                                                    type="button"
                                                    variant="ghost"
                                                    size="sm"
                                                    class="h-6 px-2 text-xs"
                                                    @click="
                                                        openCardFields(t.id)
                                                    "
                                                >
                                                    Card fields
                                                </Button>
                                                <!--
                                                    Printing from a completed
                                                    class is the main case, not
                                                    an exception to it.
                                                -->
                                                <Button
                                                    v-if="hasCardDesign(t)"
                                                    type="button"
                                                    variant="ghost"
                                                    size="sm"
                                                    class="h-6 px-2 text-xs"
                                                    @click="
                                                        openPrintCards(t.id)
                                                    "
                                                >
                                                    Print cards
                                                </Button>
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
                                        <dt
                                            class="text-xs text-muted-foreground"
                                        >
                                            Scheduled date
                                        </dt>
                                        <dd>
                                            {{ detail.scheduled_date || '—' }}
                                        </dd>
                                    </div>
                                    <div>
                                        <dt
                                            class="text-xs text-muted-foreground"
                                        >
                                            Time
                                        </dt>
                                        <dd>{{ timeLabel }}</dd>
                                    </div>
                                    <div>
                                        <dt
                                            class="text-xs text-muted-foreground"
                                        >
                                            Class Hours
                                        </dt>
                                        <dd>{{ totalHoursLabel }}</dd>
                                    </div>
                                    <div>
                                        <dt
                                            class="text-xs text-muted-foreground"
                                        >
                                            Completed
                                        </dt>
                                        <dd>
                                            {{ detail.completion_date || '—' }}
                                        </dd>
                                    </div>
                                    <div>
                                        <dt
                                            class="text-xs text-muted-foreground"
                                        >
                                            Location
                                        </dt>
                                        <dd>{{ detail.location || '—' }}</dd>
                                    </div>
                                    <div>
                                        <dt
                                            class="text-xs text-muted-foreground"
                                        >
                                            Instructor
                                        </dt>
                                        <dd>{{ detail.instructor || '—' }}</dd>
                                    </div>
                                    <div>
                                        <dt
                                            class="text-xs text-muted-foreground"
                                        >
                                            Certificate signature
                                        </dt>
                                        <dd>
                                            {{
                                                detail.show_signature
                                                    ? 'Shown'
                                                    : 'Hidden'
                                            }}
                                        </dd>
                                    </div>
                                    <div
                                        v-if="detail.address"
                                        class="col-span-2"
                                    >
                                        <dt
                                            class="text-xs text-muted-foreground"
                                        >
                                            Address
                                        </dt>
                                        <dd class="whitespace-pre-line">
                                            {{ detail.address }}
                                        </dd>
                                    </div>
                                    <div
                                        v-if="
                                            detail.min_students != null ||
                                            detail.max_students != null
                                        "
                                    >
                                        <dt
                                            class="text-xs text-muted-foreground"
                                        >
                                            Students (min / max)
                                        </dt>
                                        <dd>
                                            {{ detail.min_students ?? '—' }} /
                                            {{ detail.max_students ?? '—' }}
                                        </dd>
                                    </div>
                                    <div v-if="detail.notes" class="col-span-2">
                                        <dt
                                            class="text-xs text-muted-foreground"
                                        >
                                            Notes
                                        </dt>
                                        <dd class="whitespace-pre-line">
                                            {{ detail.notes }}
                                        </dd>
                                    </div>
                                </dl>
                            </template>
                        </section>

                        <!-- M3: completed class — who earned each credit. Also
                             shown on a re-opened (scheduled) class that still
                             holds issued credit, where each row can be revoked. -->
                        <section
                            v-if="
                                detail.status === 'completed' || hasIssuedCerts
                            "
                            data-testid="credits-awarded"
                            class="space-y-3 rounded-md border border-border p-4"
                        >
                            <h2 class="text-sm font-semibold">
                                Credits awarded
                            </h2>
                            <p
                                v-if="canManageCerts"
                                class="text-xs text-muted-foreground"
                            >
                                Wrong spelling? Edit the person's profile —
                                certificates show their current name, so the
                                number won't change.
                            </p>
                            <div
                                v-for="t in detail.trainings"
                                :key="t.id"
                                class="space-y-1"
                            >
                                <h3 class="text-xs font-semibold">
                                    {{ t.training_name }}
                                    <span
                                        v-if="t.hours"
                                        class="font-normal text-muted-foreground"
                                    >
                                        · {{ t.hours }}h
                                    </span>
                                </h3>
                                <ul
                                    v-if="t.credits.length"
                                    class="divide-y divide-border rounded border border-border text-sm"
                                >
                                    <li
                                        v-for="cr in t.credits"
                                        :key="cr.completion_id"
                                        class="flex flex-wrap items-center justify-between gap-x-3 gap-y-1 px-3 py-1.5"
                                    >
                                        <a
                                            :href="`/users/${cr.user_id}`"
                                            class="font-medium text-primary hover:underline"
                                        >
                                            {{ cr.user_name ?? 'Unknown user' }}
                                        </a>
                                        <span
                                            class="ml-auto text-xs text-muted-foreground"
                                        >
                                            {{ cr.cert_id ?? '—' }}
                                            <template v-if="cr.expire_date">
                                                · expires {{ cr.expire_date }}
                                            </template>
                                        </span>
                                        <Button
                                            v-if="canManageCerts"
                                            type="button"
                                            variant="ghost"
                                            size="sm"
                                            class="h-6 px-2 text-xs text-destructive hover:text-destructive"
                                            data-testid="revoke-cert"
                                            @click="
                                                openRevoke(t.training_name, cr)
                                            "
                                        >
                                            Revoke
                                        </Button>
                                    </li>
                                </ul>
                                <p v-else class="text-xs text-muted-foreground">
                                    No credit issued.
                                </p>
                            </div>
                        </section>

                        <!-- Documents -->
                        <section class="space-y-2">
                            <h2 class="text-sm font-semibold">Documents</h2>
                            <div class="flex flex-wrap gap-2">
                                <Button
                                    variant="outline"
                                    @click="openDoc('sign-in', 'Sign-in sheet')"
                                >
                                    Sign-in sheet
                                </Button>
                                <Button
                                    v-if="detail.status === 'completed'"
                                    variant="outline"
                                    @click="
                                        openDoc('certificates', 'Certificates')
                                    "
                                >
                                    Certificates
                                </Button>
                                <Button
                                    v-if="detail.status === 'completed'"
                                    variant="outline"
                                    @click="openDoc('summary', 'Class summary')"
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

                            <!-- Card print runs: the outcome of a queued run,
                                 above all why one failed. The sheets
                                 themselves land in the file list below. -->
                            <CardPrintRunsList :class-id="props.classId" />

                            <!-- Uploaded files (distinct from the generated PDFs above) -->
                            <div class="space-y-2 border-t border-border pt-4">
                                <h3
                                    class="text-xs font-semibold text-muted-foreground"
                                >
                                    Uploaded files
                                </h3>
                                <AttachmentsList
                                    morphable-type="App\Models\TrainingClass"
                                    :morphable-id="props.classId"
                                />
                            </div>
                        </section>
                    </div>

                    <!-- Right column: enrolled students (names only, scrollable).
                         Completed classes show the per-topic roster below the
                         credit lists instead (M3). -->
                    <aside
                        v-if="detail.status !== 'completed'"
                        class="space-y-2"
                    >
                        <div class="flex items-center justify-between">
                            <h2 class="text-sm font-semibold">
                                Enrolled ({{ detail.enrollments.length
                                }}{{
                                    detail.max_students
                                        ? ` · max ${detail.max_students}`
                                        : ''
                                }})
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
                                    <span>{{
                                        e.user_sort_name ?? e.user_name
                                    }}</span>
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

                <!-- M3: completed class — per-student per-topic roster, below
                     the credit lists -->
                <section
                    v-if="detail.status === 'completed'"
                    data-testid="enrollee-roster"
                    class="space-y-2"
                >
                    <h2 class="text-sm font-semibold">
                        Enrolled ({{ detail.enrollments.length
                        }}{{
                            detail.max_students
                                ? ` · max ${detail.max_students}`
                                : ''
                        }})
                    </h2>
                    <ul
                        v-if="detail.enrollments.length"
                        class="divide-y divide-border rounded-md border border-border text-sm"
                    >
                        <li
                            v-for="e in detail.enrollments"
                            :key="e.id"
                            data-testid="roster-row"
                            class="flex flex-wrap items-center gap-x-3 gap-y-1 px-3 py-2"
                        >
                            <span class="min-w-40 font-medium">
                                {{ e.user_sort_name ?? e.user_name }}
                            </span>
                            <Badge variant="secondary" class="text-[10px]">
                                {{ e.status }}
                            </Badge>
                            <span class="flex flex-wrap gap-1 text-xs">
                                <span
                                    v-for="t in detail.trainings"
                                    :key="t.id"
                                    class="rounded-full border px-1.5 py-0.5"
                                    :class="
                                        (e.results?.[t.id] ?? 'incomplete') ===
                                        'pass'
                                            ? 'border-emerald-300 bg-emerald-50 text-emerald-900 dark:border-emerald-800 dark:bg-emerald-950 dark:text-emerald-200'
                                            : (e.results?.[t.id] ?? '') ===
                                                'fail'
                                              ? 'border-red-300 bg-red-50 text-red-900 dark:border-red-800 dark:bg-red-950 dark:text-red-200'
                                              : 'border-border text-muted-foreground'
                                    "
                                >
                                    {{
                                        (e.results?.[t.id] ?? 'incomplete') ===
                                        'pass'
                                            ? '✓'
                                            : (e.results?.[t.id] ?? '') ===
                                                'fail'
                                              ? '✗'
                                              : '—'
                                    }}
                                    {{ t.training_name }}
                                </span>
                            </span>
                        </li>
                    </ul>
                    <p v-else class="text-sm text-muted-foreground">
                        Nobody was enrolled.
                    </p>
                </section>

                <ManageTopicsModal
                    v-model:open="topicsOpen"
                    :class-id="props.classId"
                />
                <PrintCardsModal
                    v-model:open="printOpen"
                    :class-id="props.classId"
                    :topic-id="printTopicId"
                />

                <ClassCardFieldsModal
                    v-model:open="cardFieldsOpen"
                    :class-id="classId"
                    :topic-id="cardFieldsTopicId"
                />

                <ClassCertEditModal
                    v-model:open="certOpen"
                    :class-id="props.classId"
                    :topic-id="certTopicId"
                />
                <AttachmentViewer
                    v-model:open="docOpen"
                    :generated="activeDoc"
                />
                <ManageRosterModal
                    v-model:open="rosterOpen"
                    :class-id="props.classId"
                    :users="users.users"
                />
                <ClassCompleteModal
                    v-model:open="completeOpen"
                    :target="detail"
                />
                <ClassFormModal
                    v-model:open="duplicateOpen"
                    :copy-from="detail"
                    @saved="onDuplicateSaved"
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

                <Dialog v-model:open="reissueOpen">
                    <DialogContent data-testid="reissue-dialog">
                        <DialogHeader>
                            <DialogTitle>
                                Re-issue certificate numbers?
                            </DialogTitle>
                            <DialogDescription>
                                New certificate numbers will be assigned to
                                {{ reissueScopeLabel }} when you re-complete the
                                class. Previously printed certificates will no
                                longer match.
                            </DialogDescription>
                        </DialogHeader>

                        <div class="space-y-2">
                            <label
                                for="reissue-scope"
                                class="text-sm font-medium"
                            >
                                Scope
                            </label>
                            <Select v-model="reissueScope">
                                <SelectTrigger id="reissue-scope">
                                    <SelectValue placeholder="Whole class" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem :value="ALL_TOPICS">
                                        Whole class (all topics)
                                    </SelectItem>
                                    <SelectItem
                                        v-for="t in detail.trainings"
                                        :key="t.id"
                                        :value="t.id"
                                    >
                                        {{ t.training_name }}
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                        </div>

                        <ErrorBanner :context="REISSUE_CTX" />
                        <DialogFooter>
                            <Button
                                variant="outline"
                                :disabled="reissuing"
                                @click="reissueOpen = false"
                            >
                                Cancel
                            </Button>
                            <Button
                                :disabled="reissuing"
                                data-testid="reissue-confirm"
                                @click="reissue"
                            >
                                Re-issue
                            </Button>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>

                <!-- Revoke a single certificate -->
                <Dialog v-model:open="revokeOpen">
                    <DialogContent data-testid="revoke-dialog">
                        <DialogHeader>
                            <DialogTitle>Revoke this certificate?</DialogTitle>
                            <DialogDescription>
                                <template v-if="revokeTarget">
                                    {{
                                        revokeTarget.userName ?? 'This person'
                                    }}'s certificate for “{{
                                        revokeTarget.topicName
                                    }}” will be removed from their record (kept
                                    for audit). Optionally note why.
                                </template>
                            </DialogDescription>
                        </DialogHeader>

                        <div class="space-y-2">
                            <label
                                for="revoke-reason"
                                class="text-sm font-medium"
                            >
                                Reason (optional)
                            </label>
                            <textarea
                                id="revoke-reason"
                                v-model="revokeReason"
                                rows="3"
                                maxlength="500"
                                data-testid="revoke-reason"
                                class="w-full rounded border border-input bg-background px-3 py-2 text-sm"
                                placeholder="e.g. Attended the wrong session"
                            />
                            <p
                                v-if="revokeTarget"
                                class="text-xs text-muted-foreground"
                            >
                                Wrong spelling? Instead of revoking,
                                <a
                                    :href="`/users/${revokeTarget.userId}`"
                                    class="text-primary hover:underline"
                                >
                                    edit the person's profile </a
                                >— certificates show their current name, so the
                                number won't change.
                            </p>
                        </div>

                        <ErrorBanner :context="REVOKE_CTX" />
                        <DialogFooter>
                            <Button
                                variant="outline"
                                :disabled="revoking"
                                @click="revokeOpen = false"
                            >
                                Cancel
                            </Button>
                            <Button
                                variant="destructive"
                                :disabled="revoking"
                                data-testid="revoke-confirm"
                                @click="revoke"
                            >
                                Revoke
                            </Button>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>

                <!-- Issue a single certificate to a missed person -->
                <Dialog v-model:open="issueOpen">
                    <DialogContent data-testid="issue-dialog">
                        <DialogHeader>
                            <DialogTitle>Issue a certificate</DialogTitle>
                            <DialogDescription>
                                Credit someone who was missed for a topic. They
                                are enrolled if needed and given the next
                                certificate number in this class's sequence.
                            </DialogDescription>
                        </DialogHeader>

                        <div class="space-y-3">
                            <div class="space-y-1">
                                <label
                                    for="issue-user"
                                    class="text-sm font-medium"
                                >
                                    Person
                                </label>
                                <Select v-model="issueUserId">
                                    <SelectTrigger id="issue-user">
                                        <SelectValue
                                            placeholder="Choose a person"
                                        />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem
                                            v-for="u in issueUserOptions"
                                            :key="u.id"
                                            :value="u.id"
                                        >
                                            {{ u.label }}
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                            <div class="space-y-1">
                                <label
                                    for="issue-topic"
                                    class="text-sm font-medium"
                                >
                                    Topic
                                </label>
                                <Select v-model="issueTopicId">
                                    <SelectTrigger id="issue-topic">
                                        <SelectValue
                                            placeholder="Choose a topic"
                                        />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem
                                            v-for="t in detail.trainings"
                                            :key="t.id"
                                            :value="t.id"
                                        >
                                            {{ t.training_name }}
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                        </div>

                        <ErrorBanner :context="ISSUE_CTX" />
                        <DialogFooter>
                            <Button
                                variant="outline"
                                :disabled="issuing"
                                @click="issueOpen = false"
                            >
                                Cancel
                            </Button>
                            <Button
                                :disabled="issuing || !canSubmitIssue"
                                data-testid="issue-confirm"
                                @click="issue"
                            >
                                Issue certificate
                            </Button>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>
            </template>
        </AsyncState>
    </div>
</template>
