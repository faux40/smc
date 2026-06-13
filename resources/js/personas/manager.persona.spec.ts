import { flushPromises, mount } from '@vue/test-utils';
import axios from 'axios';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import Dashboard from '@/pages/Dashboard.vue';
import { dashboardEndpoints, needsActionRows } from '@/personas/fixtures';

/*
 * Persona: the training manager. The dashboard is their home base —
 * "who needs what, and when?" answered without leaving the page, with a
 * drill-down into any user. Backend half:
 * tests/Feature/Personas/TrainingManagerPersonaTest.php
 * (php artisan test --group=persona-manager).
 */

vi.mock('axios');
vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<div />' },
    // Inertia Link accepts a string or a wayfinder {url} object.
    Link: {
        props: ['href'],
        template: `<a :href="typeof href === 'string' ? href : href?.url"><slot /></a>`,
    },
    usePage: () => ({
        props: { auth: { user: { id: 'u-mgr', isManager: true } } },
    }),
}));
vi.mock('@/routes', () => ({ dashboard: () => '/dashboard' }));
vi.mock('@/routes/users', () => ({
    index: () => '/users',
    show: (id: string) => ({ url: `/users/${id}` }),
}));
vi.mock('@/composables/useRealtime', () => ({
    useRealtime: vi.fn(() => ({ bind: vi.fn(), leave: vi.fn() })),
}));

async function mountDashboard() {
    (axios.get as ReturnType<typeof vi.fn>).mockImplementation(
        dashboardEndpoints,
    );
    const wrapper = mount(Dashboard);
    await flushPromises();

    return wrapper;
}

describe('persona: training manager', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        vi.clearAllMocks();
    });

    it('gets the full widget dashboard, not the self-service hint', async () => {
        const wrapper = await mountDashboard();

        expect(wrapper.text()).not.toContain(
            'The org dashboard is for Manager-or-higher roles',
        );

        for (const url of [
            '/api/dashboard/summary',
            '/api/dashboard/needs-action',
            '/api/dashboard/users-compliance',
            '/api/dashboard/recent-completions',
        ]) {
            expect(axios.get).toHaveBeenCalledWith(url, expect.anything());
        }
    });

    it('reads the org posture from the summary cards', async () => {
        const wrapper = await mountDashboard();
        const text = wrapper.text();

        expect(text).toContain('Overdue');
        expect(text).toContain('Due soon');
        expect(text).toContain(
            '8 user(s) · 12 active assignment(s) · 2 user(s) with overdue items',
        );
    });

    it('sees who needs what — and when — in the needs-action list', async () => {
        const wrapper = await mountDashboard();
        const text = wrapper.text();

        expect(text).toContain('Olive Overdue');
        expect(text).toContain('Fall Protection');
        expect(text).toContain('OSHA General');
        // Forecasting: the due-soon row carries when it comes due.
        expect(text).toContain('Dana Duesoon');
        expect(text).toContain('Forklift');
    });

    it('can drill from a needs-action row into that user', async () => {
        const wrapper = await mountDashboard();

        const link = wrapper.find(
            `a[href="/users/${needsActionRows[0].user_id}"]`,
        );
        expect(link.exists()).toBe(true);
    });

    it('sees recent completions land as they happen', async () => {
        const wrapper = await mountDashboard();

        expect(wrapper.text()).toContain('Carl Current');
        expect(wrapper.text()).toContain('First Aid');
    });
});
