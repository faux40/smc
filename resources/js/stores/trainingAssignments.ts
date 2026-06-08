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

interface BroadcastPayload {
    id: string;
    user_id?: string;
    training_id?: string;
    name?: string;
    expires_at?: string | null;
    last_completed_at?: string | null;
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
        ): Promise<void> {
            const key = filterKey(filter);

            if (loadedFilters.value.has(key)) {
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
                { source_type: 'direct', user_id: userId, training_id: trainingId },
                { headers: defaultHeaders() },
            );
            data.forEach((r) => upsert(r));

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

            return data;
        }

        async function destroy(id: string): Promise<void> {
            await axios.delete(`/api/training-assignments/${id}`, {
                headers: defaultHeaders(),
            });
            rows.value = rows.value.filter((r) => r.id !== id);
        }

        function subscribe(orgId: string): void {
            if (subscribedOrgId.value === orgId) {
                return;
            }

            subscribedOrgId.value = orgId;

            const { bind } = useRealtime(`org.${orgId}`);

            bind('TrainingAssignmentCreated', (payload: BroadcastPayload) => {
                upsert({
                    id: payload.id,
                    user_id: payload.user_id ?? '',
                    training_id: payload.training_id ?? '',
                    name: payload.name ?? '',
                    expires_at: payload.expires_at ?? null,
                    last_completed_at: payload.last_completed_at ?? null,
                    active_sources: [],
                    can_delete: false,
                });
            });

            bind('TrainingAssignmentDeleted', (payload: BroadcastPayload) => {
                rows.value = rows.value.filter((r) => r.id !== payload.id);
            });
        }

        return {
            rows,
            loadedFilters,
            forUser,
            forTraining,
            upsert,
            loadFor,
            assignDirect,
            assignFromRequirement,
            destroy,
            subscribe,
        };
    },
);
