<script setup lang="ts">
/*
 * Full-width all-users compliance list (replaces the top-N overdue widget).
 * One row per org user with per-status counts + an overall status badge +
 * tag chips. Sortable headers (name, status, overdue, due-soon), client-side
 * search, and a lazy drill-down: expanding a row fetches that user's full
 * compliance breakdown from /api/users/{id}/compliance on demand.
 */
import { Link } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, onMounted, ref } from 'vue';
import DashWidget from '@/components/dashboard/DashWidget.vue';
import TagPill from '@/components/TagPill.vue';
import { Input } from '@/components/ui/input';
import { realtimeTabId } from '@/echo';
import ComplianceStatusBadge from '@/pages/users/Partials/ComplianceStatusBadge.vue';
import type { ComplianceStatus } from '@/pages/users/Partials/ComplianceStatusBadge.vue';
import { show as userShow } from '@/routes/users';
import { useTagsStore } from '@/stores/tags';

type OverallStatus = ComplianceStatus | 'none';

interface UserRow {
    user_id: string;
    name: string | null;
    email: string | null;
    counts: Record<string, number>;
    overall_status: OverallStatus;
    tag_ids: string[];
}

// Mirrors UserComplianceCalculator::row() — the fields the drill-down shows.
interface DetailItem {
    requirement_name?: string | null;
    status?: ComplianceStatus;
    next_due_date?: string | null;
    last_completion_date?: string | null;
    days_until_due?: number | null;
}

type SortKey = 'name' | 'status' | 'overdue' | 'due_soon';

const tagsStore = useTagsStore();
const rows = ref<UserRow[]>([]);
const loading = ref(true);
const error = ref<string | null>(null);

const search = ref('');
const sortKey = ref<SortKey>('overdue');
const sortAsc = ref(false); // default: most overdue first

// Worst-first ordering for the status column sort.
const STATUS_ORDER: Record<OverallStatus, number> = {
    overdue: 0,
    due_soon: 1,
    never_started: 2,
    current: 3,
    inactive: 4,
    none: 5,
};

// Drill-down group ordering — worst first, matching STATUS_ORDER above.
const DETAIL_GROUP_ORDER = [
    'overdue',
    'due_soon',
    'never_started',
    'current',
    'inactive',
] as const;

// Drill-down state, keyed by user id.
const expanded = ref<string | null>(null);
const detail = ref<Record<string, DetailItem[]>>({});
const detailLoading = ref<string | null>(null);
const detailError = ref<Record<string, string>>({});

onMounted(async () => {
    try {
        const [{ data }] = await Promise.all([
            axios.get<UserRow[]>('/api/dashboard/users-compliance', {
                headers: defaultHeaders(),
            }),
            tagsStore.loadLibrary(),
        ]);
        rows.value = data;
    } catch (e) {
        error.value = (e as Error).message;
    } finally {
        loading.value = false;
    }
});

const filtered = computed(() => {
    const q = search.value.trim().toLowerCase();

    if (!q) {
        return rows.value;
    }

    return rows.value.filter(
        (r) =>
            (r.name ?? '').toLowerCase().includes(q) ||
            (r.email ?? '').toLowerCase().includes(q),
    );
});

const sorted = computed(() => {
    const dir = sortAsc.value ? 1 : -1;

    return [...filtered.value].sort((a, b) => {
        let cmp = 0;

        switch (sortKey.value) {
            case 'name':
                cmp = (a.name ?? '').localeCompare(b.name ?? '');
                break;
            case 'status':
                cmp =
                    STATUS_ORDER[a.overall_status] -
                    STATUS_ORDER[b.overall_status];
                break;
            case 'overdue':
                cmp = (a.counts.overdue ?? 0) - (b.counts.overdue ?? 0);
                break;
            case 'due_soon':
                cmp = (a.counts.due_soon ?? 0) - (b.counts.due_soon ?? 0);
                break;
        }

        return cmp * dir;
    });
});

function toggleSort(key: SortKey): void {
    if (sortKey.value === key) {
        sortAsc.value = !sortAsc.value;
    } else {
        sortKey.value = key;
        // Counts default to descending (most first); name/status ascending.
        sortAsc.value = key === 'name' || key === 'status';
    }
}

function sortIndicator(key: SortKey): string {
    if (sortKey.value !== key) {
        return '';
    }

    return sortAsc.value ? '▲' : '▼';
}

function tagsFor(row: UserRow) {
    return row.tag_ids
        .map((id) => tagsStore.libraryById(id))
        .filter((t): t is NonNullable<typeof t> => t !== undefined);
}

async function toggleExpand(row: UserRow): Promise<void> {
    if (expanded.value === row.user_id) {
        expanded.value = null;

        return;
    }

    expanded.value = row.user_id;

    // Lazy-load the detail once per user.
    if (detail.value[row.user_id] || detailLoading.value === row.user_id) {
        return;
    }

    detailLoading.value = row.user_id;

    try {
        const { data } = await axios.get<{
            groups: Record<string, DetailItem[]>;
        }>(`/api/users/${row.user_id}/compliance`, {
            headers: defaultHeaders(),
        });
        // Every status group, worst first — the drill-down is the full
        // picture for the user, not just the actionable buckets.
        detail.value = {
            ...detail.value,
            [row.user_id]: DETAIL_GROUP_ORDER.flatMap(
                (group) => data.groups[group] ?? [],
            ),
        };
    } catch (e) {
        detailError.value = {
            ...detailError.value,
            [row.user_id]: (e as Error).message,
        };
    } finally {
        detailLoading.value = null;
    }
}

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
    <DashWidget
        widget-id="all-users-compliance"
        title="All users — compliance"
        description="Every user's status at a glance. Search, sort, and expand a row for detail."
        :loading="loading"
        :error="error"
    >
        <template #actions>
            <Input
                v-model="search"
                type="search"
                placeholder="Search name or email…"
                class="h-8 w-56"
                aria-label="Search users"
            />
        </template>

        <div
            v-if="rows.length === 0"
            class="rounded border border-dashed border-border p-3 text-xs text-muted-foreground"
        >
            No users yet.
        </div>

        <div v-else class="max-h-[32rem] overflow-x-auto overflow-y-auto">
            <table class="min-w-full divide-y divide-border text-sm">
                <thead class="bg-muted/40">
                    <tr>
                        <th class="px-3 py-2 text-left font-medium">
                            <button
                                type="button"
                                class="hover:underline"
                                @click="toggleSort('name')"
                            >
                                User {{ sortIndicator('name') }}
                            </button>
                        </th>
                        <th class="px-3 py-2 text-left font-medium">
                            <button
                                type="button"
                                class="hover:underline"
                                @click="toggleSort('status')"
                            >
                                Status {{ sortIndicator('status') }}
                            </button>
                        </th>
                        <th class="px-3 py-2 text-left font-medium">
                            <button
                                type="button"
                                class="hover:underline"
                                @click="toggleSort('overdue')"
                            >
                                Overdue {{ sortIndicator('overdue') }}
                            </button>
                        </th>
                        <th class="px-3 py-2 text-left font-medium">
                            <button
                                type="button"
                                class="hover:underline"
                                @click="toggleSort('due_soon')"
                            >
                                Due soon {{ sortIndicator('due_soon') }}
                            </button>
                        </th>
                        <th class="px-3 py-2 text-left font-medium">Tags</th>
                        <th class="px-3 py-2"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    <template v-for="row in sorted" :key="row.user_id">
                        <tr class="hover:bg-muted/30">
                            <td class="px-3 py-2">
                                <Link
                                    :href="userShow(row.user_id)"
                                    class="font-medium text-primary hover:underline"
                                >
                                    {{ row.name ?? row.email ?? row.user_id }}
                                </Link>
                                <div
                                    v-if="row.email"
                                    class="text-xs text-muted-foreground"
                                >
                                    {{ row.email }}
                                </div>
                            </td>
                            <td class="px-3 py-2">
                                <ComplianceStatusBadge
                                    v-if="row.overall_status !== 'none'"
                                    :status="row.overall_status"
                                    :count="row.counts[row.overall_status]"
                                />
                                <span
                                    v-else
                                    class="text-xs text-muted-foreground"
                                >
                                    No assignments
                                </span>
                            </td>
                            <td class="px-3 py-2">
                                <span
                                    v-if="row.counts.overdue > 0"
                                    class="font-medium text-red-700 dark:text-red-300"
                                >
                                    {{ row.counts.overdue }}
                                </span>
                                <span v-else class="text-muted-foreground"
                                    >0</span
                                >
                            </td>
                            <td class="px-3 py-2">
                                {{ row.counts.due_soon }}
                            </td>
                            <td class="px-3 py-2">
                                <div class="flex flex-wrap gap-1">
                                    <TagPill
                                        v-for="tag in tagsFor(row)"
                                        :key="tag.id"
                                        :tag="tag"
                                        size="sm"
                                    />
                                </div>
                            </td>
                            <td class="px-3 py-2 text-right">
                                <button
                                    type="button"
                                    class="text-xs text-primary hover:underline"
                                    :aria-expanded="expanded === row.user_id"
                                    @click="toggleExpand(row)"
                                >
                                    {{ expanded === row.user_id ? '▲' : '▼' }}
                                </button>
                            </td>
                        </tr>
                        <tr v-if="expanded === row.user_id" class="bg-muted/20">
                            <td colspan="6" class="px-3 py-2">
                                <p
                                    v-if="detailLoading === row.user_id"
                                    class="text-xs text-muted-foreground"
                                >
                                    Loading detail…
                                </p>
                                <p
                                    v-else-if="detailError[row.user_id]"
                                    class="text-xs text-red-700 dark:text-red-300"
                                >
                                    {{ detailError[row.user_id] }}
                                </p>
                                <p
                                    v-else-if="
                                        (detail[row.user_id]?.length ?? 0) === 0
                                    "
                                    class="text-xs text-muted-foreground"
                                >
                                    No assignments.
                                </p>
                                <ul v-else class="space-y-1 text-xs">
                                    <li
                                        v-for="(item, i) in detail[row.user_id]"
                                        :key="i"
                                        class="flex items-center justify-between gap-3"
                                    >
                                        <span
                                            class="flex items-center gap-2 font-medium"
                                        >
                                            <ComplianceStatusBadge
                                                v-if="item.status"
                                                :status="item.status"
                                            />
                                            {{ item.requirement_name ?? '—' }}
                                        </span>
                                        <span class="text-muted-foreground">
                                            <template v-if="item.next_due_date">
                                                due {{ item.next_due_date
                                                }}<template
                                                    v-if="
                                                        item.days_until_due !=
                                                        null
                                                    "
                                                >
                                                    ({{ item.days_until_due }}d)</template
                                                >
                                                ·
                                            </template>
                                            last completed
                                            {{
                                                item.last_completion_date ??
                                                'never'
                                            }}
                                        </span>
                                    </li>
                                </ul>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>
    </DashWidget>
</template>
