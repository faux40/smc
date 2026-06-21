<script setup lang="ts">
/*
 * Full-width all-users compliance list (replaces the top-N overdue widget).
 * One row per org user with per-status counts + an overall status badge +
 * tag chips. Server-paged (search / sort / paging all round-trip), with a
 * lazy drill-down: expanding a row fetches that user's full compliance
 * breakdown from /api/users/{id}/training-compliance on demand.
 */
import { Link } from '@inertiajs/vue3';
import axios from 'axios';
import { onMounted, ref } from 'vue';
import DashWidget from '@/components/dashboard/DashWidget.vue';
import Pagination from '@/components/Pagination.vue';
import TagPill from '@/components/TagPill.vue';
import { Input } from '@/components/ui/input';
import { useServerTable } from '@/composables/useServerTable';
import type { ServerTableResponse } from '@/composables/useServerTable';
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

// Mirrors the J3 training-compliance row — the fields the drill-down shows.
interface DetailItem {
    training_name?: string | null;
    status?: ComplianceStatus;
    expires_at?: string | null;
    last_completed_at?: string | null;
    days_until_due?: number | null;
    sources?: Array<{
        type: 'direct' | 'requirement';
        id: string | null;
        name: string | null;
    }>;
}

type SortKey = 'name' | 'status' | 'overdue' | 'due_soon';

const tagsStore = useTagsStore();
const loading = ref(true);
const error = ref<string | null>(null);
const search = ref('');

// Drill-down group ordering — worst first.
const DETAIL_GROUP_ORDER = [
    'overdue',
    'due_soon',
    'not_started',
    'current',
    'as_needed',
] as const;

// Server-paged: search / sort / paging all run on the server.
const table = useServerTable<UserRow>(
    async (params) => {
        const { data } = await axios.get<ServerTableResponse<UserRow>>(
            '/api/dashboard/users-compliance',
            { headers: defaultHeaders(), params },
        );

        return data;
    },
    { perPage: 25, sort: 'overdue', dir: 'desc' },
);

function onSearch(value: string | number): void {
    search.value = String(value);
    table.setQuery(search.value);
}

function sortIndicator(key: SortKey): string {
    if (table.sort.value !== key) {
        return '';
    }

    return table.dir.value === 'asc' ? '▲' : '▼';
}

// Drill-down state, keyed by user id.
const expanded = ref<string | null>(null);
const detail = ref<Record<string, DetailItem[]>>({});
const detailLoading = ref<string | null>(null);
const detailError = ref<Record<string, string>>({});

onMounted(async () => {
    try {
        await Promise.all([table.fetchPage(), tagsStore.loadLibrary()]);
    } catch (e) {
        error.value = (e as Error).message;
    } finally {
        loading.value = false;
    }
});

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
        }>(`/api/users/${row.user_id}/training-compliance`, {
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
                :model-value="search"
                type="search"
                placeholder="Search name or email…"
                class="h-8 w-56"
                aria-label="Search users"
                @update:model-value="onSearch"
            />
        </template>

        <div
            v-if="table.total.value === 0"
            class="rounded border border-dashed border-border p-3 text-xs text-muted-foreground"
        >
            No users match the current search.
        </div>

        <template v-else>
            <div class="max-h-[32rem] overflow-x-auto overflow-y-auto">
                <table class="min-w-full divide-y divide-border text-sm">
                    <thead class="bg-muted/40">
                        <tr>
                            <th class="px-3 py-2 text-left font-medium">
                                <button
                                    type="button"
                                    class="hover:underline"
                                    @click="table.setSort('name')"
                                >
                                    User {{ sortIndicator('name') }}
                                </button>
                            </th>
                            <th class="px-3 py-2 text-left font-medium">
                                <button
                                    type="button"
                                    class="hover:underline"
                                    @click="table.setSort('status')"
                                >
                                    Status {{ sortIndicator('status') }}
                                </button>
                            </th>
                            <th class="px-3 py-2 text-left font-medium">
                                <button
                                    type="button"
                                    class="hover:underline"
                                    @click="table.setSort('overdue')"
                                >
                                    Overdue {{ sortIndicator('overdue') }}
                                </button>
                            </th>
                            <th class="px-3 py-2 text-left font-medium">
                                <button
                                    type="button"
                                    class="hover:underline"
                                    @click="table.setSort('due_soon')"
                                >
                                    Due soon {{ sortIndicator('due_soon') }}
                                </button>
                            </th>
                            <th class="px-3 py-2 text-left font-medium">Tags</th>
                            <th class="px-3 py-2"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        <template
                            v-for="row in table.rows.value"
                            :key="row.user_id"
                        >
                            <tr class="hover:bg-muted/30">
                                <td class="px-3 py-2">
                                    <Link
                                        :href="userShow(row.user_id)"
                                        class="font-medium text-primary hover:underline"
                                    >
                                        {{
                                            row.name ?? row.email ?? row.user_id
                                        }}
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
                                        {{
                                            expanded === row.user_id ? '▲' : '▼'
                                        }}
                                    </button>
                                </td>
                            </tr>
                            <tr
                                v-if="expanded === row.user_id"
                                class="bg-muted/20"
                            >
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
                                            (detail[row.user_id]?.length ?? 0) ===
                                            0
                                        "
                                        class="text-xs text-muted-foreground"
                                    >
                                        No assignments.
                                    </p>
                                    <ul v-else class="space-y-1 text-xs">
                                        <li
                                            v-for="(item, i) in detail[
                                                row.user_id
                                            ]"
                                            :key="i"
                                            class="flex flex-wrap items-center justify-between gap-x-3 gap-y-1"
                                        >
                                            <span
                                                class="flex items-center gap-2 font-medium"
                                            >
                                                <ComplianceStatusBadge
                                                    v-if="item.status"
                                                    :status="item.status"
                                                />
                                                {{ item.training_name ?? '—' }}
                                                <span
                                                    v-for="(chip, ci) in item.sources ??
                                                    []"
                                                    :key="ci"
                                                    class="rounded-full border border-border px-1.5 py-0.5 font-normal text-muted-foreground"
                                                >
                                                    {{
                                                        chip.type ===
                                                        'requirement'
                                                            ? (chip.name ??
                                                              'Requirement')
                                                            : 'Direct'
                                                    }}
                                                </span>
                                            </span>
                                            <span class="text-muted-foreground">
                                                <template v-if="item.expires_at">
                                                    due {{ item.expires_at
                                                    }}<template
                                                        v-if="
                                                            item.days_until_due !=
                                                            null
                                                        "
                                                    >
                                                        ({{
                                                            item.days_until_due
                                                        }}d)</template
                                                    >
                                                    ·
                                                </template>
                                                last completed
                                                {{
                                                    item.last_completed_at ??
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
    </DashWidget>
</template>
