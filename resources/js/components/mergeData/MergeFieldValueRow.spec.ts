import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import MergeFieldValueRow from '@/components/mergeData/MergeFieldValueRow.vue';
import { useMergeDataStore } from '@/stores/mergeData';
import type { MergeFieldRow, MergeValueRow } from '@/stores/mergeData';

vi.mock('axios');
vi.mock('@/echo', () => ({ realtimeTabId: () => 'test-tab' }));
vi.mock('vue-sonner', () => ({ toast: { success: vi.fn(), error: vi.fn() } }));

function field(overrides: Partial<MergeFieldRow> = {}): MergeFieldRow {
    return {
        id: 'f1',
        key: 'agency',
        label: 'Agency name',
        type: 'text',
        field_group: null,
        help: null,
        seq: 0,
        draft: false,
        is_system: true,
        can_edit: false,
        can_delete: false,
        ...overrides,
    };
}

function value(
    overrides: Partial<MergeValueRow> = {},
): MergeValueRow {
    return {
        id: 'v1',
        merge_field_id: 'f1',
        location: '',
        department: '',
        value: 'City of Rio Dell',
        ...overrides,
    };
}

function mountRow(
    f: MergeFieldRow,
    values: MergeValueRow[],
    variation: { location?: string; department?: string } = {},
) {
    const store = useMergeDataStore();
    store.fields = [f];
    store.values = values;
    store.setValue = vi.fn().mockResolvedValue(undefined);
    store.clearValue = vi.fn().mockResolvedValue(undefined);

    const wrapper = mount(MergeFieldValueRow, {
        props: {
            field: f,
            location: variation.location ?? '',
            department: variation.department ?? '',
        },
    });

    return { store, wrapper };
}

describe('MergeFieldValueRow', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        vi.clearAllMocks();
    });

    it('seeds the input from the exact variation row', () => {
        const { wrapper } = mountRow(field(), [value()]);

        expect(wrapper.get('input').element.value).toBe('City of Rio Dell');
    });

    it('shows an inherited hint when the variation has no own row', () => {
        const { wrapper } = mountRow(field(), [value()], {
            location: 'North Yard',
        });

        expect(wrapper.get('input').element.value).toBe('');
        expect(wrapper.text()).toContain('Inherited');
        expect(wrapper.text()).toContain('City of Rio Dell');
    });

    it('shows the placeholder warning when nothing is set anywhere', () => {
        const { wrapper } = mountRow(field(), []);

        expect(wrapper.text()).toContain('--AGENCY--');
    });

    it('saves a dirty text value through the store', async () => {
        const { store, wrapper } = mountRow(field(), [value()]);

        await wrapper.get('input').setValue('New name');
        const save = wrapper.get('[data-testid="save-value"]');
        await save.trigger('click');
        await flushPromises();

        expect(store.setValue).toHaveBeenCalledWith('f1', '', '', 'New name');
    });

    it('hides the save button until dirty', () => {
        const { wrapper } = mountRow(field(), [value()]);

        expect(wrapper.find('[data-testid="save-value"]').exists()).toBe(false);
    });

    it('parses list fields one item per line, dropping blanks', async () => {
        const f = field({ type: 'list', key: 'workgroups', label: 'Workgroups' });
        const { store, wrapper } = mountRow(f, [
            value({ value: ['Parks', 'Water'] }),
        ]);

        const textarea = wrapper.get('textarea');
        expect(textarea.element.value).toBe('Parks\nWater');

        await textarea.setValue('Parks\n\n  Public Works  \nWater\n');
        await wrapper.get('[data-testid="save-value"]').trigger('click');
        await flushPromises();

        expect(store.setValue).toHaveBeenCalledWith('f1', '', '', [
            'Parks',
            'Public Works',
            'Water',
        ]);
    });

    it('renders a date input for date fields', () => {
        const { wrapper } = mountRow(field({ type: 'date' }), []);

        expect(wrapper.get('input').attributes('type')).toBe('date');
    });

    it('clears an override via the store after confirm', async () => {
        vi.stubGlobal('confirm', vi.fn().mockReturnValue(true));
        const rows = [
            value(),
            value({ id: 'v2', location: 'North Yard', value: 'North override' }),
        ];
        const { store, wrapper } = mountRow(field(), rows, {
            location: 'North Yard',
        });

        await wrapper.get('[data-testid="clear-value"]').trigger('click');
        await flushPromises();

        expect(store.clearValue).toHaveBeenCalledWith('v2');
    });

    it('offers no clear button when the variation has no own row', () => {
        const { wrapper } = mountRow(field(), [value()], {
            location: 'North Yard',
        });

        expect(wrapper.find('[data-testid="clear-value"]').exists()).toBe(false);
    });

    it('emits edit/remove for editable fields when admin actions are on', async () => {
        const store = useMergeDataStore();
        const f = field({ is_system: false, can_edit: true, can_delete: true });
        store.fields = [f];
        store.values = [];

        const wrapper = mount(MergeFieldValueRow, {
            props: { field: f, location: '', department: '', adminActions: true },
        });

        await wrapper.get('[data-testid="edit-field"]').trigger('click');
        await wrapper.get('[data-testid="remove-field"]').trigger('click');

        expect(wrapper.emitted('edit')).toHaveLength(1);
        expect(wrapper.emitted('remove')).toHaveLength(1);
    });

    it('hides admin actions on system fields even for admins', () => {
        const f = field({ is_system: true, can_edit: false, can_delete: false });
        const store = useMergeDataStore();
        store.fields = [f];
        store.values = [];

        const wrapper = mount(MergeFieldValueRow, {
            props: { field: f, location: '', department: '', adminActions: true },
        });

        expect(wrapper.find('[data-testid="edit-field"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="remove-field"]').exists()).toBe(false);
    });
});
