import axios from 'axios';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { useMergeDataStore } from '@/stores/mergeData';
import type { MergeFieldRow, MergeValueRow } from '@/stores/mergeData';

vi.mock('axios');
vi.mock('@/echo', () => ({ realtimeTabId: () => 'test-tab' }));

const capturedBindings: Record<string, (payload: unknown) => void> = {};
const mockBind = vi.fn((event: string, cb: (p: unknown) => void) => {
    capturedBindings[event] = cb;
});

vi.mock('@/composables/useRealtime', () => ({
    useRealtime: vi.fn(() => ({ bind: mockBind, leave: vi.fn() })),
}));

function field(
    overrides: Partial<MergeFieldRow> & { id: string },
): MergeFieldRow {
    return {
        key: overrides.id,
        label: overrides.id,
        type: 'text',
        field_group: null,
        help: null,
        seq: 0,
        draft: false,
        is_system: false,
        can_edit: true,
        can_delete: true,
        ...overrides,
    };
}

function value(
    overrides: Partial<MergeValueRow> & { id: string; merge_field_id: string },
): MergeValueRow {
    return {
        location: '',
        department: '',
        value: 'x',
        ...overrides,
    };
}

describe('useMergeDataStore', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        vi.clearAllMocks();
        Object.keys(capturedBindings).forEach(
            (k) => delete capturedBindings[k],
        );
    });

    // ----------------------------------------------------------------
    // resolution ladder (mirror of backend MergeValueResolver)
    // ----------------------------------------------------------------

    it('resolvedFor walks both > location > department > default', () => {
        const store = useMergeDataStore();
        store.fields = [field({ id: 'f1', key: 'contact' })];
        store.values = [
            value({ id: 'v1', merge_field_id: 'f1', value: 'default' }),
            value({
                id: 'v2',
                merge_field_id: 'f1',
                department: 'Parks',
                value: 'dept',
            }),
            value({
                id: 'v3',
                merge_field_id: 'f1',
                location: 'North',
                value: 'loc',
            }),
            value({
                id: 'v4',
                merge_field_id: 'f1',
                location: 'North',
                department: 'Parks',
                value: 'both',
            }),
        ];

        expect(store.resolvedFor('f1', 'North', 'Parks')).toEqual({
            value: 'both',
            source: 'exact',
        });
        expect(store.resolvedFor('f1', 'North', '')).toEqual({
            value: 'loc',
            source: 'exact',
        });
        expect(store.resolvedFor('f1', 'South', 'Parks')).toEqual({
            value: 'dept',
            source: 'department',
        });
        expect(store.resolvedFor('f1', 'South', '')).toEqual({
            value: 'default',
            source: 'default',
        });
        expect(store.resolvedFor('f1', '', '')).toEqual({
            value: 'default',
            source: 'exact',
        });
    });

    it('resolvedFor reports location fallback for a both-variation request', () => {
        const store = useMergeDataStore();
        store.fields = [field({ id: 'f1', key: 'contact' })];
        store.values = [
            value({
                id: 'v3',
                merge_field_id: 'f1',
                location: 'North',
                value: 'loc',
            }),
        ];

        expect(store.resolvedFor('f1', 'North', 'Parks')).toEqual({
            value: 'loc',
            source: 'location',
        });
    });

    it('resolvedFor returns null source when nothing is set', () => {
        const store = useMergeDataStore();
        store.fields = [field({ id: 'f1', key: 'contact' })];

        expect(store.resolvedFor('f1', '', '')).toEqual({
            value: null,
            source: null,
        });
    });

    it('rowFor returns only the exact variation row', () => {
        const store = useMergeDataStore();
        store.values = [
            value({ id: 'v1', merge_field_id: 'f1', value: 'default' }),
            value({
                id: 'v2',
                merge_field_id: 'f1',
                location: 'North',
                value: 'loc',
            }),
        ];

        expect(store.rowFor('f1', 'North', '')?.id).toBe('v2');
        expect(store.rowFor('f1', '', '')?.id).toBe('v1');
        expect(store.rowFor('f1', 'South', '')).toBeUndefined();
    });

    // ----------------------------------------------------------------
    // grouping
    // ----------------------------------------------------------------

    it('groupedFields preserves server order and groups in order encountered', () => {
        const store = useMergeDataStore();
        store.fields = [
            field({ id: 'a', field_group: 'Agency' }),
            field({ id: 'b', field_group: 'Agency' }),
            field({ id: 'c', field_group: 'Emergency' }),
            field({ id: 'd', field_group: null }),
        ];

        expect(
            store.groupedFields.map((g) => ({
                group: g.group,
                ids: g.fields.map((f) => f.id),
            })),
        ).toEqual([
            { group: 'Agency', ids: ['a', 'b'] },
            { group: 'Emergency', ids: ['c'] },
            { group: null, ids: ['d'] },
        ]);
    });

    // ----------------------------------------------------------------
    // template completeness
    // ----------------------------------------------------------------

    it('missingKeysFor reports placeholder keys with no resolvable value', () => {
        const store = useMergeDataStore();
        store.fields = [
            field({ id: 'f1', key: 'agency' }),
            field({ id: 'f2', key: 'top_manager' }),
        ];
        store.values = [
            value({ id: 'v1', merge_field_id: 'f1', value: 'Rio Dell' }),
        ];

        // agency resolves; top_manager doesn't; doc_date is computed at
        // generation time; EMS_direct_phone isn't a registered field.
        expect(
            store.missingKeysFor(
                ['agency', 'top_manager', 'doc_date', 'EMS_direct_phone'],
                '',
                '',
            ),
        ).toEqual(['top_manager']);
    });

    it('missingKeysFor honors the variation ladder', () => {
        const store = useMergeDataStore();
        store.fields = [field({ id: 'f1', key: 'assembly_area' })];
        store.values = [
            value({
                id: 'v1',
                merge_field_id: 'f1',
                location: 'North',
                value: 'North gate',
            }),
        ];

        expect(store.missingKeysFor(['assembly_area'], 'North', '')).toEqual(
            [],
        );
        // No default row -> unresolved for other variations.
        expect(store.missingKeysFor(['assembly_area'], 'South', '')).toEqual([
            'assembly_area',
        ]);
    });

    // ----------------------------------------------------------------
    // network paths
    // ----------------------------------------------------------------

    it('load fetches fields and values once', async () => {
        const get = axios.get as ReturnType<typeof vi.fn>;
        get.mockImplementation((url: string) =>
            Promise.resolve({
                data: url.includes('merge-fields')
                    ? [field({ id: 'f1' })]
                    : [value({ id: 'v1', merge_field_id: 'f1' })],
            }),
        );

        const store = useMergeDataStore();
        await store.load();
        await store.load(); // second call is a no-op

        expect(get).toHaveBeenCalledTimes(2);
        expect(store.fields).toHaveLength(1);
        expect(store.values).toHaveLength(1);
    });

    it('setValue PUTs the variation and patches the cache in place', async () => {
        const put = axios.put as ReturnType<typeof vi.fn>;
        put.mockResolvedValue({
            data: value({
                id: 'v9',
                merge_field_id: 'f1',
                location: 'North',
                value: 'new',
            }),
        });

        const store = useMergeDataStore();
        store.values = [
            value({ id: 'v1', merge_field_id: 'f1', value: 'default' }),
        ];

        await store.setValue('f1', 'North', '', 'new');

        expect(put).toHaveBeenCalledWith(
            '/api/merge-values',
            {
                merge_field_id: 'f1',
                location: 'North',
                department: '',
                value: 'new',
            },
            expect.objectContaining({ headers: expect.any(Object) }),
        );
        expect(store.values).toHaveLength(2);

        // Upserting the same variation replaces, not appends.
        put.mockResolvedValue({
            data: value({
                id: 'v9',
                merge_field_id: 'f1',
                location: 'North',
                value: 'newer',
            }),
        });
        await store.setValue('f1', 'North', '', 'newer');
        expect(store.values).toHaveLength(2);
        expect(store.rowFor('f1', 'North', '')?.value).toBe('newer');
    });

    it('clearValue DELETEs and drops the row', async () => {
        const del = axios.delete as ReturnType<typeof vi.fn>;
        del.mockResolvedValue({ data: { ok: true } });

        const store = useMergeDataStore();
        store.values = [value({ id: 'v1', merge_field_id: 'f1' })];

        await store.clearValue('v1');

        expect(del).toHaveBeenCalledWith(
            '/api/merge-values/v1',
            expect.objectContaining({ headers: expect.any(Object) }),
        );
        expect(store.values).toHaveLength(0);
    });

    // ----------------------------------------------------------------
    // realtime
    // ----------------------------------------------------------------

    it('peer-tab change events reload; self-echo is ignored', async () => {
        const get = axios.get as ReturnType<typeof vi.fn>;
        get.mockResolvedValue({ data: [] });

        const store = useMergeDataStore();
        await store.load();
        get.mockClear();

        store.subscribe('org-1');
        expect(capturedBindings['MergeValuesChanged']).toBeDefined();

        capturedBindings['MergeValuesChanged']({ origin_tab: 'test-tab' });
        expect(get).not.toHaveBeenCalled();

        capturedBindings['MergeValuesChanged']({ origin_tab: 'other-tab' });
        await vi.waitFor(() => expect(get).toHaveBeenCalledTimes(2));
    });
});
