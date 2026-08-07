import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import AppSidebar from '@/components/AppSidebar.vue';
import NavMain from '@/components/NavMain.vue';

/*
 * Sidebar grouping (partial F40).
 *
 * The Documents module owns three pages — Documents, Document data and Cards —
 * which sat loose in the flat Platform list. They now render as their own
 * labelled group, so the module reads as one thing and later modules can land
 * the same way.
 *
 * These assert the grouping, not the markup: which titles land in which group,
 * and that the role gate survived the move. The visual result is checked
 * against the running app.
 */

type Role = 'owner' | 'admin' | 'manager' | 'user';

const FLAGS: Record<Role, Record<string, boolean>> = {
    owner: { isOwner: true },
    admin: { isAdmin: true },
    manager: { isManager: true },
    user: {},
};

let role: Role = 'owner';

vi.mock('@inertiajs/vue3', () => ({
    Link: {
        props: ['href'],
        template: `<a :href="typeof href === 'string' ? href : href?.url"><slot /></a>`,
    },
    usePage: () => ({
        props: { auth: { user: { id: 'u-1', ...FLAGS[role] } } },
    }),
}));

vi.mock('@/routes', () => ({ dashboard: () => '/dashboard' }));
vi.mock('@/routes/assignments', () => ({ page: () => '/assignments' }));
vi.mock('@/routes/cards', () => ({ page: () => '/cards' }));
vi.mock('@/routes/classes', () => ({ page: () => '/classes' }));
vi.mock('@/routes/completions', () => ({ page: () => '/completions' }));
vi.mock('@/routes/documents', () => ({
    page: () => '/documents',
    data: () => '/documents/data',
}));
vi.mock('@/routes/requirements', () => ({ page: () => '/requirements' }));
vi.mock('@/routes/tags', () => ({ page: () => '/tags' }));
vi.mock('@/routes/trainings', () => ({ page: () => '/trainings' }));
vi.mock('@/routes/users', () => ({ index: () => '/users' }));
vi.mock('@/composables/useCurrentUrl', () => ({
    useCurrentUrl: () => ({ isCurrentUrl: () => false }),
}));

const passthrough = { template: '<div><slot /></div>' };
const stubs = {
    Sidebar: passthrough,
    SidebarContent: passthrough,
    SidebarHeader: passthrough,
    SidebarFooter: passthrough,
    SidebarMenu: passthrough,
    SidebarMenuItem: passthrough,
    SidebarMenuButton: passthrough,
    AppLogo: true,
    NavUser: true,
    NavMain: true,
};

const DOCUMENT_TITLES = ['Documents', 'Document data', 'Cards'];

/** The rendered groups, as { label, titles } — the thing this change is about. */
function groups(currentRole: Role) {
    role = currentRole;
    const wrapper = mount(AppSidebar, { global: { stubs } });

    return wrapper.findAllComponents(NavMain).map((nav) => ({
        label: (nav.props('label') as string) ?? 'Platform',
        titles: (nav.props('items') as { title: string }[]).map((i) => i.title),
    }));
}

const group = (all: ReturnType<typeof groups>, label: string) =>
    all.find((g) => g.label === label);

beforeEach(() => {
    role = 'owner';
});

describe('the Documents group', () => {
    it.each(['owner', 'admin', 'manager'] as Role[])(
        'holds exactly the three module pages for %s',
        (r) => {
            expect(group(groups(r), 'Documents')?.titles).toEqual(
                DOCUMENT_TITLES,
            );
        },
    );

    it('is gone from the Platform list, not duplicated into both', () => {
        const platform = group(groups('owner'), 'Platform');

        for (const title of DOCUMENT_TITLES) {
            expect(platform?.titles).not.toContain(title);
        }
    });

    it('keeps Platform intact otherwise', () => {
        // Guards against splitting the array at the wrong index and dropping
        // Classes, the entry immediately above the Documents block.
        expect(group(groups('owner'), 'Platform')?.titles).toEqual([
            'Dashboard',
            'Compliance',
            'Reports',
            'Users',
            'Trainings',
            'Requirements',
            'Tags',
            'Assignments',
            'Completions',
            'Classes',
        ]);
    });
});

describe('the role gate survived the move', () => {
    it('is absent entirely below Manager — no empty header', () => {
        const all = groups('user');

        expect(group(all, 'Documents')).toBeUndefined();
        expect(all.map((g) => g.label)).toEqual(['Platform']);
    });

    it('leaves a plain user their Dashboard only', () => {
        expect(group(groups('user'), 'Platform')?.titles).toEqual([
            'Dashboard',
        ]);
    });
});
