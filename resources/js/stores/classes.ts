/*
 * Classes store (Training System) — data relay for the classes list + per-class
 * detail (trainings + roster). Mutations route through here; the org channel's
 * single `ClassChanged` event re-syncs the affected class (and the list).
 */

import axios from 'axios';
import { defineStore } from 'pinia';
import { ref } from 'vue';
import { useRealtime } from '@/composables/useRealtime';
import { realtimeTabId } from '@/echo';

export interface ClassRow {
    id: string;
    name: string;
    scheduled_date: string | null;
    location: string | null;
    instructor: string | null;
    total_hours: string | null;
    status: 'scheduled' | 'completed';
    trainings_count: number;
    enrollments_count: number;
    can_edit: boolean;
    can_delete: boolean;
}

export interface ClassTrainingRow {
    id: string;
    training_id: string | null;
    training_name: string;
    initial_only: boolean;
    repeating: boolean;
    as_needed: boolean;
    std_freq_name: string | null;
    repeat_days: number | null;
    hours: string | null;
}

export interface EnrollmentRow {
    id: string;
    user_id: string;
    user_name: string | null;
    status: 'enrolled' | 'passed' | 'incomplete';
    notes: string | null;
}

export interface ClassDetail {
    id: string;
    name: string;
    scheduled_date: string | null;
    location: string | null;
    instructor: string | null;
    total_hours: string | null;
    notes: string | null;
    status: 'scheduled' | 'completed';
    completion_date: string | null;
    can_edit: boolean;
    trainings: ClassTrainingRow[];
    enrollments: EnrollmentRow[];
}

export interface ClassFormPayload {
    name: string;
    scheduled_date: string;
    location: string | null;
    instructor: string | null;
    total_hours: number | null;
    notes: string | null;
    // Create-only: snapshot these trainings onto the new class.
    training_ids?: string[];
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

export const useClassesStore = defineStore('classes', () => {
    const library = ref<ClassRow[]>([]);
    const loaded = ref(false);
    const detail = ref<Record<string, ClassDetail>>({});
    const subscribedOrgId = ref<string | null>(null);

    async function load(force = false): Promise<void> {
        if (loaded.value && !force) {
            return;
        }

        const { data } = await axios.get<ClassRow[]>('/api/classes', {
            headers: defaultHeaders(),
        });
        library.value = data;
        loaded.value = true;
    }

    async function loadDetail(id: string): Promise<ClassDetail> {
        const { data } = await axios.get<ClassDetail>(`/api/classes/${id}`, {
            headers: defaultHeaders(),
        });
        detail.value = { ...detail.value, [id]: data };

        return data;
    }

    function cache(d: ClassDetail): ClassDetail {
        detail.value = { ...detail.value, [d.id]: d };

        return d;
    }

    async function create(payload: ClassFormPayload): Promise<ClassDetail> {
        const { data } = await axios.post<ClassDetail>(
            '/api/classes',
            payload,
            {
                headers: defaultHeaders(),
            },
        );
        await load(true);

        return cache(data);
    }

    async function update(
        id: string,
        payload: ClassFormPayload,
    ): Promise<ClassDetail> {
        const { data } = await axios.patch<ClassDetail>(
            `/api/classes/${id}`,
            payload,
            { headers: defaultHeaders() },
        );
        await load(true);

        return cache(data);
    }

    async function destroy(id: string): Promise<void> {
        await axios.delete(`/api/classes/${id}`, { headers: defaultHeaders() });
        library.value = library.value.filter((c) => c.id !== id);
        const next = { ...detail.value };
        delete next[id];
        detail.value = next;
    }

    async function attachTraining(
        id: string,
        body: { training_id: string; hours: number | null },
    ): Promise<ClassDetail> {
        const { data } = await axios.post<ClassDetail>(
            `/api/classes/${id}/trainings`,
            body,
            { headers: defaultHeaders() },
        );

        return cache(data);
    }

    async function updateTrainingHours(
        id: string,
        classTrainingId: string,
        hours: number | null,
    ): Promise<ClassDetail> {
        const { data } = await axios.patch<ClassDetail>(
            `/api/classes/${id}/trainings/${classTrainingId}`,
            { hours },
            { headers: defaultHeaders() },
        );

        return cache(data);
    }

    async function detachTraining(
        id: string,
        classTrainingId: string,
    ): Promise<ClassDetail> {
        const { data } = await axios.delete<ClassDetail>(
            `/api/classes/${id}/trainings/${classTrainingId}`,
            { headers: defaultHeaders() },
        );

        return cache(data);
    }

    async function enroll(id: string, userId: string): Promise<ClassDetail> {
        const { data } = await axios.post<ClassDetail>(
            `/api/classes/${id}/enrollments`,
            { user_id: userId },
            { headers: defaultHeaders() },
        );

        return cache(data);
    }

    async function unenroll(
        id: string,
        enrollmentId: string,
    ): Promise<ClassDetail> {
        const { data } = await axios.delete<ClassDetail>(
            `/api/classes/${id}/enrollments/${enrollmentId}`,
            { headers: defaultHeaders() },
        );

        return cache(data);
    }

    async function complete(
        id: string,
        payload: {
            completion_date: string;
            enrollments: {
                id: string;
                status: 'passed' | 'incomplete';
                notes: string | null;
            }[];
        },
    ): Promise<ClassDetail> {
        const { data } = await axios.post<ClassDetail>(
            `/api/classes/${id}/complete`,
            payload,
            { headers: defaultHeaders() },
        );
        await load(true);

        return cache(data);
    }

    function subscribe(orgId: string): void {
        if (subscribedOrgId.value === orgId) {
            return;
        }

        subscribedOrgId.value = orgId;

        const { bind } = useRealtime(`org.${orgId}`);

        // Aggregate event: re-sync the list and any cached detail for the
        // changed class. Self-echoes are filtered by useRealtime.
        bind('ClassChanged', (p: { class_id: string; action: string }) => {
            void load(true);

            if (p.action === 'deleted') {
                const next = { ...detail.value };
                delete next[p.class_id];
                detail.value = next;
            } else if (detail.value[p.class_id]) {
                void loadDetail(p.class_id);
            }
        });
    }

    return {
        library,
        loaded,
        detail,
        load,
        loadDetail,
        create,
        update,
        destroy,
        attachTraining,
        updateTrainingHours,
        detachTraining,
        enroll,
        unenroll,
        complete,
        subscribe,
    };
});
