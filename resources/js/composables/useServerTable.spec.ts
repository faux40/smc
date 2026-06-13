import { flushPromises } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { useServerTable } from '@/composables/useServerTable';
import type { ServerTableQuery } from '@/composables/useServerTable';

interface Row {
    id: number;
}

function fakeFetcher(total = 7) {
    return vi.fn(async (p: ServerTableQuery) => {
        const lastPage = Math.max(1, Math.ceil(total / p.per_page));
        const current = Math.min(Math.max(1, p.page), lastPage);

        return {
            data: [{ id: current }] as Row[],
            meta: {
                current_page: current,
                last_page: lastPage,
                per_page: p.per_page,
                total,
            },
        };
    });
}

describe('useServerTable', () => {
    beforeEach(() => vi.useFakeTimers());
    afterEach(() => vi.useRealTimers());

    it('fetchPage populates rows + meta and mirrors the server page', async () => {
        const fetcher = fakeFetcher(7);
        const t = useServerTable<Row>(fetcher, { perPage: 3 });
        await t.fetchPage();

        expect(fetcher).toHaveBeenCalledTimes(1);
        expect(t.total.value).toBe(7);
        expect(t.lastPage.value).toBe(3);
        expect(t.page.value).toBe(1);
        expect(t.hasNext.value).toBe(true);
        expect(t.hasPrev.value).toBe(false);
    });

    it('setPage clamps to [1, lastPage] and refetches', async () => {
        const fetcher = fakeFetcher(7);
        const t = useServerTable<Row>(fetcher, { perPage: 3 });
        await t.fetchPage();

        t.setPage(99); // clamps to last page (3)
        await flushPromises();
        expect(t.page.value).toBe(3);
        expect(t.hasNext.value).toBe(false);

        const callsBefore = fetcher.mock.calls.length;
        t.setPage(3); // no change → no refetch
        expect(fetcher.mock.calls.length).toBe(callsBefore);
    });

    it('setSort toggles dir on the same key and resets to page 1', async () => {
        const fetcher = fakeFetcher();
        const t = useServerTable<Row>(fetcher, {
            perPage: 3,
            sort: null,
            dir: 'desc',
        });
        await t.fetchPage();
        t.setPage(2);
        await flushPromises();

        t.setSort('completion_date'); // new key → asc, page resets
        await flushPromises();
        expect(t.sort.value).toBe('completion_date');
        expect(t.dir.value).toBe('asc');
        expect(t.page.value).toBe(1);

        t.setSort('completion_date'); // same key → flip to desc
        await flushPromises();
        expect(t.dir.value).toBe('desc');
    });

    it('setQuery is debounced, resets to page 1, and passes q to the fetcher', async () => {
        const fetcher = fakeFetcher();
        const t = useServerTable<Row>(fetcher, { perPage: 3 });
        await t.fetchPage();
        t.setPage(2);
        await flushPromises();
        fetcher.mockClear();

        t.setQuery('ab');
        t.setQuery('abc'); // rapid typing collapses to one call
        expect(fetcher).not.toHaveBeenCalled();
        vi.advanceTimersByTime(300);
        await flushPromises();

        expect(fetcher).toHaveBeenCalledTimes(1);
        expect(fetcher.mock.calls[0][0].q).toBe('abc');
        expect(fetcher.mock.calls[0][0].page).toBe(1);
    });

    it('setPerPage resets to page 1', async () => {
        const fetcher = fakeFetcher(7);
        const t = useServerTable<Row>(fetcher, { perPage: 3 });
        await t.fetchPage();
        t.setPage(3);
        await flushPromises();

        t.setPerPage(50);
        await flushPromises();
        expect(t.perPage.value).toBe(50);
        expect(t.page.value).toBe(1);
    });

    it('refetchSoon re-pulls the current page once for a burst of events', async () => {
        const fetcher = fakeFetcher();
        const t = useServerTable<Row>(fetcher, { perPage: 3 });
        await t.fetchPage();
        fetcher.mockClear();

        t.refetchSoon();
        t.refetchSoon();
        t.refetchSoon();
        expect(fetcher).not.toHaveBeenCalled();
        vi.advanceTimersByTime(400);
        await flushPromises();
        expect(fetcher).toHaveBeenCalledTimes(1);
    });

    it('reload resets to page 1 and refetches', async () => {
        const fetcher = fakeFetcher();
        const t = useServerTable<Row>(fetcher, { perPage: 3 });
        await t.fetchPage();
        t.setPage(2);
        await flushPromises();
        fetcher.mockClear();

        t.reload();
        await flushPromises();
        expect(t.page.value).toBe(1);
        expect(fetcher).toHaveBeenCalledTimes(1);
    });
});
