<script setup lang="ts">
/*
 * K2 — the manager's "who needs what, and when" view. One consolidated
 * widget over /api/dashboard/needs-action (every TA that is overdue /
 * not started / due soon, server-sorted worst first) replacing the old
 * requirement-flavored DueSoonWidget + training-flavored
 * TrainingDueSoonWidget pair. Grouping, search, and status filtering
 * are client-side.
 */
import { Link } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, onMounted, ref } from 'vue';
import DashWidget from '@/components/dashboard/DashWidget.vue';
import { Input } from '@/components/ui/input';
import { realtimeTabId } from '@/echo';
import ComplianceStatusBadge from '@/pages/users/Partials/ComplianceStatusBadge.vue';
import type { ComplianceStatus } from '@/pages/users/Partials/ComplianceStatusBadge.vue';
import { show as userShow } from '@/routes/users';

interface SourceChip {
    type: 'direct' | 'requirement';
    id: string | null;
    name: string | null;
}

interface NeedsActionRow {
    id: string;
    user_id: string;
    user_name: string | null;
    training_id: string;
    training_name: string;
    status: ComplianceStatus;
    expires_at: string | null;
    days_until_due: number | null;
    sources: SourceChip[];
}

type GroupBy = 'user' | 'training';
type StatusFilter = 'all' | 'overdue' | 'due_soon' | 'not_started';

const rows = ref<NeedsActionRow[]>([]);
const loading = ref(true);
const error = ref<string | null>(null);

const groupBy = ref<GroupBy>('user');
const statusFilter = ref<StatusFilter>('all');
const search = ref('');

onMounted(async () => {
    try {
        const { data } = await axios.get<NeedsActionRow[]>(
            '/api/dashboard/needs-action',
            { headers: defaultHeaders() },
        );
        rows.value = Array.isArray(data) ? data : [];
    } catch (e) {
        error.value = (e as Error).message;
    } finally {
        loading.value = false;
    }
});

const filtered = computed(() => {
    const q = search.value.trim().toLowerCase();

    return rows.value.filter((row) => {
        if (statusFilter.value !== 'all' && row.status !== statusFilter.value) {
            return false;
        }

        if (!q) {
            return true;
        }

        return (
            (row.user_name ?? '').toLowerCase().includes(q) ||
            row.training_name.toLowerCase().includes(q)
        );
    });
});

interface Group {
    key: string;
    name: string;
    rows: NeedsActionRow[];
}

// First-seen order: the server sorts worst-first, so the group containing
// the worst row naturally leads.
const groups = computed<Group[]>(() => {
    const map = new Map<string, Group>();

    for (const row of filtered.value) {
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
        widget-id="needs-action"
        title="Needs action"
        description="Every overdue, never-started, or expiring training — who needs what, and when."
        :loading="loading"
        :error="error"
    >
        <template #actions>
            <div class="flex flex-wrap items-center gap-2">
                <Input
                    v-model="search"
                    type="search"
                    placeholder="Search user or training…"
                    class="h-8 w-48"
                    aria-label="Search needs-action rows"
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
            v-if="rows.length === 0"
            class="rounded border border-dashed border-border p-3 text-xs text-muted-foreground"
        >
            Nothing needs action — everyone is current. 🎉
        </div>

        <div
            v-else-if="filtered.length === 0"
            class="rounded border border-dashed border-border p-3 text-xs text-muted-foreground"
        >
            No rows match the filters.
        </div>

        <div v-else class="max-h-[32rem] space-y-4 overflow-y-auto pr-1">
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
                    </li>
                </ul>
            </section>
        </div>
    </DashWidget>
</template>
