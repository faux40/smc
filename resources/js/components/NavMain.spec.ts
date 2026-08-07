import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import NavMain from '@/components/NavMain.vue';

/*
 * NavMain renders one labelled sidebar group. The label used to be hardcoded
 * to "Platform", which meant a second group could not exist — the reason the
 * Documents links sat loose in the flat list.
 */

vi.mock('@inertiajs/vue3', () => ({
    Link: {
        props: ['href'],
        template: `<a :href="typeof href === 'string' ? href : href?.url"><slot /></a>`,
    },
}));
vi.mock('@/composables/useCurrentUrl', () => ({
    useCurrentUrl: () => ({ isCurrentUrl: () => false }),
}));

const ITEMS = [{ title: 'Documents', href: '/documents', icon: 'span' }];

const stubs = {
    SidebarGroup: { template: '<div><slot /></div>' },
    SidebarGroupLabel: {
        template: '<div data-test="group-label"><slot /></div>',
    },
    SidebarMenu: { template: '<ul><slot /></ul>' },
    SidebarMenuItem: { template: '<li><slot /></li>' },
    SidebarMenuButton: { template: '<div><slot /></div>' },
};

const mountNav = (props = {}) =>
    mount(NavMain, { props: { items: ITEMS, ...props }, global: { stubs } });

describe('NavMain', () => {
    it('defaults its label to Platform so existing usage is unchanged', () => {
        expect(mountNav().get('[data-test="group-label"]').text()).toBe(
            'Platform',
        );
    });

    it('renders the label it is given', () => {
        expect(
            mountNav({ label: 'Documents' })
                .get('[data-test="group-label"]')
                .text(),
        ).toBe('Documents');
    });

    it('still renders its items', () => {
        expect(mountNav({ label: 'Documents' }).text()).toContain('Documents');
    });
});
