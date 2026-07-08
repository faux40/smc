<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import DualListShuttle from '@/components/DualListShuttle.vue';
import TagFilter from '@/components/TagFilter.vue';
import type { TagFilterMode } from '@/components/TagFilter.vue';
import TagPill from '@/components/TagPill.vue';
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
import { ASSIGNABLE_ROLES } from '@/lib/userRoles';
import UserFormModal from '@/pages/users/Partials/UserFormModal.vue';
import UsersBulkAddGrid from '@/pages/users/Partials/UsersBulkAddGrid.vue';
import type { ClassDetail } from '@/stores/classes';
import { useClassesStore } from '@/stores/classes';
import type { TagRow } from '@/stores/tags';
import { useTagsStore } from '@/stores/tags';
import { useUsersStore } from '@/stores/users';
import type { PickerUserRow } from '@/stores/users';

export interface PickerUser {
    id: string;
    sort_name?: string;
    email: string | null;
    department?: string | null;
    supervisor_sort_name?: string | null;
    job_title?: string | null;
    location?: string | null;
    tag_ids?: string[];
}

const props = defineProps<{
    open: boolean;
    classId: string;
    users: PickerUser[];
}>();
const emit = defineEmits<{ (e: 'update:open', v: boolean): void }>();

const store = useClassesStore();
const tagsStore = useTagsStore();
const usersStore = useUsersStore();

onMounted(() => {
    void tagsStore.loadLibrary();
});

// ── Inline add-user (single + bulk) ────────────────────────────────────────
// Create people without leaving the roster; new users are queued as enrolled
// (added to `selected`) and persisted on close via the existing commit() diff.
const addPersonOpen = ref(false);
const showBulkAdd = ref(false);

const assignableRoles = [...ASSIGNABLE_ROLES];
const existingEmails = computed(() =>
    usersStore.users
        .map((u) => u.email)
        .filter((e): e is string => typeof e === 'string' && e !== ''),
);
const supervisorOptions = computed(() =>
    usersStore.users.map((u) => ({ id: u.id, name: u.sort_name || u.id })),
);

function openAddPerson(): void {
    void usersStore.loadFieldOptions();
    addPersonOpen.value = true;
}

function toggleBulkAdd(): void {
    showBulkAdd.value = !showBulkAdd.value;

    if (showBulkAdd.value) {
        void usersStore.loadFieldOptions();
    }
}

// Queue newly created people as enrolled and refresh the picker so they show
// up with full profile data in the lists.
async function enrollCreated(userIds: string[]): Promise<void> {
    selected.value = new Set([...selected.value, ...userIds]);
    await usersStore.loadPicker(true);
}

function onPersonCreated(user: PickerUserRow): void {
    void enrollCreated([user.id]);
}

const tagsById = computed(
    () => new Map(tagsStore.library.map((t) => [t.id, t])),
);

const detail = computed(() => store.detail[props.classId] ?? null);
const canEdit = computed(
    () =>
        detail.value?.can_edit === true && detail.value?.status === 'scheduled',
);

const actionError = ref<string | null>(null);
const saving = ref(false);

// Local working set of enrolled user-ids — moves are instant client-side and
// only persisted (diffed against the server roster) when the modal closes.
const selected = ref<Set<string>>(new Set());

// Snapshot of the roster AT THE MOMENT a loaded detail is first seen during
// this open lifecycle. commit() diffs against this baseline — never a live
// `detail` that may still be loading or have swapped underneath us — and bails
// entirely when it was never captured. This is the guard against the reported
// data-loss bug: opening before the roster loads must never be read on close
// as "the user removed everyone".
const baseline = ref<Set<string> | null>(null);
// user_id → enrollment_id, captured alongside the baseline so an unenroll maps
// back to a stable enrollment id without trusting the (possibly-swapped) live
// detail at commit time.
const baselineEnrollmentIds = ref<Map<string, string>>(new Map());

// Source-list filters (apply to the Available list only).
const deptFilter = ref<string>('');
const tagFilterIds = ref<string[]>([]);
const tagFilterMode = ref<TagFilterMode>('and');

function seedBaseline(d: ClassDetail): void {
    baseline.value = new Set(d.enrollments.map((e) => e.user_id));
    baselineEnrollmentIds.value = new Map(
        d.enrollments.map((e) => [e.user_id, e.id]),
    );
    selected.value = new Set(baseline.value);
}

// React to BOTH the open toggle and the detail loading. A fresh open resets
// transient state and drops any stale baseline; the baseline is then seeded
// once, as soon as a loaded detail is available — which covers "opened before
// the roster finished loading".
watch(
    [() => props.open, detail],
    ([open], [prevOpen]) => {
        if (open && !prevOpen) {
            actionError.value = null;
            baseline.value = null;
            baselineEnrollmentIds.value = new Map();
            selected.value = new Set();
            deptFilter.value = '';
            tagFilterIds.value = [];
            tagFilterMode.value = 'and';
        }

        if (open && baseline.value === null && detail.value) {
            seedBaseline(detail.value);
        }
    },
);

const userLabel = (u: PickerUser) => u.sort_name || u.email || u.id;

const columns = [
    { key: 'name', label: 'Name' },
    { key: 'email', label: 'Email' },
    { key: 'department', label: 'Department' },
    { key: 'supervisor', label: 'Supervisor' },
    { key: 'job_title', label: 'Job title' },
    { key: 'location', label: 'Location' },
    { key: 'tags', label: 'Tags' },
];

interface StudentItem {
    id: string;
    name: string;
    email: string;
    department: string;
    supervisor: string;
    job_title: string;
    location: string;
    tags: TagRow[];
    searchText: string;
}

// Plain-text cell value for the non-tag columns. Kept in the script (not the
// template) so the `Record<…>` cast doesn't trip the SFC's HTML parser.
const cellValue = (item: StudentItem, key: string): string =>
    (item as unknown as Record<string, string>)[key] || '—';

const toItem = (u: PickerUser): StudentItem => {
    const tags = (u.tag_ids ?? [])
        .map((id) => tagsById.value.get(id))
        .filter((t): t is TagRow => t !== undefined);

    return {
        id: u.id,
        name: userLabel(u),
        email: u.email ?? '',
        department: u.department ?? '',
        supervisor: u.supervisor_sort_name ?? '',
        job_title: u.job_title ?? '',
        location: u.location ?? '',
        tags,
        // Tag names are searchable even though they render as pills.
        searchText: tags.map((t) => t.name).join(' '),
    };
};

// Distinct, sorted departments present in the user pool — drives the filter.
const departments = computed<string[]>(() => {
    const set = new Set<string>();

    for (const u of props.users) {
        if (u.department) {
            set.add(u.department);
        }
    }

    return [...set].sort((a, b) => a.localeCompare(b));
});

function matchesTags(userTagIds: string[]): boolean {
    if (tagFilterIds.value.length === 0) {
        return true;
    }

    const has = (id: string) => userTagIds.includes(id);

    if (tagFilterMode.value === 'and') {
        return tagFilterIds.value.every(has);
    }

    if (tagFilterMode.value === 'or') {
        return tagFilterIds.value.some(has);
    }

    return !tagFilterIds.value.some(has); // 'not' — exclude any match
}

const assigned = computed<StudentItem[]>(() =>
    props.users.filter((u) => selected.value.has(u.id)).map(toItem),
);
const available = computed<StudentItem[]>(() =>
    props.users
        .filter((u) => !selected.value.has(u.id))
        .filter(
            (u) => deptFilter.value === '' || u.department === deptFilter.value,
        )
        .filter((u) => matchesTags(u.tag_ids ?? []))
        .map(toItem),
);

function assign(item: { id: string }): void {
    const next = new Set(selected.value);
    next.add(item.id);
    selected.value = next;
}
function unassign(item: { id: string }): void {
    const next = new Set(selected.value);
    next.delete(item.id);
    selected.value = next;
}

// Persist the queued add/removes (diff vs the server roster) on close — one
// bulk request rather than a call per student.
async function commit(): Promise<void> {
    // No baseline means we never saw a loaded roster this session (e.g. the
    // modal was closed before the roster finished loading). Never infer
    // removals from an absent baseline — bail.
    if (baseline.value === null || !canEdit.value) {
        return;
    }

    const base = baseline.value;
    // Enroll every selected id not already on the (baseline) roster — derived
    // from the selection set (not the available pool) so a just-created user
    // enrolls even before the picker refresh repopulates the lists.
    const toEnroll = [...selected.value].filter((id) => !base.has(id));
    // Unenroll = baseline members the user actively shuttled out, mapped back
    // to their snapshot enrollment ids.
    const toUnenroll = [...base]
        .filter((userId) => !selected.value.has(userId))
        .map((userId) => baselineEnrollmentIds.value.get(userId))
        .filter((id): id is string => typeof id === 'string');

    if (toEnroll.length === 0 && toUnenroll.length === 0) {
        return;
    }

    saving.value = true;

    // Belt-and-suspenders: emptying an entire multi-person roster is
    // catastrophic if it's ever unintended, so the server rejects it unless we
    // confirm the intent. Flag it only when the user genuinely cleared everyone
    // (all baseline members removed, nobody added).
    const clearsWholeRoster =
        toEnroll.length === 0 &&
        base.size >= 2 &&
        toUnenroll.length === base.size;

    try {
        await store.bulkEnroll(props.classId, {
            enroll: toEnroll,
            unenroll: toUnenroll,
            ...(clearsWholeRoster ? { confirm_clear: true } : {}),
        });
    } catch (e) {
        actionError.value =
            (e as { response?: { data?: { message?: string } } }).response?.data
                ?.message ?? (e as Error).message;
    } finally {
        saving.value = false;
    }
}

function onOpenChange(value: boolean): void {
    if (!value) {
        void commit();
    }

    emit('update:open', value);
}
</script>

<template>
    <Dialog :open="open" @update:open="onOpenChange">
        <DialogContent
            class="max-h-[90vh] w-[94vw] overflow-y-auto sm:max-w-7xl"
        >
            <DialogHeader>
                <DialogTitle>Roster</DialogTitle>
                <DialogDescription>
                    Promote students from the source list into the roster;
                    filter by department or tag to find them. Changes save when
                    you close.
                </DialogDescription>
            </DialogHeader>

            <p
                v-if="actionError"
                class="rounded bg-red-50 p-2 text-sm text-red-800 dark:bg-red-900/30 dark:text-red-200"
            >
                {{ actionError }}
            </p>

            <!-- Inline add-user: create people without leaving the roster.
                 Created users are queued as enrolled and saved on close. -->
            <div v-if="canEdit" class="flex items-center gap-2">
                <span class="text-xs text-muted-foreground">
                    Don't see someone?
                </span>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    @click="openAddPerson"
                >
                    + Add a person
                </Button>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    data-testid="roster-bulk-toggle"
                    @click="toggleBulkAdd"
                >
                    {{ showBulkAdd ? 'Close bulk add' : 'Bulk add' }}
                </Button>
            </div>

            <UsersBulkAddGrid
                v-if="canEdit && showBulkAdd"
                :existing-emails="existingEmails"
                :roles="assignableRoles"
                :supervisors="supervisorOptions"
                :field-options="usersStore.fieldOptions"
                @created="enrollCreated"
                @close="showBulkAdd = false"
            />

            <DualListShuttle
                :assigned="assigned"
                :available="available"
                :columns="columns"
                layout="stacked"
                assigned-title="Enrolled"
                available-title="Available students"
                search-placeholder="Search name, email, title, dept, location…"
                always-expanded
                :disabled="!canEdit"
                @assign="assign"
                @unassign="unassign"
            >
                <template #available-controls>
                    <div class="flex flex-wrap items-center gap-3">
                        <div class="flex items-center gap-1.5">
                            <Label for="roster_dept" class="text-xs">
                                Department
                            </Label>
                            <select
                                id="roster_dept"
                                v-model="deptFilter"
                                class="h-8 rounded border border-input bg-background px-2 text-xs"
                            >
                                <option value="">All departments</option>
                                <option
                                    v-for="d in departments"
                                    :key="d"
                                    :value="d"
                                >
                                    {{ d }}
                                </option>
                            </select>
                        </div>
                        <div class="flex items-center gap-1.5">
                            <span class="text-xs text-muted-foreground">
                                Tags
                            </span>
                            <TagFilter
                                v-model:tag-ids="tagFilterIds"
                                v-model:mode="tagFilterMode"
                                placeholder="any"
                            />
                        </div>
                    </div>
                </template>

                <template #cell="{ item, column }">
                    <span
                        v-if="column.key === 'tags'"
                        class="flex flex-wrap gap-1"
                    >
                        <TagPill
                            v-for="t in (item as StudentItem).tags"
                            :key="t.id"
                            :tag="t"
                        />
                        <span
                            v-if="!(item as StudentItem).tags.length"
                            class="text-muted-foreground"
                        >
                            —
                        </span>
                    </span>
                    <template v-else>
                        {{ cellValue(item as StudentItem, column.key) }}
                    </template>
                </template>
            </DualListShuttle>

            <DialogFooter>
                <Button
                    type="button"
                    :disabled="saving"
                    @click="onOpenChange(false)"
                >
                    Done
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>

    <!-- Rich single-add form (reused), in non-navigating inline mode so the
         new user is created and auto-enrolled without leaving the roster. -->
    <UserFormModal
        v-model:open="addPersonOpen"
        mode="create"
        inline
        @created="onPersonCreated"
    />
</template>
