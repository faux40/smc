import { beforeEach, describe, expect, it, vi } from 'vitest';
import { useTableFilter } from '@/composables/useTableFilter';

const BLANK = { q: '', role: '' };
const KEY = 'tableFilters:users';

describe('useTableFilter (session-scoped)', () => {
    beforeEach(() => {
        sessionStorage.clear();
    });

    it('commit applies the current params to the server query', () => {
        const apply = vi.fn();
        const f = useTableFilter('users', { q: '', role: '' }, BLANK, apply);

        f.params.q = 'dana';
        f.commit();

        expect(apply).toHaveBeenCalledWith({ q: 'dana', role: '' });
    });

    it('commit mirrors the params to sessionStorage (not the profile)', () => {
        const f = useTableFilter('users', { q: '', role: '' }, BLANK, vi.fn());

        f.params.q = 'ab';
        f.params.role = 'Admin';
        f.commit();

        expect(JSON.parse(sessionStorage.getItem(KEY)!)).toEqual({
            q: 'ab',
            role: 'Admin',
        });
    });

    it('restore applies the session filter only when the page is unfiltered', () => {
        sessionStorage.setItem(
            KEY,
            JSON.stringify({ q: 'saved', role: 'Admin' }),
        );
        const apply = vi.fn();
        const f = useTableFilter('users', { q: '', role: '' }, BLANK, apply);

        f.restore(true);

        expect(f.params.q).toBe('saved');
        expect(f.params.role).toBe('Admin');
        expect(apply).toHaveBeenCalledWith({ q: 'saved', role: 'Admin' });
    });

    it('restore is a no-op when the page already has filters (URL wins)', () => {
        sessionStorage.setItem(KEY, JSON.stringify({ q: 'saved' }));
        const apply = vi.fn();
        const f = useTableFilter(
            'users',
            { q: 'fromUrl', role: '' },
            BLANK,
            apply,
        );

        f.restore(false);

        expect(f.params.q).toBe('fromUrl');
        expect(apply).not.toHaveBeenCalled();
    });

    it('restore is a no-op when there is no session filter', () => {
        const apply = vi.fn();
        const f = useTableFilter('users', { q: '', role: '' }, BLANK, apply);

        f.restore(true);
        expect(apply).not.toHaveBeenCalled();
    });

    it('clear resets to blank, drops the session entry, and re-queries unfiltered', () => {
        sessionStorage.setItem(
            KEY,
            JSON.stringify({ q: 'saved', role: 'Admin' }),
        );
        const apply = vi.fn();
        const f = useTableFilter(
            'users',
            { q: 'saved', role: 'Admin' },
            BLANK,
            apply,
        );

        f.clear();

        expect(f.params).toEqual({ q: '', role: '' });
        expect(sessionStorage.getItem(KEY)).toBeNull();
        expect(apply).toHaveBeenCalledWith({ q: '', role: '' });
    });
});
