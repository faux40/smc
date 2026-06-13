import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import Pagination from '@/components/Pagination.vue';

function mountPagination(
    props: Partial<InstanceType<typeof Pagination>['$props']> = {},
) {
    return mount(Pagination, {
        props: { page: 1, lastPage: 3, total: 7, perPage: 3, ...props },
    });
}

describe('Pagination', () => {
    it('shows the current range and total', () => {
        const w = mountPagination({ page: 2, perPage: 3, total: 7 });
        expect(w.find('[data-testid="page-range"]').text()).toBe(
            'Showing 4–6 of 7',
        );
        expect(w.find('[data-testid="page-indicator"]').text()).toContain(
            'Page 2 of 3',
        );
    });

    it('shows "No results" when empty', () => {
        const w = mountPagination({ total: 0, lastPage: 1 });
        expect(w.find('[data-testid="page-range"]').text()).toBe('No results');
    });

    it('disables Prev on the first page and Next on the last', () => {
        const first = mountPagination({ page: 1, lastPage: 3 });
        expect(
            first
                .find('button[aria-label="Previous page"]')
                .attributes('disabled'),
        ).toBeDefined();
        expect(
            first.find('button[aria-label="Next page"]').attributes('disabled'),
        ).toBeUndefined();

        const last = mountPagination({ page: 3, lastPage: 3 });
        expect(
            last.find('button[aria-label="Next page"]').attributes('disabled'),
        ).toBeDefined();
    });

    it('emits update:page on Prev/Next', async () => {
        const w = mountPagination({ page: 2, lastPage: 3 });
        await w.find('button[aria-label="Next page"]').trigger('click');
        await w.find('button[aria-label="Previous page"]').trigger('click');
        expect(w.emitted('update:page')).toEqual([[3], [1]]);
    });

    it('emits update:perPage when the selector changes', async () => {
        const w = mountPagination({ perPage: 25 });
        await w.find('select[aria-label="Rows per page"]').setValue('50');
        expect(w.emitted('update:perPage')).toEqual([[50]]);
    });
});
