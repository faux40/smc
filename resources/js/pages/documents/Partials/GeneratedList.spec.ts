import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import GeneratedList from '@/pages/documents/Partials/GeneratedList.vue';
import { useGeneratedDocumentsStore } from '@/stores/generatedDocuments';
import type { GeneratedDocumentRow } from '@/stores/generatedDocuments';

vi.mock('axios');
vi.mock('@/echo', () => ({ realtimeTabId: () => 'test-tab' }));
vi.mock('vue-sonner', () => ({ toast: { success: vi.fn(), error: vi.fn() } }));
vi.mock('@/composables/useRealtime', () => ({
    useRealtime: vi.fn(() => ({ bind: vi.fn(), leave: vi.fn() })),
}));

function row(overrides: Partial<GeneratedDocumentRow> & { id: string }): GeneratedDocumentRow {
    return {
        template_id: 't1',
        template_name: 'HazCom',
        extension: 'docx',
        location: '',
        department: '',
        status: 'done',
        error: null,
        filename: 'rio_dell.hazcom_20260711',
        requested_by_name: 'John Barritt',
        created_at: '2026-07-11T12:00:00Z',
        updated_at: '2026-07-11T12:00:00Z',
        ...overrides,
    };
}

describe('GeneratedList', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        vi.clearAllMocks();
    });

    async function mountList(rows: GeneratedDocumentRow[]) {
        const store = useGeneratedDocumentsStore();
        store.fetchPage = vi.fn().mockResolvedValue({
            data: rows,
            meta: { current_page: 1, last_page: 1, per_page: 25, total: rows.length },
        });
        store.destroy = vi.fn().mockResolvedValue(undefined);
        store.retry = vi.fn().mockResolvedValue(rows[0]);

        const wrapper = mount(GeneratedList);
        await flushPromises();

        return { wrapper, store };
    }

    it('renders done rows with both download links', async () => {
        const { wrapper } = await mountList([row({ id: 'g1' })]);

        expect(wrapper.get('[data-testid="download-pdf"]').attributes('href')).toBe(
            '/api/generated-documents/g1/download?format=pdf',
        );
        expect(wrapper.get('[data-testid="download-merged"]').text()).toContain('DOCX');
    });

    it('hides downloads and shows the error for failed rows', async () => {
        const { wrapper } = await mountList([
            row({ id: 'g2', status: 'failed', error: 'soffice exploded' }),
        ]);

        expect(wrapper.find('[data-testid="download-pdf"]').exists()).toBe(false);
        expect(wrapper.text()).toContain('soffice exploded');
    });

    /*
     * A failed row keeps its error string forever ($tries = 1, nothing
     * re-runs it), so without a date a weeks-old fossil reads as a live
     * outage — which is exactly what happened on prod 2026-08-04.
     */
    it('dates the failure so a stale error cannot pass for a live one', async () => {
        const { wrapper } = await mountList([
            row({
                id: 'g5',
                status: 'failed',
                error: 'PDF conversion failed: sh: 1: exec: soffice: not found',
                updated_at: '2026-07-13T20:47:50Z',
            }),
        ]);

        const failedAt = wrapper.get('[data-testid="failed-at"]');
        expect(failedAt.text()).toContain('Failed');
        // Compared against the same conversion the component does, so the
        // assertion does not depend on the runner's locale.
        expect(failedAt.text()).toContain(
            new Date('2026-07-13T20:47:50Z').toLocaleString(),
        );
    });

    it('retries a failed row through the store', async () => {
        const { wrapper, store } = await mountList([
            row({ id: 'g6', status: 'failed', error: 'boom' }),
        ]);

        await wrapper.get('[data-testid="retry-generated"]').trigger('click');
        await flushPromises();

        expect(store.retry).toHaveBeenCalledWith('g6');
    });

    it('offers no retry on rows that did not fail', async () => {
        const { wrapper } = await mountList([
            row({ id: 'g7', status: 'done' }),
            row({ id: 'g8', status: 'queued' }),
        ]);

        expect(wrapper.find('[data-testid="retry-generated"]').exists()).toBe(false);
    });

    it('deletes after confirm', async () => {
        vi.stubGlobal('confirm', vi.fn().mockReturnValue(true));
        const { wrapper, store } = await mountList([row({ id: 'g3' })]);

        await wrapper.get('[data-testid="delete-generated"]').trigger('click');
        await flushPromises();

        expect(store.destroy).toHaveBeenCalledWith('g3');
    });

    it('refetches when the store revision bumps', async () => {
        const { store } = await mountList([row({ id: 'g4' })]);
        (store.fetchPage as ReturnType<typeof vi.fn>).mockClear();

        store.revision++;
        await vi.waitFor(() => expect(store.fetchPage).toHaveBeenCalled());
    });
});
