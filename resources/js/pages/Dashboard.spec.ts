import { mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import Dashboard from '@/pages/Dashboard.vue';

vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<div />' },
    usePage: () => ({
        props: { auth: { user: { id: 'me', isAdmin: true } } },
    }),
}));
vi.mock('@/routes', () => ({ dashboard: () => '/dashboard' }));
vi.mock('@/routes/users', () => ({ show: (id: string) => ({ url: `/users/${id}` }) }));

// NeedsActionWidget and SummaryStatsWidget already have their own specs —
// this file only covers the F7 cross-widget wiring in Dashboard.vue, so
// both are replaced with minimal stand-ins that expose just enough surface
// to prove the wiring: the "record a completion" event goes in, the
// summary widget's exposed refresh() comes out.
const NeedsActionWidgetStub = {
    emits: ['completion-recorded'],
    template:
        '<button data-test="fire-completion-recorded" type="button" @click="$emit(\'completion-recorded\')" />',
};

const refreshSpy = vi.fn().mockResolvedValue(undefined);
const SummaryStatsWidgetStub = {
    setup(_props: unknown, { expose }: { expose: (e: object) => void }) {
        expose({ refresh: refreshSpy });

        return {};
    },
    template: '<div data-test="summary-stub" />',
};

const STUBS = {
    AllUsersComplianceWidget: true,
    RecentCompletionsWidget: true,
    NeedsActionWidget: NeedsActionWidgetStub,
    SummaryStatsWidget: SummaryStatsWidgetStub,
};

describe('Dashboard — nudges the summary cards after a needs-action completion (F7)', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        vi.clearAllMocks();
    });

    it('calls the summary widget\'s exposed refresh() when NeedsActionWidget emits completion-recorded', async () => {
        const wrapper = mount(Dashboard, { global: { stubs: STUBS } });

        await wrapper.find('[data-test="fire-completion-recorded"]').trigger('click');

        expect(refreshSpy).toHaveBeenCalledTimes(1);
    });
});
