import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import axios from 'axios';
import { usePreferencesStore } from '@/stores/preferences';
import { useTableView } from '@/composables/useTableView';

vi.mock('axios');

const COLUMNS = [
    { key: 'name', label: 'Name', sortable: true },
    { key: 'email', label: 'Email', sortable: true },
    { key: 'role', label: 'Role' },
    { key: 'dept', label: 'Department', defaultVisible: false },
];

describe('useTableView', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        vi.clearAllMocks();
        (axios.patch as ReturnType<typeof vi.fn>).mockResolvedValue({ data: {} });
    });

    it('defaults to declared order + per-column default visibility', () => {
        const view = useTableView('users', COLUMNS);
        expect(view.columns.value.map((c) => c.key)).toEqual([
            'name',
            'email',
            'role',
            'dept',
        ]);
        // dept defaults hidden; the rest default visible.
        expect(view.visibleColumns.value.map((c) => c.key)).toEqual([
            'name',
            'email',
            'role',
        ]);
        expect(view.columns.value.find((c) => c.key === 'name')?.sortable).toBe(
            true,
        );
    });

    it('applies saved visibility overrides', () => {
        usePreferencesStore().hydrate({
            users: { visible_columns: { email: false, dept: true } },
        });
        const view = useTableView('users', COLUMNS);
        expect(view.visibleColumns.value.map((c) => c.key)).toEqual([
            'name',
            'role',
            'dept',
        ]);
    });

    it('applies saved order and appends columns not in the saved order', () => {
        usePreferencesStore().hydrate({
            users: { column_order: ['role', 'name'] },
        });
        const view = useTableView('users', COLUMNS);
        // role, name first (saved), then email, dept (declared order) appended.
        expect(view.columns.value.map((c) => c.key)).toEqual([
            'role',
            'name',
            'email',
            'dept',
        ]);
    });

    it('toggle flips visibility and persists', () => {
        const prefs = usePreferencesStore();
        prefs.hydrate({});
        const spy = vi.spyOn(prefs, 'update');
        const view = useTableView('users', COLUMNS);

        view.toggle('email');
        expect(spy).toHaveBeenCalledWith('users', {
            visible_columns: { email: false },
        });
        expect(view.isVisible('email')).toBe(false);
    });

    it('move reorders horizontally and persists the full key order', () => {
        const prefs = usePreferencesStore();
        prefs.hydrate({});
        const spy = vi.spyOn(prefs, 'update');
        const view = useTableView('users', COLUMNS);

        view.move('email', 'left'); // email swaps before name
        expect(spy).toHaveBeenCalledWith('users', {
            column_order: ['email', 'name', 'role', 'dept'],
        });
        expect(view.columns.value.map((c) => c.key)).toEqual([
            'email',
            'name',
            'role',
            'dept',
        ]);
    });

    it('move is a no-op at the edges', () => {
        const prefs = usePreferencesStore();
        prefs.hydrate({});
        const spy = vi.spyOn(prefs, 'update');
        const view = useTableView('users', COLUMNS);

        view.move('name', 'left'); // already leftmost
        expect(spy).not.toHaveBeenCalled();
    });
});
