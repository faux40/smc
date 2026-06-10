/**
 * DataTable — self-contained table component.
 *
 * Owns: column-visibility + order (via useTableView), drag-to-reorder (via
 * useColumnDrag), Columns dropdown, SortableHeader loop, and per-column slot
 * dispatch. Pages supply filter controls (in #filters slot), bespoke cell
 * markup (#col-{key} slots), and any fixed leading/trailing columns.
 */
import { mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { h, nextTick } from 'vue';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import axios from 'axios';
import DataTable from '@/components/DataTable.vue';
import { usePreferencesStore } from '@/stores/preferences';

vi.mock('axios');

const COLS = [
    { key: 'name', label: 'Name', sortable: true },
    { key: 'email', label: 'Email', sortable: false },
];
const ROWS = [
    { id: '1', name: 'Alice', email: 'alice@example.com' },
    { id: '2', name: 'Bob', email: 'bob@example.com' },
];

function makeWrapper(
    propsOverride: Record<string, unknown> = {},
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    slots: Record<string, any> = {},
) {
    return mount(DataTable, {
        props: {
            viewId: 'test',
            defaultColumns: COLS,
            rows: ROWS,
            rowKey: (row: object) => (row as { id: string }).id,
            ...propsOverride,
        },
        slots,
    });
}

describe('DataTable', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        (axios.patch as ReturnType<typeof vi.fn>).mockResolvedValue({ data: {} });
        usePreferencesStore().hydrate({});
    });

    // ── Structure ──────────────────────────────────────────────────────────────

    it('renders a header for each visible default column', () => {
        const wrapper = makeWrapper();
        expect(wrapper.text()).toContain('Name');
        expect(wrapper.text()).toContain('Email');
    });

    it('renders one body row per item', () => {
        const wrapper = makeWrapper();
        expect(wrapper.findAll('tbody tr')).toHaveLength(2);
    });

    it('shows a Columns button', () => {
        const wrapper = makeWrapper();
        expect(wrapper.find('button').text()).toBe('Columns');
    });

    // ── Cell rendering ─────────────────────────────────────────────────────────

    it('falls back to plain text for columns without a col slot', () => {
        const wrapper = makeWrapper();
        expect(wrapper.text()).toContain('Alice');
        expect(wrapper.text()).toContain('alice@example.com');
    });

    it('uses a col-{key} scoped slot when provided, receiving col + row', () => {
        const wrapper = makeWrapper(
            {},
            {
                'col-name': ({ row }: { row: Record<string, unknown> }) =>
                    h('strong', { 'data-testid': 'custom-name' }, row.name as string),
            },
        );
        const customs = wrapper.findAll('[data-testid="custom-name"]');
        expect(customs).toHaveLength(2);
        expect(customs[0].text()).toBe('Alice');
        expect(customs[1].text()).toBe('Bob');
    });

    // ── Sort ───────────────────────────────────────────────────────────────────

    it('emits sort with the column key when a sortable header is clicked', async () => {
        const wrapper = makeWrapper({ sortKey: null, sortDir: 'asc' });
        // SortableHeader renders <th><button @click="emit('sort', sortKey)">…</button></th>.
        // The click target must be the inner button, not the <th>.
        const sortBtn = wrapper.find('thead th button');
        await sortBtn.trigger('click');
        const emitted = wrapper.emitted('sort');
        expect(emitted).toBeTruthy();
        expect(emitted![0]).toEqual(['name']);
    });

    // ── Column control ─────────────────────────────────────────────────────────

    it('hides a column and its cells after toggle via the prefs store', async () => {
        const wrapper = makeWrapper();
        // Drive visibility through the prefs store — the Checkbox/Reka-UI
        // interaction is covered by TableColumnsMenu.spec.ts. Here we verify
        // DataTable re-renders when the store changes.
        const prefs = usePreferencesStore();
        prefs.update('test', { visible_columns: { email: false } });
        await nextTick();
        expect(wrapper.text()).not.toContain('Email');
        expect(wrapper.text()).not.toContain('alice@example.com');
    });

    it('respects saved column visibility from prefs on mount', () => {
        usePreferencesStore().hydrate({
            test: { visible_columns: { email: false } },
        });
        const wrapper = makeWrapper();
        expect(wrapper.text()).not.toContain('Email');
        expect(wrapper.text()).toContain('Name');
    });

    // ── Extra slots ────────────────────────────────────────────────────────────

    it('renders #filters slot content left of the Columns button', () => {
        const wrapper = makeWrapper(
            {},
            { filters: h('input', { placeholder: 'Search', 'data-testid': 'search' }) },
        );
        expect(wrapper.find('[data-testid="search"]').exists()).toBe(true);
    });

    it('renders #trail-header and #trail-cells slots', () => {
        const wrapper = makeWrapper(
            {},
            {
                'trail-header': h('th', 'Actions'),
                'trail-cells': ({ row }: { row: Record<string, unknown> }) =>
                    h('td', `del-${row.id}`),
            },
        );
        expect(wrapper.text()).toContain('Actions');
        expect(wrapper.text()).toContain('del-1');
        expect(wrapper.text()).toContain('del-2');
    });

    it('renders #lead-header and #lead-cells slots before dynamic columns', () => {
        const wrapper = makeWrapper(
            {},
            {
                'lead-header': h('th', 'Select'),
                'lead-cells': h('td', 'checkbox'),
            },
        );
        expect(wrapper.text()).toContain('Select');
        expect(wrapper.text()).toContain('checkbox');
    });
});
