import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import axios from 'axios';
import { usePreferencesStore } from '@/stores/preferences';
import { useTableFilter } from '@/composables/useTableFilter';

vi.mock('axios');

describe('useTableFilter', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        vi.clearAllMocks();
        (axios.patch as ReturnType<typeof vi.fn>).mockResolvedValue({ data: {} });
    });

    it('commit applies the current params to the server query', () => {
        const apply = vi.fn();
        const f = useTableFilter('users', { q: '', role: '' }, apply);

        f.params.q = 'dana';
        f.commit();

        expect(apply).toHaveBeenCalledWith({ q: 'dana', role: '' });
    });

    it('commit persists the params to prefs (debounced)', async () => {
        vi.useFakeTimers();
        const f = useTableFilter('users', { q: '', role: '' }, vi.fn());

        f.params.q = 'a';
        f.commit();
        f.params.q = 'ab';
        f.commit();
        expect(axios.patch).not.toHaveBeenCalled();

        await vi.advanceTimersByTimeAsync(700);
        vi.useRealTimers();

        expect(axios.patch).toHaveBeenCalledTimes(1);
        expect(axios.patch).toHaveBeenLastCalledWith(
            '/api/me/preferences',
            { preferences: { users: { filters: { q: 'ab', role: '' } } } },
            expect.anything(),
        );
    });

    it('restoreSaved applies saved filters only when the page is unfiltered', () => {
        usePreferencesStore().hydrate({
            users: { filters: { q: 'saved', role: 'Admin' } },
        });
        const apply = vi.fn();
        const f = useTableFilter('users', { q: '', role: '' }, apply);

        f.restoreSaved(true);

        expect(f.params.q).toBe('saved');
        expect(f.params.role).toBe('Admin');
        expect(apply).toHaveBeenCalledWith({ q: 'saved', role: 'Admin' });
    });

    it('restoreSaved is a no-op when the page already has filters', () => {
        usePreferencesStore().hydrate({
            users: { filters: { q: 'saved' } },
        });
        const apply = vi.fn();
        const f = useTableFilter('users', { q: 'fromUrl', role: '' }, apply);

        f.restoreSaved(false);

        expect(f.params.q).toBe('fromUrl');
        expect(apply).not.toHaveBeenCalled();
    });

    it('restoreSaved is a no-op when there are no saved filters', () => {
        usePreferencesStore().hydrate({});
        const apply = vi.fn();
        const f = useTableFilter('users', { q: '', role: '' }, apply);

        f.restoreSaved(true);
        expect(apply).not.toHaveBeenCalled();
    });
});
