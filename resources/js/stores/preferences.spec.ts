import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import axios from 'axios';
import { usePreferencesStore } from '@/stores/preferences';

vi.mock('axios');

describe('preferences store', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        vi.clearAllMocks();
        (axios.patch as ReturnType<typeof vi.fn>).mockResolvedValue({
            data: { ok: true },
        });
    });

    it('hydrates from the shared blob and reads a view', () => {
        const store = usePreferencesStore();
        store.hydrate({
            users: { visible_columns: { email: false }, filters: { q: 'x' } },
        });

        expect(store.view('users').visible_columns).toEqual({ email: false });
        expect(store.view('users').filters).toEqual({ q: 'x' });
        // Unknown view → empty object, not undefined.
        expect(store.view('assignments')).toEqual({});
    });

    it('treats a null/absent blob as empty', () => {
        const store = usePreferencesStore();
        store.hydrate(null);
        expect(store.view('users')).toEqual({});
    });

    it('merges an update into the view without clobbering other keys/views', () => {
        const store = usePreferencesStore();
        store.hydrate({
            users: { visible_columns: { email: false }, filters: { q: 'x' } },
            assignments: { visible_columns: { tags: true } },
        });

        store.update('users', {
            visible_columns: { email: true, role: false },
        });

        // visible_columns replaced for users; filters + the assignments view kept.
        expect(store.view('users').visible_columns).toEqual({
            email: true,
            role: false,
        });
        expect(store.view('users').filters).toEqual({ q: 'x' });
        expect(store.view('assignments').visible_columns).toEqual({
            tags: true,
        });
    });

    it('ensureHydrated only hydrates once (later calls are no-ops)', () => {
        const store = usePreferencesStore();
        store.ensureHydrated({ users: { filters: { q: 'first' } } });
        store.ensureHydrated({ users: { filters: { q: 'second' } } });
        expect(store.view('users').filters).toEqual({ q: 'first' });
    });

    it('resetView removes column_order and visible_columns but keeps filters', () => {
        const store = usePreferencesStore();
        store.hydrate({
            users: {
                visible_columns: { email: false },
                column_order: ['email', 'name'],
                filters: { q: 'hello' },
            },
            assignments: { column_order: ['date'] },
        });

        store.resetView('users');

        expect(store.view('users').visible_columns).toBeUndefined();
        expect(store.view('users').column_order).toBeUndefined();
        // filters are NOT cleared — only column layout is reset
        expect(store.view('users').filters).toEqual({ q: 'hello' });
        // other views untouched
        expect(store.view('assignments').column_order).toEqual(['date']);
    });

    it('resetAllViews removes column_order and visible_columns from every view, keeps filters', () => {
        const store = usePreferencesStore();
        store.hydrate({
            users: {
                visible_columns: { email: false },
                column_order: ['email'],
                filters: { q: 'a' },
            },
            assignments: {
                visible_columns: { date: false },
                column_order: ['date'],
            },
        });

        store.resetAllViews();

        expect(store.view('users').visible_columns).toBeUndefined();
        expect(store.view('users').column_order).toBeUndefined();
        expect(store.view('users').filters).toEqual({ q: 'a' });
        expect(store.view('assignments').visible_columns).toBeUndefined();
        expect(store.view('assignments').column_order).toBeUndefined();
    });

    it('persists the whole blob to the endpoint, debounced', async () => {
        vi.useFakeTimers();
        const store = usePreferencesStore();
        store.hydrate({});

        store.update('users', { filters: { q: 'a' } });
        store.update('users', { filters: { q: 'ab' } });
        expect(axios.patch).not.toHaveBeenCalled();

        await vi.advanceTimersByTimeAsync(700);
        vi.useRealTimers();

        expect(axios.patch).toHaveBeenCalledTimes(1);
        expect(axios.patch).toHaveBeenLastCalledWith(
            '/api/me/preferences',
            { preferences: { users: { filters: { q: 'ab' } } } },
            expect.anything(),
        );
    });
});
