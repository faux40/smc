/*
 * Merge data store (Phase D1) — the org's document merge-field
 * definitions (system + org) and per-variation values.
 *
 * Owns both /api/merge-fields and /api/merge-values: the Document data
 * page always uses them together, and the resolution ladder needs both.
 * `resolvedFor` mirrors the backend MergeValueResolver exactly —
 * both-match > location-only > department-only > org default.
 *
 * Realtime is coarse: MergeFields/ValuesChanged carry no row payload,
 * peer tabs just refetch (self-echo filtered by origin_tab).
 */

import axios from 'axios';
import { defineStore } from 'pinia';
import { computed, ref } from 'vue';
import { useRealtime } from '@/composables/useRealtime';
import { realtimeTabId } from '@/echo';

export type MergeFieldType = 'text' | 'multiline' | 'date' | 'list';

export interface MergeFieldRow {
    id: string;
    key: string;
    label: string;
    type: MergeFieldType;
    field_group: string | null;
    help: string | null;
    seq: number;
    draft: boolean;
    is_system: boolean;
    can_edit: boolean;
    can_delete: boolean;
}

export type MergeValueContent = string | string[] | null;

export interface MergeValueRow {
    id: string;
    merge_field_id: string;
    location: string;
    department: string;
    value: MergeValueContent;
}

export interface MergeFieldPayload {
    key: string;
    label: string;
    type: MergeFieldType;
    field_group: string | null;
    help: string | null;
    seq?: number;
}

/** Where a resolved value came from — drives the "inherited" hint. */
export type ResolvedSource =
    | 'exact'
    | 'location'
    | 'department'
    | 'default'
    | null;

export interface ResolvedValue {
    value: MergeValueContent;
    source: ResolvedSource;
}

export interface FieldGroup {
    group: string | null;
    fields: MergeFieldRow[];
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

export const useMergeDataStore = defineStore('mergeData', () => {
    const fields = ref<MergeFieldRow[]>([]);
    const values = ref<MergeValueRow[]>([]);
    const loaded = ref(false);
    const subscribedOrgId = ref<string | null>(null);

    async function fetchAll(): Promise<void> {
        const [fieldsRes, valuesRes] = await Promise.all([
            axios.get<MergeFieldRow[]>('/api/merge-fields', {
                headers: defaultHeaders(),
            }),
            axios.get<MergeValueRow[]>('/api/merge-values', {
                headers: defaultHeaders(),
            }),
        ]);
        fields.value = fieldsRes.data;
        values.value = valuesRes.data;
    }

    async function load(): Promise<void> {
        if (loaded.value) {
            return;
        }

        await fetchAll();
        loaded.value = true;
    }

    async function reload(): Promise<void> {
        await fetchAll();
        loaded.value = true;
    }

    // ---- field definitions (Admin+) --------------------------------

    async function createField(
        payload: MergeFieldPayload,
    ): Promise<MergeFieldRow> {
        const { data } = await axios.post<MergeFieldRow>(
            '/api/merge-fields',
            payload,
            {
                headers: defaultHeaders(),
            },
        );
        fields.value = [...fields.value, data];

        return data;
    }

    async function updateField(
        id: string,
        payload: MergeFieldPayload,
    ): Promise<void> {
        const { data } = await axios.patch<MergeFieldRow>(
            `/api/merge-fields/${id}`,
            payload,
            {
                headers: defaultHeaders(),
            },
        );
        fields.value = fields.value.map((f) => (f.id === id ? data : f));
    }

    async function destroyField(id: string): Promise<void> {
        await axios.delete(`/api/merge-fields/${id}`, {
            headers: defaultHeaders(),
        });
        fields.value = fields.value.filter((f) => f.id !== id);
        // The backend clears the field's values with it.
        values.value = values.value.filter((v) => v.merge_field_id !== id);
    }

    // ---- values (Manager+) ------------------------------------------

    async function setValue(
        fieldId: string,
        location: string,
        department: string,
        value: MergeValueContent,
    ): Promise<void> {
        const { data } = await axios.put<MergeValueRow>(
            '/api/merge-values',
            { merge_field_id: fieldId, location, department, value },
            { headers: defaultHeaders() },
        );
        const existing = values.value.some(
            (v) =>
                v.merge_field_id === fieldId &&
                v.location === location &&
                v.department === department,
        );
        values.value = existing
            ? values.value.map((v) =>
                  v.merge_field_id === fieldId &&
                  v.location === location &&
                  v.department === department
                      ? data
                      : v,
              )
            : [...values.value, data];
    }

    async function clearValue(id: string): Promise<void> {
        await axios.delete(`/api/merge-values/${id}`, {
            headers: defaultHeaders(),
        });
        values.value = values.value.filter((v) => v.id !== id);
    }

    // ---- lookups ------------------------------------------------------

    /** The exact variation row (what the input edits), if one exists. */
    function rowFor(
        fieldId: string,
        location: string,
        department: string,
    ): MergeValueRow | undefined {
        return values.value.find(
            (v) =>
                v.merge_field_id === fieldId &&
                v.location === location &&
                v.department === department,
        );
    }

    /**
     * Mirror of the backend ladder. `source` tells the UI whether the
     * shown value belongs to this variation ('exact') or is inherited.
     */
    function resolvedFor(
        fieldId: string,
        location: string,
        department: string,
    ): ResolvedValue {
        const exact = rowFor(fieldId, location, department);

        if (exact) {
            return { value: exact.value, source: 'exact' };
        }

        if (location !== '' && department !== '') {
            const byLocation = rowFor(fieldId, location, '');

            if (byLocation) {
                return { value: byLocation.value, source: 'location' };
            }
        }

        if (department !== '') {
            const byDepartment = rowFor(fieldId, '', department);

            if (byDepartment) {
                return { value: byDepartment.value, source: 'department' };
            }
        }

        if (location !== '' || department !== '') {
            const fallback = rowFor(fieldId, '', '');

            if (fallback) {
                return { value: fallback.value, source: 'default' };
            }
        }

        return { value: null, source: null };
    }

    /**
     * Template-completeness check: which of a template's placeholder
     * keys have NO resolvable value for the given variation. Keys that
     * aren't registered fields (generation-time computed values, legacy
     * aliases) are not "missing" — the generator handles those itself.
     */
    function missingKeysFor(
        placeholders: string[],
        location: string,
        department: string,
    ): string[] {
        return placeholders.filter((key) => {
            const field = fields.value.find((f) => f.key === key);

            return (
                field !== undefined &&
                resolvedFor(field.id, location, department).source === null
            );
        });
    }

    /** Fields bucketed by field_group, both in server order (ungrouped last). */
    const groupedFields = computed<FieldGroup[]>(() => {
        const buckets: FieldGroup[] = [];

        for (const f of fields.value) {
            const bucket = buckets.find((b) => b.group === f.field_group);

            if (bucket) {
                bucket.fields.push(f);
            } else {
                buckets.push({ group: f.field_group, fields: [f] });
            }
        }

        return buckets;
    });

    // ---- realtime ------------------------------------------------------

    function subscribe(orgId: string): void {
        if (subscribedOrgId.value === orgId) {
            return;
        }

        subscribedOrgId.value = orgId;

        const { bind } = useRealtime(`org.${orgId}`, 'private', {
            persist: true,
        });
        const refetchFromPeer = (p: { origin_tab?: string | null }): void => {
            if (p.origin_tab === realtimeTabId()) {
                return; // self-echo — the cache is already patched
            }

            void reload();
        };

        bind('MergeFieldsChanged', refetchFromPeer);
        bind('MergeValuesChanged', refetchFromPeer);
    }

    return {
        fields,
        values,
        loaded,
        load,
        reload,
        createField,
        updateField,
        destroyField,
        setValue,
        clearValue,
        rowFor,
        resolvedFor,
        missingKeysFor,
        groupedFields,
        subscribe,
    };
});
