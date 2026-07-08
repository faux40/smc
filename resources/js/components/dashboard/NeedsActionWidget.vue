<script setup lang="ts">
/*
 * K2 — the manager's "who needs what, and when" view. One consolidated
 * widget over /api/dashboard/needs-action (every TA that is overdue /
 * not started / due soon, server-sorted worst first) replacing the old
 * requirement-flavored DueSoonWidget + training-flavored
 * TrainingDueSoonWidget pair.
 *
 * Server-driven: status filter, search, and paging all round-trip to SQL
 * via the dashboard store (an org can hold 10k+ actionable rows). Grouping
 * by user/training is applied to the current page client-side.
 */
import { Link } from '@inertiajs/vue3';
import { computed, onMounted, ref, watch } from 'vue';
import DashWidget from '@/components/dashboard/DashWidget.vue';
import Pagination from '@/components/Pagination.vue';
import { Input } from '@/components/ui/input';
import { useServerTable } from '@/composables/useServerTable';
import CompletionFormModal from '@/pages/completions/Partials/CompletionFormModal.vue';
import ComplianceStatusBadge from '@/pages/users/Partials/ComplianceStatusBadge.vue';
import { show as userShow } from '@/routes/users';
import { useDashboardStore } from '@/stores/dashboard';
import type { NeedsActionRow } from '@/stores/dashboard';

type GroupBy = 'user' | 'training';
type StatusFilter = 'all' | 'overdue' | 'due_soon' | 'not_started';

const emit = defineEmits<{ (e: 'completion-recorded'): void }>();

const store = useDashboardStore();

const loading = ref(true);
const error = ref<string | null>(null);

const groupBy = ref<GroupBy>('user');
const statusFilter = ref<StatusFilter>('all');
const search = ref('');

// Server-paged: status filter / search / paging all run on the server. Fixed
// worst-first ordering, so no sort keys are exposed.
const table = useServerTable<NeedsActionRow>(
    (params) =>
        store.needsAction({
            ...params,
            status: statusFilter.value === 'all' ? undefined : statusFilter.value,
        }),
    { perPage: 50 },
);

onMounted(async () => {
    try {
        await table.fetchPage();
    } catch (e) {
        error.value = (e as Error).message;
    } finally {
        loading.value = false;
    }
});

// Changing the status chip is an external filter to useServerTable — reset to
// page 1 and refetch (the fetcher reads statusFilter itself).
watch(statusFilter, () => table.reload());

function onSearch(value: string | number): void {
    search.value = String(value);
    table.setQuery(search.value);
}

const hasActiveFilter = computed(
    () => statusFilter.value !== 'all' || search.value.trim() !== '',
);

interface Group {
    key: string;
    name: string;
    rows: NeedsActionRow[];
}

// First-seen order over the current page: the server sorts worst-first, so the
// group containing the worst row on this page naturally leads.
const groups = computed<Group[]>(() => {
    const map = new Map<string, Group>();

    for (const row of table.rows.value) {
        const key = groupBy.value === 'user' ? row.user_id : row.training_id;
        const name =
            groupBy.value === 'user'
                ? (row.user_name ?? 'Unknown user')
                : row.training_name;

        if (!map.has(key)) {
            map.set(key, { key, name, rows: [] });
        }

        map.get(key)!.rows.push(row);
    }

    return [...map.values()];
});

// ── Record completion (F7) — a per-row quick action prefilled from the
// row itself. Note: row.id is the *assignment* id; row.training_id is the
// module id CompletionFormModal actually records against.
const completionModalOpen = ref(false);
const completionModalUserId = ref<string | null>(null);
const completionModalTrainingId = ref<string | null>(null);

function openCompletion(row: NeedsActionRow): void {
    completionModalUserId.value = row.user_id;
    completionModalTrainingId.value = row.training_id;
    completionModalOpen.value = true;
}

async function onCompletionSaved(): Promise<void> {
    await table.fetchPage();
    // The summary-stats widget owns its own separate fetch — let the
    // dashboard page nudge it too if it wants to.
    emit('completion-recorded');
}
</script>

<template>
    <DashWidget
        widget-id="needs-action"
        title="Needs action"
        description="Every overdue, never-started, or expiring training — who needs what, and when."
        :loading="loading"
        :error="error"
    >
        <template #actions>
            <div class="flex flex-wrap items-center gap-2">
                <Input
                    :model-value="search"
                    type="search"
                    placeholder="Search user or training…"
                    class="h-8 w-48"
                    aria-label="Search needs-action rows"
                    @update:model-value="onSearch"
                />
                <select
                    v-model="statusFilter"
                    data-test="status-filter"
                    aria-label="Filter by status"
                    class="h-8 rounded-md border border-input bg-background px-2 text-xs"
                >
                    <option value="all">All statuses</option>
                    <option value="overdue">Overdue</option>
                    <option value="not_started">Not started</option>
                    <option value="due_soon">Due soon</option>
                </select>
                <div
                    class="flex overflow-hidden rounded-md border border-input text-xs"
                    role="group"
                    aria-label="Group rows by"
                >
                    <button
                        type="button"
                        data-test="group-by-user"
                        class="px-2 py-1.5"
                        :class="
                            groupBy === 'user'
                                ? 'bg-primary text-primary-foreground'
                                : 'bg-background hover:bg-muted'
                        "
                        @click="groupBy = 'user'"
                    >
                        By user
                    </button>
                    <button
                        type="button"
                        data-test="group-by-training"
                        class="border-l border-input px-2 py-1.5"
                        :class="
                            groupBy === 'training'
                                ? 'bg-primary text-primary-foreground'
                                : 'bg-background hover:bg-muted'
                        "
                        @click="groupBy = 'training'"
                    >
                        By training
                    </button>
                </div>
            </div>
        </template>

        <div
            v-if="table.total.value === 0 && !hasActiveFilter"
            class="rounded border border-dashed border-border p-3 text-xs text-muted-foreground"
        >
            Nothing needs action — everyone is current. 🎉
        </div>

        <div
            v-else-if="table.total.value === 0"
            class="rounded border border-dashed border-border p-3 text-xs text-muted-foreground"
        >
            No rows match the filters.
        </div>

        <template v-else>
            <div class="max-h-[32rem] space-y-4 overflow-y-auto pr-1">
                <section v-for="group in groups" :key="group.key">
                    <h4
                        data-test="group-header"
                        class="mb-1.5 flex items-baseline gap-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground"
                    >
                        <Link
                            v-if="groupBy === 'user'"
                            :href="userShow(group.key)"
                            class="text-sm normal-case tracking-normal text-primary hover:underline"
                        >
                            {{ group.name }}
                        </Link>
                        <span
                            v-else
                            class="text-sm normal-case tracking-normal text-foreground"
                        >
                            {{ group.name }}
                        </span>
                        <span>{{ group.rows.length }}</span>
                    </h4>

                    <ul class="divide-y divide-border rounded border border-border">
                        <li
                            v-for="row in group.rows"
                            :key="row.id"
                            class="flex flex-wrap items-center justify-between gap-x-3 gap-y-1 px-3 py-2 text-sm"
                        >
                            <span class="flex min-w-0 items-center gap-2">
                                <ComplianceStatusBadge :status="row.status" />
                                <Link
                                    v-if="groupBy === 'training'"
                                    :href="userShow(row.user_id)"
                                    class="truncate font-medium text-primary hover:underline"
                                >
                                    {{ row.user_name ?? 'Unknown user' }}
                                </Link>
                                <span v-else class="truncate font-medium">
                                    {{ row.training_name }}
                                </span>
                            </span>

                            <span
                                class="flex items-center gap-2 text-xs text-muted-foreground"
                            >
                                <span
                                    v-for="(chip, i) in row.sources"
                                    :key="i"
                                    class="rounded-full border border-border px-1.5 py-0.5"
                                >
                                    {{
                                        chip.type === 'requirement'
                                            ? (chip.name ?? 'Requirement')
                                            : 'Direct'
                                    }}
                                </span>
                                <span v-if="row.expires_at">
                                    due {{ row.expires_at
                                    }}<template v-if="row.days_until_due != null">
                                        ({{ row.days_until_due }}d)</template
                                    >
                                </span>
                                <span v-else>never completed</span>
                            </span>

                            <button
                                type="button"
                                data-test="row-record-completion"
                                class="text-xs font-medium text-primary hover:underline"
                                @click="openCompletion(row)"
                            >
                                Record completion
                            </button>
                        </li>
                    </ul>
                </section>
            </div>

            <Pagination
                :page="table.page.value"
                :last-page="table.lastPage.value"
                :total="table.total.value"
                :per-page="table.perPage.value"
                :loading="table.loading.value"
                @update:page="table.setPage"
                @update:per-page="table.setPerPage"
            />
        </template>

        <CompletionFormModal
            v-model:open="completionModalOpen"
            mode="create"
            :initial-user-id="completionModalUserId"
            :initial-training-id="completionModalTrainingId"
            @saved="onCompletionSaved"
        />
    </DashWidget>
</template>
