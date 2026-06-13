import { flushPromises, mount } from '@vue/test-utils';
import axios from 'axios';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import Dashboard from '@/pages/Dashboard.vue';
import { dashboardEndpoints } from '@/personas/fixtures';

/*
 * Persona: the boss (Owner). Same dashboard the managers live in —
 * the org-wide posture at a glance, every user one click away.
 * Backend half: tests/Feature/Personas/OwnerPersonaTest.php
 * (php artisan test --group=persona-owner) — including the user/role
 * management and owner-protection guarantees that have no page-level
 * counterpart here.
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
        props: { auth: { user: { id: 'u-owner', isOwner: true } } },
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

describe('persona: the boss (owner)', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        vi.clearAllMocks();
    });

    it('the owner flag alone unlocks the org widgets', async () => {
        const wrapper = await mountDashboard();

        expect(wrapper.text()).not.toContain(
            'The org dashboard is for Manager-or-higher roles',
        );
        expect(wrapper.text()).toContain(
            '8 user(s) · 12 active assignment(s) · 2 user(s) with overdue items',
        );
    });

    it('sees every user ranked by compliance, each one click away', async () => {
        const wrapper = await mountDashboard();
        const text = wrapper.text();

        expect(text).toContain('Olive Overdue');
        expect(text).toContain('Carl Current');
        expect(wrapper.find('a[href="/users/u-olive"]').exists()).toBe(true);
        expect(wrapper.find('a[href="/users/u-carl"]').exists()).toBe(true);
    });
});
