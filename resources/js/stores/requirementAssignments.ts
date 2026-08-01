import axios from 'axios';
import { defineStore } from 'pinia';
import { computed } from 'vue';
import { realtimeTabId } from '@/echo';
import { useRequirementsStore } from '@/stores/requirements';
import { useTrainingAssignmentsStore } from '@/stores/trainingAssignments';

export interface RequirementAssignmentRow {
    requirement_id: string;
    requirement_name: string;
    user_id: string;
}

const REQUIREMENT_CLASS = 'App\\Models\\Requirement';

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

export const useRequirementAssignmentsStore = defineStore(
    'requirementAssignments',
    () => {
        const taStore = useTrainingAssignmentsStore();
        const requirementsStore = useRequirementsStore();

        /**
         * Returns unique requirement assignments for a given user by aggregating
         * active_sources across all TrainingAssignment rows in the TA store.
         */
        const forUser = computed(
            () =>
                (userId: string): RequirementAssignmentRow[] => {
                    const seen = new Set<string>();
                    const result: RequirementAssignmentRow[] = [];

                    for (const ta of taStore.rows) {
                        if (ta.user_id !== userId) continue;
                        for (const source of ta.active_sources) {
                            if (
                                source.sourceable_type === REQUIREMENT_CLASS &&
                                source.sourceable_id !== null &&
                                !seen.has(source.sourceable_id)
                            ) {
                                seen.add(source.sourceable_id);
                                const req = requirementsStore.library.find(
                                    (r) => r.id === source.sourceable_id,
                                );
                                result.push({
                                    requirement_id: source.sourceable_id,
                                    requirement_name:
                                        req?.name ?? 'Unknown Requirement',
                                    user_id: userId,
                                });
                            }
                        }
                    }

                    return result;
                },
        );

        async function destroyByRequirement(
            userId: string,
            requirementId: string,
        ): Promise<{ deleted_ids: string[]; updated_ids: string[] }> {
            const { data } = await axios.delete<{
                deleted_ids: string[];
                updated_ids: string[];
            }>('/api/training-assignments/by-requirement', {
                data: { user_id: userId, requirement_id: requirementId },
                headers: defaultHeaders(),
            });

            // Remove TAs the server fully pruned.
            taStore.rows = taStore.rows.filter(
                (r) => !data.deleted_ids.includes(r.id),
            );

            // Strip the requirement source from TAs the server kept (another source still covers them).
            taStore.rows = taStore.rows.map((ta) => {
                if (ta.user_id !== userId) return ta;
                const hasTargetSource = ta.active_sources.some(
                    (s) =>
                        s.sourceable_type === REQUIREMENT_CLASS &&
                        s.sourceable_id === requirementId,
                );
                if (!hasTargetSource) return ta;
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

            return data;
        }

        return { forUser, destroyByRequirement };
    },
);
