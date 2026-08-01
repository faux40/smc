/*
 * Training assignments store — training-as-atom compliance records.
 *
 * Each row represents one (user, training) assignment. A row may have
 * multiple sources (direct or via requirement) tracked in active_sources.
 * Pre-computed expires_at and last_completed_at come from the server and
 * are kept in sync by the CompletionObserver + RecalculateTrainingStatus.
 *
 * Reverb subscription handles peer-tab creation and deletion; the acting
 * tab's optimistic upsert/remove covers the local action immediately.
 *
 * Phase C ships the store; Phase D wires it into the Assignments UI.
 */

import axios from 'axios';
import { defineStore } from 'pinia';
import { computed, ref } from 'vue';
import type {
    ServerTableQuery,
    ServerTableResponse,
} from '@/composables/useServerTable';
import { useRealtime } from '@/composables/useRealtime';
import { realtimeTabId } from '@/echo';

export interface AssignmentSourceRow {
    id: string;
    sourceable_type: string | null;
    sourceable_id: string | null;
    added_at: string;
}

export interface TrainingAssignmentRow {
    id: string;
    user_id: string;
    training_id: string;
    name: string;
    expires_at: string | null;
    last_completed_at: string | null;
    active_sources: AssignmentSourceRow[];
    can_delete: boolean;
}

export interface TrainingAssignmentFilter {
    user_id?: string;
    training_id?: string;
}

/** One row of the server-paged by-user assignments table. */
export interface AssignmentUserRow {
    user_id: string;
    name: string | null;
    email: string | null;
    employee_number: string | null;
    job_title: string | null;
    department: string | null;
    location: string | null;
    supervisor_name: string | null;
    tag_ids: string[];
    assignments_count: number;
    assignments: TrainingAssignmentRow[];
}

/** Query for the by-user table: server-table params + the page's filters. */
export type AssignmentsByUserQuery = ServerTableQuery & {
    user_q?: string;
    requirements?: string[];
    req_mode?: string;
    tags?: string[];
    tags_mode?: string;
};

/** Result of a single manual "Remind" (F10). */
export interface RemindResult {
    sent: boolean;
    status: string;
    supervisor_notified: boolean;
}

/** Result of a "Remind selected" bulk nudge (F10). */
export interface RemindBulkResult {
    reminded_count: number;
    skipped_count: number;
    supervisors_notified_count: number;
}

interface BroadcastPayload {
    id: string;
    user_id?: string;
    training_id?: string;
    name?: string;
    expires_at?: string | null;
    last_completed_at?: string | null;
    active_sources?: AssignmentSourceRow[];
    origin_tab?: string | null;
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

function filterKey(filter: TrainingAssignmentFilter): string {
    return `u:${filter.user_id ?? ''}|t:${filter.training_id ?? ''}`;
}

export const useTrainingAssignmentsStore = defineStore(
    'trainingAssignments',
    () => {
        const rows = ref<TrainingAssignmentRow[]>([]);
        const loadedFilters = ref<Set<string>>(new Set());
        const subscribedOrgId = ref<string | null>(null);

        // Bumped on any mutation/broadcast so the server-paged by-user table
        // re-pulls its current page.
        const revision = ref(0);

        /**
         * Server-paged by-user assignments ({data, meta}). The Index drives it
         * via useServerTable; does not touch the `rows` cache.
         */
        async function fetchByUser(
            params: AssignmentsByUserQuery,
        ): Promise<ServerTableResponse<AssignmentUserRow>> {
            const query: Record<string, string | number | string[]> = {
                page: params.page,
                per_page: params.per_page,
                dir: params.dir,
            };

            if (params.sort) {
                query.sort = params.sort;
            }
            if (params.q) {
                query.q = params.q;
            }
            if (params.user_q) {
                query.user_q = params.user_q;
            }
            if (params.requirements && params.requirements.length > 0) {
                query.requirements = params.requirements;
                query.req_mode = params.req_mode ?? 'or';
            }
            if (params.tags && params.tags.length > 0) {
                query.tags = params.tags;
                query.tags_mode = params.tags_mode ?? 'and';
            }

            const { data } = await axios.get<
                ServerTableResponse<AssignmentUserRow>
            >('/api/training-assignments/by-user', {
                headers: defaultHeaders(),
                params: query,
            });

            return data;
        }

        const forUser = computed(
            () => (userId: string) =>
                rows.value.filter((r) => r.user_id === userId),
        );

        const forTraining = computed(
            () => (trainingId: string) =>
                rows.value.filter((r) => r.training_id === trainingId),
        );

        function upsert(row: TrainingAssignmentRow): void {
            const idx = rows.value.findIndex((r) => r.id === row.id);

            if (idx === -1) {
                rows.value = [...rows.value, row];
            } else {
                const next = rows.value.slice();
                next[idx] = { ...next[idx], ...row };
                rows.value = next;
            }
        }

        async function loadFor(
            filter: TrainingAssignmentFilter = {},
            opts: { force?: boolean } = {},
        ): Promise<void> {
            const key = filterKey(filter);

            // F7: completing a training recalculates status/expires_at
            // server-side with no broadcast (see CompletionObserver) — a
            // caller that just recorded one needs to bypass the cache for a
            // filter it already loaded, rather than living with stale rows.
            if (loadedFilters.value.has(key) && !opts.force) {
                return;
            }

            const params: Record<string, string> = {};

            if (filter.user_id) {
                params.user_id = filter.user_id;
            }
            if (filter.training_id) {
                params.training_id = filter.training_id;
            }

            const { data } = await axios.get<TrainingAssignmentRow[]>(
                '/api/training-assignments',
                { headers: defaultHeaders(), params },
            );
            data.forEach((r) => upsert(r));
            loadedFilters.value = new Set([...loadedFilters.value, key]);
        }

        async function assignDirect(
            userId: string,
            trainingId: string,
        ): Promise<TrainingAssignmentRow[]> {
            const { data } = await axios.post<TrainingAssignmentRow[]>(
                '/api/training-assignments',
                {
                    source_type: 'direct',
                    user_id: userId,
                    training_id: trainingId,
                },
                { headers: defaultHeaders() },
            );
            data.forEach((r) => upsert(r));
            revision.value++;

            return data;
        }

        async function assignFromRequirement(
            userId: string,
            requirementId: string,
        ): Promise<TrainingAssignmentRow[]> {
            const { data } = await axios.post<TrainingAssignmentRow[]>(
                '/api/training-assignments',
                {
                    source_type: 'requirement',
                    user_id: userId,
                    requirement_id: requirementId,
                },
                { headers: defaultHeaders() },
            );
            data.forEach((r) => upsert(r));
            revision.value++;

            return data;
        }

        async function destroy(id: string): Promise<void> {
            await axios.delete(`/api/training-assignments/${id}`, {
                headers: defaultHeaders(),
            });
            rows.value = rows.value.filter((r) => r.id !== id);
            revision.value++;
        }

        async function breakFromRequirement(
            taId: string,
            requirementId: string,
        ): Promise<{ deleted_id: string | null; updated_ids: string[] }> {
            const { data } = await axios.delete<{
                deleted_id: string | null;
                updated_ids: string[];
            }>(`/api/training-assignments/${taId}/from-requirement`, {
                data: { requirement_id: requirementId },
                headers: defaultHeaders(),
            });

            if (data.deleted_id) {
                rows.value = rows.value.filter((r) => r.id !== data.deleted_id);
            }

            const REQUIREMENT_CLASS = 'App\\Models\\Requirement';
            rows.value = rows.value.map((ta) => {
                if (!data.updated_ids.includes(ta.id)) return ta;
                return {
                    ...ta,
                    active_sources: ta.active_sources.filter(
                        (s) =>
                            !(
                                s.sourceable_type === REQUIREMENT_CLASS &&
                                s.sourceable_id === requirementId
                            ),
                    ),
                };
            });
            revision.value++;

            return data;
        }

        // F10 — nudge the person about one assignment now. Re-sends the
        // notification matching its current status; overdue reminders CC the
        // supervisor server-side. Rejects (422) when there's nothing to remind.
        async function remind(taId: string): Promise<RemindResult> {
            const { data } = await axios.post<RemindResult>(
                `/api/assignments/${taId}/remind`,
                {},
                { headers: defaultHeaders() },
            );

            return data;
        }

        // F10 — "Remind selected": one org-scoped call over many assignments.
        async function remindBulk(taIds: string[]): Promise<RemindBulkResult> {
            const { data } = await axios.post<RemindBulkResult>(
                '/api/assignments/remind-bulk',
                { training_assignment_ids: taIds },
                { headers: defaultHeaders() },
            );

            return data;
        }

        function subscribe(orgId: string): void {
            if (subscribedOrgId.value === orgId) {
                return;
            }

            subscribedOrgId.value = orgId;

            const { bind } = useRealtime(`org.${orgId}`, 'private', {
                persist: true,
            });

            bind('TrainingAssignmentCreated', (payload: BroadcastPayload) => {
                const existing = rows.value.find((r) => r.id === payload.id);
                upsert({
                    id: payload.id,
                    user_id: payload.user_id ?? '',
                    training_id: payload.training_id ?? '',
                    name: payload.name ?? '',
                    expires_at: payload.expires_at ?? null,
                    last_completed_at: payload.last_completed_at ?? null,
                    active_sources: payload.active_sources ?? [],
                    can_delete: existing?.can_delete ?? false,
                });
                revision.value++;
            });

            bind('TrainingAssignmentDeleted', (payload: BroadcastPayload) => {
                rows.value = rows.value.filter((r) => r.id !== payload.id);
                revision.value++;
            });

            // A bulk assignment carries no per-row payload (it can touch
            // hundreds of TAs across pages this tab may not hold) — just bump
            // the revision so watchers debounce-refetch the current page, the
            // same path the single-assign event drives.
            bind('TrainingAssignmentsBulkChanged', () => {
                revision.value++;
            });
        }

        return {
            rows,
            revision,
            loadedFilters,
            forUser,
            forTraining,
            upsert,
            loadFor,
            fetchByUser,
            assignDirect,
            assignFromRequirement,
            destroy,
            breakFromRequirement,
            remind,
            remindBulk,
            subscribe,
        };
    },
);
