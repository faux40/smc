import axios from 'axios';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { useRequirementAssignmentsStore } from '@/stores/requirementAssignments';
import { useTrainingAssignmentsStore } from '@/stores/trainingAssignments';
import type {
    AssignmentSourceRow,
    TrainingAssignmentRow,
} from '@/stores/trainingAssignments';
import { useRequirementsStore } from '@/stores/requirements';
import type { RequirementRow } from '@/stores/requirements';

vi.mock('axios');
vi.mock('@/echo', () => ({ realtimeTabId: () => 'test-tab' }));
vi.mock('@/composables/useRealtime', () => ({
    useRealtime: vi.fn(() => ({ bind: vi.fn(), leave: vi.fn() })),
}));

const REQUIREMENT_CLASS = 'App\\Models\\Requirement';

function source(
    overrides: Partial<AssignmentSourceRow> = {},
): AssignmentSourceRow {
    return {
        id: 'src-1',
        sourceable_type: null,
        sourceable_id: null,
        added_at: '2026-01-01T00:00:00.000Z',
        ...overrides,
    };
}

function ta(
    overrides: Partial<TrainingAssignmentRow> & { id: string },
): TrainingAssignmentRow {
    return {
        user_id: 'u1',
        training_id: 't1',
        name: 'Safety Training',
        expires_at: null,
        last_completed_at: null,
        active_sources: [source()],
        can_delete: true,
        ...overrides,
    };
}

function req(
    overrides: Partial<RequirementRow> & { id: string },
): RequirementRow {
    return {
        name: 'Fire Safety',
        description: null,
        elements_count: 1,
        can_edit: true,
        can_delete: true,
        ...overrides,
    };
}

describe('useRequirementAssignmentsStore', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        vi.clearAllMocks();
    });

    // ----------------------------------------------------------------
    // forUser — derived computation
    // ----------------------------------------------------------------

    it('forUser returns empty when user has no TAs', () => {
        const store = useRequirementAssignmentsStore();
        expect(store.forUser('u1')).toHaveLength(0);
    });

    it('forUser returns empty when user TAs have no requirement sources', () => {
        const taStore = useTrainingAssignmentsStore();
        taStore.rows = [
            ta({
                id: 'ta-1',
                user_id: 'u1',
                active_sources: [source({ sourceable_type: null })],
            }),
        ];
        const store = useRequirementAssignmentsStore();
        expect(store.forUser('u1')).toHaveLength(0);
    });

    it('forUser returns one row per unique requirement', () => {
        const taStore = useTrainingAssignmentsStore();
        const reqStore = useRequirementsStore();
        reqStore.library = [req({ id: 'r1', name: 'Fire Safety' })];
        taStore.rows = [
            ta({
                id: 'ta-1',
                user_id: 'u1',
                active_sources: [
                    source({
                        id: 'src-1',
                        sourceable_type: REQUIREMENT_CLASS,
                        sourceable_id: 'r1',
                    }),
                ],
            }),
        ];
        const store = useRequirementAssignmentsStore();
        const result = store.forUser('u1');
        expect(result).toHaveLength(1);
        expect(result[0]).toMatchObject({
            requirement_id: 'r1',
            requirement_name: 'Fire Safety',
            user_id: 'u1',
        });
    });

    it('forUser deduplicates when multiple TAs share the same requirement source', () => {
        const taStore = useTrainingAssignmentsStore();
        const reqStore = useRequirementsStore();
        reqStore.library = [req({ id: 'r1', name: 'Fire Safety' })];
        taStore.rows = [
            ta({
                id: 'ta-1',
                user_id: 'u1',
                training_id: 't1',
                active_sources: [
                    source({
                        sourceable_type: REQUIREMENT_CLASS,
                        sourceable_id: 'r1',
                    }),
                ],
            }),
            ta({
                id: 'ta-2',
                user_id: 'u1',
                training_id: 't2',
                active_sources: [
                    source({
                        sourceable_type: REQUIREMENT_CLASS,
                        sourceable_id: 'r1',
                    }),
                ],
            }),
        ];
        const store = useRequirementAssignmentsStore();
        expect(store.forUser('u1')).toHaveLength(1);
    });

    it('forUser returns multiple rows for distinct requirements', () => {
        const taStore = useTrainingAssignmentsStore();
        const reqStore = useRequirementsStore();
        reqStore.library = [
            req({ id: 'r1', name: 'Fire Safety' }),
            req({ id: 'r2', name: 'First Aid' }),
        ];
        taStore.rows = [
            ta({
                id: 'ta-1',
                user_id: 'u1',
                active_sources: [
                    source({
                        sourceable_type: REQUIREMENT_CLASS,
                        sourceable_id: 'r1',
                    }),
                ],
            }),
            ta({
                id: 'ta-2',
                user_id: 'u1',
                active_sources: [
                    source({
                        sourceable_type: REQUIREMENT_CLASS,
                        sourceable_id: 'r2',
                    }),
                ],
            }),
        ];
        const store = useRequirementAssignmentsStore();
        const result = store.forUser('u1');
        expect(result).toHaveLength(2);
        expect(result.map((r) => r.requirement_id).sort()).toEqual([
            'r1',
            'r2',
        ]);
    });

    it('forUser only returns rows for the requested user', () => {
        const taStore = useTrainingAssignmentsStore();
        const reqStore = useRequirementsStore();
        reqStore.library = [req({ id: 'r1', name: 'Fire Safety' })];
        taStore.rows = [
            ta({
                id: 'ta-1',
                user_id: 'u1',
                active_sources: [
                    source({
                        sourceable_type: REQUIREMENT_CLASS,
                        sourceable_id: 'r1',
                    }),
                ],
            }),
            ta({
                id: 'ta-2',
                user_id: 'u2',
                active_sources: [
                    source({
                        sourceable_type: REQUIREMENT_CLASS,
                        sourceable_id: 'r1',
                    }),
                ],
            }),
        ];
        const store = useRequirementAssignmentsStore();
        expect(store.forUser('u1')).toHaveLength(1);
        expect(store.forUser('u1')[0].user_id).toBe('u1');
    });

    it('forUser uses "Unknown Requirement" when requirement is not in the library', () => {
        const taStore = useTrainingAssignmentsStore();
        taStore.rows = [
            ta({
                id: 'ta-1',
                user_id: 'u1',
                active_sources: [
                    source({
                        sourceable_type: REQUIREMENT_CLASS,
                        sourceable_id: 'r-missing',
                    }),
                ],
            }),
        ];
        const store = useRequirementAssignmentsStore();
        expect(store.forUser('u1')[0].requirement_name).toBe(
            'Unknown Requirement',
        );
    });

    // ----------------------------------------------------------------
    // destroyByRequirement — network + optimistic update
    // ----------------------------------------------------------------

    it('destroyByRequirement calls DELETE /api/training-assignments/by-requirement with correct payload', async () => {
        vi.mocked(axios.delete).mockResolvedValue({
            data: { deleted_ids: [], updated_ids: [] },
        });
        const store = useRequirementAssignmentsStore();
        await store.destroyByRequirement('u1', 'r1');
        expect(axios.delete).toHaveBeenCalledWith(
            '/api/training-assignments/by-requirement',
            expect.objectContaining({
                data: { user_id: 'u1', requirement_id: 'r1' },
            }),
        );
    });

    it('destroyByRequirement removes deleted TAs from the TA store', async () => {
        vi.mocked(axios.delete).mockResolvedValue({
            data: { deleted_ids: ['ta-1'], updated_ids: [] },
        });
        const taStore = useTrainingAssignmentsStore();
        taStore.rows = [
            ta({ id: 'ta-1', user_id: 'u1' }),
            ta({ id: 'ta-2', user_id: 'u1' }),
        ];
        await useRequirementAssignmentsStore().destroyByRequirement('u1', 'r1');
        expect(taStore.rows.map((r) => r.id)).toEqual(['ta-2']);
    });

    it('destroyByRequirement strips requirement sources from updated TAs', async () => {
        vi.mocked(axios.delete).mockResolvedValue({
            data: { deleted_ids: [], updated_ids: ['ta-1'] },
        });
        const taStore = useTrainingAssignmentsStore();
        taStore.rows = [
            ta({
                id: 'ta-1',
                user_id: 'u1',
                active_sources: [
                    source({
                        id: 'src-req',
                        sourceable_type: REQUIREMENT_CLASS,
                        sourceable_id: 'r1',
                    }),
                    source({
                        id: 'src-direct',
                        sourceable_type: null,
                        sourceable_id: null,
                    }),
                ],
            }),
        ];
        await useRequirementAssignmentsStore().destroyByRequirement('u1', 'r1');
        const remaining = taStore.rows[0].active_sources;
        expect(remaining).toHaveLength(1);
        expect(remaining[0].id).toBe('src-direct');
    });

    it('destroyByRequirement returns the deleted_ids and updated_ids from the server', async () => {
        vi.mocked(axios.delete).mockResolvedValue({
            data: { deleted_ids: ['ta-1'], updated_ids: ['ta-2'] },
        });
        const taStore = useTrainingAssignmentsStore();
        taStore.rows = [
            ta({ id: 'ta-1', user_id: 'u1' }),
            ta({ id: 'ta-2', user_id: 'u1' }),
        ];
        const result =
            await useRequirementAssignmentsStore().destroyByRequirement(
                'u1',
                'r1',
            );
        expect(result).toEqual({
            deleted_ids: ['ta-1'],
            updated_ids: ['ta-2'],
        });
    });
});
