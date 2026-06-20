/*
 * Classes store (Training System) — data relay for the classes list + per-class
 * detail (trainings + roster). Mutations route through here; the org channel's
 * single `ClassChanged` event re-syncs the affected class (and the list).
 */

import axios from 'axios';
import { defineStore } from 'pinia';
import { ref } from 'vue';
import { useRealtime } from '@/composables/useRealtime';
import type {
    ServerTableQuery,
    ServerTableResponse,
} from '@/composables/useServerTable';
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

export interface TopicCredit {
    completion_id: string;
    user_id: string;
    user_name: string | null;
    cert_id: string | null;
    expire_date: string | null;
    hours: number | null;
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
    /**
     * Per-class certificate overrides — seeded from the training snapshot at
     * attach time, editable for this class while it's scheduled. cert_text is
     * Markdown (rendered on the certificate).
     */
    cert_title: string | null;
    cert_text: string | null;
    cert_code: string | null;
    /** M3 — who earned this topic's credit (populated after close-out). */
    credits: TopicCredit[];
}

/** The editable per-class certificate fields (see ClassTrainingRow). */
export interface ClassCertPayload {
    cert_title: string | null;
    cert_text: string | null;
    cert_code: string | null;
}

export interface EnrollmentRow {
    id: string;
    user_id: string;
    user_name: string | null;
    user_email: string | null;
    status: 'enrolled' | 'passed' | 'partial' | 'incomplete';
    notes: string | null;
    // Class topics this user already holds a (live) completion for — drives
    // the close-out modal's per-topic defaults so re-completing preserves
    // existing credit.
    credited_training_ids: string[];
}

export interface ClassDetail {
    id: string;
    name: string;
    scheduled_date: string | null;
    start_time: string | null;
    end_time: string | null;
    location: string | null;
    address: string | null;
    instructor: string | null;
    show_signature: boolean;
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
    start_time: string | null;
    end_time: string | null;
    location: string | null;
    address: string | null;
    instructor: string | null;
    show_signature: boolean;
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
    const detail = ref<Record<string, ClassDetail>>({});
    const subscribedOrgId = ref<string | null>(null);
    // Bumped on every ClassChanged broadcast — the paged Index watches it and
    // refetches its current page.
    const revision = ref(0);

    /**
     * Server-paged fetch for the classes table (paged {data, meta} contract).
     * Does not touch the cached library — the Index drives it via
     * useServerTable and renders the returned page.
     */
    async function fetchPage(
        params: ServerTableQuery,
    ): Promise<ServerTableResponse<ClassRow>> {
        const query: Record<string, string | number> = {
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

        const { data } = await axios.get<ServerTableResponse<ClassRow>>(
            '/api/classes',
            { headers: defaultHeaders(), params: query },
        );

        return data;
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

        return cache(data);
    }

    async function destroy(id: string): Promise<void> {
        await axios.delete(`/api/classes/${id}`, { headers: defaultHeaders() });
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

    /** Edit a topic's per-class certificate fields (title / text / code). */
    async function updateTrainingCert(
        id: string,
        classTrainingId: string,
        cert: ClassCertPayload,
    ): Promise<ClassDetail> {
        const { data } = await axios.patch<ClassDetail>(
            `/api/classes/${id}/trainings/${classTrainingId}`,
            cert,
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

    /**
     * Apply a whole roster diff in one request: `enroll` is a list of
     * user-ids (idempotent server-side), `unenroll` a list of enrollment-ids.
     */
    async function bulkEnroll(
        id: string,
        diff: { enroll: string[]; unenroll: string[] },
    ): Promise<ClassDetail> {
        const { data } = await axios.post<ClassDetail>(
            `/api/classes/${id}/enrollments/bulk`,
            diff,
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
                notes: string | null;
                results: { class_training_id: string; passed: boolean }[];
            }[];
        },
    ): Promise<ClassDetail> {
        const { data } = await axios.post<ClassDetail>(
            `/api/classes/${id}/complete`,
            payload,
            { headers: defaultHeaders() },
        );

        return cache(data);
    }

    /**
     * Re-open a completed class for editing. De-issues the certs it generated
     * (server-side) and unlocks the class back to `scheduled`.
     */
    async function reopen(id: string): Promise<ClassDetail> {
        const { data } = await axios.post<ClassDetail>(
            `/api/classes/${id}/reopen`,
            {},
            { headers: defaultHeaders() },
        );

        return cache(data);
    }

    function subscribe(orgId: string): void {
        if (subscribedOrgId.value === orgId) {
            return;
        }

        subscribedOrgId.value = orgId;

        const { bind } = useRealtime(`org.${orgId}`);

        // Aggregate event: nudge the paged list to refetch and re-sync any
        // cached detail for the changed class. Self-echoes are filtered by
        // useRealtime.
        bind('ClassChanged', (p: { class_id: string; action: string }) => {
            revision.value++;

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
        detail,
        revision,
        fetchPage,
        loadDetail,
        create,
        update,
        destroy,
        attachTraining,
        updateTrainingHours,
        updateTrainingCert,
        detachTraining,
        enroll,
        unenroll,
        bulkEnroll,
        complete,
        reopen,
        subscribe,
    };
});
