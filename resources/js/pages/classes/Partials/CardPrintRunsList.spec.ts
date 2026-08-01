import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { useCardPrintRunsStore } from '@/stores/cardPrintRuns';
import type { CardPrintRunRow } from '@/stores/cardPrintRuns';
import CardPrintRunsList from './CardPrintRunsList.vue';

vi.mock('axios');
const toastSuccess = vi.fn();
const toastError = vi.fn();
vi.mock('vue-sonner', () => ({
    toast: {
        success: (...args: unknown[]) => toastSuccess(...args),
        error: (...args: unknown[]) => toastError(...args),
    },
}));
vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: { auth: { user: { org_id: 'org1' } } } }),
}));

function run(
    overrides: Partial<CardPrintRunRow> & { id: string },
): CardPrintRunRow {
    return {
        class_training_id: 'ct1',
        topic_name: 'First Aid / CPR',
        status: 'queued',
        error: null,
        card_count: null,
        sheet_count: null,
        include_backs: false,
        proof: false,
        start_cell: 1,
        created_at: '2026-07-31T10:00:00+00:00',
        ...overrides,
    };
}

async function mountWith(runs: CardPrintRunRow[]) {
    setActivePinia(createPinia());
    const store = useCardPrintRunsStore();
    store.byClass = { c1: runs };
    store.loaded = { c1: true };
    vi.spyOn(store, 'load').mockResolvedValue();
    const subscribe = vi.spyOn(store, 'subscribe').mockImplementation(() => {});

    const wrapper = mount(CardPrintRunsList, { props: { classId: 'c1' } });
    await flushPromises();

    return { wrapper, store, subscribe };
}

describe('CardPrintRunsList', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('stays out of the way when nothing has been printed', async () => {
        const { wrapper } = await mountWith([]);

        expect(wrapper.text()).toBe('');
    });

    it('tracks a run that is still working', async () => {
        const { wrapper } = await mountWith([
            run({
                id: 'r1',
                status: 'processing',
                card_count: 12,
                sheet_count: 2,
            }),
        ]);

        const text = wrapper.text();

        expect(text).toContain('First Aid / CPR');
        expect(text).toContain('12 cards');
        expect(text).toContain('2 sheets');
    });

    it('marks a proof so one card in the list reads as intended', async () => {
        // "1 card" alone looks like a run that went wrong; "proof" says it
        // was the point.
        const { wrapper } = await mountWith([
            run({ id: 'r1', status: 'processing', card_count: 1, proof: true }),
        ]);

        expect(wrapper.text()).toContain('proof');
    });

    it('drops a run that succeeded — its sheets are in Documents', async () => {
        // This list tracks work in flight. A finished run has produced the
        // thing you actually wanted, and it is filed one section down;
        // leaving a receipt behind just accumulates furniture.
        const { wrapper } = await mountWith([
            run({ id: 'r1', status: 'done', card_count: 12, sheet_count: 2 }),
        ]);

        expect(wrapper.text()).toBe('');
    });

    it('keeps a failure on screen when a sibling run succeeded', async () => {
        const { wrapper } = await mountWith([
            run({ id: 'r1', status: 'done', topic_name: 'First Aid' }),
            run({
                id: 'r2',
                status: 'failed',
                topic_name: 'Forklift',
                error: 'No design.',
            }),
        ]);

        const text = wrapper.text();

        expect(text).toContain('Forklift');
        expect(text).not.toContain('First Aid');
    });

    it('shows why a run failed', async () => {
        const { wrapper } = await mountWith([
            run({
                id: 'r1',
                status: 'failed',
                error: 'The card design for this run is no longer available.',
            }),
        ]);

        // A run's failure reason is the only place this is ever explained —
        // the sheets simply never appear in Documents otherwise.
        expect(wrapper.text()).toContain('no longer available');
    });

    it('says nothing about runs that were already settled on arrival', async () => {
        await mountWith([run({ id: 'r1', status: 'done' })]);

        expect(toastSuccess).not.toHaveBeenCalled();
    });

    it('announces a run that finishes while you are watching', async () => {
        const { wrapper, store } = await mountWith([
            run({ id: 'r1', status: 'queued' }),
        ]);

        store.byClass = {
            c1: [run({ id: 'r1', status: 'done', sheet_count: 2 })],
        };
        await flushPromises();

        // The row leaves as it settles, so the toast is the whole handover —
        // it must fire off the store's runs, not off what's rendered.
        expect(toastSuccess).toHaveBeenCalled();
        expect(wrapper.text()).toBe('');
    });

    it('announces a failure the same way', async () => {
        const { store } = await mountWith([
            run({ id: 'r1', status: 'processing' }),
        ]);

        store.byClass = {
            c1: [run({ id: 'r1', status: 'failed', error: 'No qpdf.' })],
        };
        await flushPromises();

        expect(toastError).toHaveBeenCalled();
    });

    it('clears a run on request', async () => {
        const { wrapper, store } = await mountWith([
            run({ id: 'r1', status: 'failed', error: 'No design.' }),
        ]);
        const destroy = vi.spyOn(store, 'destroy').mockResolvedValue();

        await wrapper.get('[data-testid="clear-run"]').trigger('click');

        expect(destroy).toHaveBeenCalledWith('c1', 'r1');
    });

    it('offers no clear for a run still working', async () => {
        // Removing the record mid-flight would leave the job running with
        // nowhere to report what happened to it.
        const { wrapper } = await mountWith([
            run({ id: 'r1', status: 'processing' }),
        ]);

        expect(wrapper.find('[data-testid="clear-run"]').exists()).toBe(false);
    });

    it('subscribes so a queued run can finish on its own', async () => {
        const { subscribe } = await mountWith([run({ id: 'r1' })]);

        expect(subscribe).toHaveBeenCalledWith('org1');
    });

    it('says so when the list cannot be loaded', async () => {
        /*
         * Regression: mount awaited store.load() bare, so a failed fetch
         * (403, network blip) became an unhandled promise rejection with
         * nothing on screen — a run from an earlier session that failed
         * would simply never be mentioned.
         */
        setActivePinia(createPinia());
        const store = useCardPrintRunsStore();
        vi.spyOn(store, 'subscribe').mockImplementation(() => {});
        vi.spyOn(store, 'load').mockRejectedValue({
            response: { data: { message: 'Not allowed.' } },
        });

        mount(CardPrintRunsList, { props: { classId: 'c1' } });
        await flushPromises();

        expect(toastError).toHaveBeenCalledWith('Not allowed.');
    });

    it('falls back to a generic reason when the server gives none', async () => {
        setActivePinia(createPinia());
        const store = useCardPrintRunsStore();
        vi.spyOn(store, 'subscribe').mockImplementation(() => {});
        vi.spyOn(store, 'load').mockRejectedValue(new Error('network'));

        mount(CardPrintRunsList, { props: { classId: 'c1' } });
        await flushPromises();

        expect(toastError).toHaveBeenCalledWith(
            expect.stringContaining('print runs'),
        );
    });
});
