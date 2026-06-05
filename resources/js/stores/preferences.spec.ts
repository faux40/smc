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

        store.update('users', { visible_columns: { email: true, role: false } });

        // visible_columns replaced for users; filters + the assignments view kept.
        expect(store.view('users').visible_columns).toEqual({
            email: true,
            role: false,
        });
        expect(store.view('users').filters).toEqual({ q: 'x' });
        expect(store.view('assignments').visible_columns).toEqual({ tags: true });
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
