<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import {
    CalendarDays,
    CheckCircle2,
    ClipboardCheck,
    ClipboardList,
    Database,
    FileStack,
    FileText,
    GraduationCap,
    IdCard,
    LayoutGrid,
    ShieldCheck,
    Tags as TagsIcon,
    Users,
} from 'lucide-vue-next';
import { computed } from 'vue';
import AppLogo from '@/components/AppLogo.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { dashboard } from '@/routes';
import { page as assignmentsPage } from '@/routes/assignments';
import { page as cardsPage } from '@/routes/cards';
import { page as classesPage } from '@/routes/classes';
import { page as completionsPage } from '@/routes/completions';
import {
    data as documentDataPage,
    page as documentsPage,
} from '@/routes/documents';
import { page as requirementsPage } from '@/routes/requirements';
import { page as tagsPage } from '@/routes/tags';
import { page as trainingsPage } from '@/routes/trainings';
import { index as usersIndex } from '@/routes/users';
import type { NavItem } from '@/types';

const page = usePage();
const authUser = computed(
    () =>
        page.props.auth.user as {
            isOwner?: boolean;
            isSuperAdmin?: boolean;
            isAdmin?: boolean;
            isManager?: boolean;
        } | null,
);

const mainNavItems = computed<NavItem[]>(() => {
    const items: NavItem[] = [
        {
            title: 'Dashboard',
            href: dashboard(),
            icon: LayoutGrid,
        },
    ];

    const u = authUser.value;

    // Compliance roll-ups sit right under Dashboard, Manager+ (matches the
    // dashboard widgets + the Compliance controller gate).
    if (u && (u.isOwner || u.isSuperAdmin || u.isAdmin || u.isManager)) {
        items.push({
            title: 'Compliance',
            href: '/compliance',
            icon: ShieldCheck,
        });
        items.push({
            title: 'Reports',
            href: '/reports',
            icon: FileText,
        });
    }

    if (u && (u.isOwner || u.isSuperAdmin || u.isAdmin)) {
        items.push({
            title: 'Users',
            href: usersIndex(),
            icon: Users,
        });
        items.push({
            title: 'Trainings',
            href: trainingsPage(),
            icon: GraduationCap,
        });
        items.push({
            title: 'Requirements',
            href: requirementsPage(),
            icon: ClipboardList,
        });
        items.push({ title: 'Tags', href: tagsPage(), icon: TagsIcon });
    }

    // Manager+ admin entries — manual single-record entry pages and
    // the bulk-assignment workflow. Matches the widened Assignment /
    // Completion policies (Phases 13.1 + 13.2).
    if (u && (u.isOwner || u.isSuperAdmin || u.isAdmin || u.isManager)) {
        items.push({
            title: 'Assignments',
            href: assignmentsPage(),
            icon: ClipboardCheck,
        });
        items.push({
            title: 'Completions',
            href: completionsPage(),
            icon: CheckCircle2,
        });
        items.push({
            title: 'Classes',
            href: classesPage(),
            icon: CalendarDays,
        });
    }

    return items;
});

/*
 * The Documents module gets its own labelled group rather than three more
 * entries on the flat list (F40): template generation (D1/D2), the org merge
 * data feeding it, and cards (custom-certs C2) are one thing, and reading as
 * one thing is the point. Later modules land the same way.
 *
 * Same Manager+ gate the block carried inside mainNavItems. Empty for everyone
 * below that, and the template drops the whole group rather than render a
 * heading over nothing.
 */
const documentNavItems = computed<NavItem[]>(() => {
    const u = authUser.value;

    if (!u || !(u.isOwner || u.isSuperAdmin || u.isAdmin || u.isManager)) {
        return [];
    }

    return [
        { title: 'Documents', href: documentsPage(), icon: FileStack },
        { title: 'Document data', href: documentDataPage(), icon: Database },
        { title: 'Cards', href: cardsPage(), icon: IdCard },
    ];
});
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="dashboard()">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <NavMain :items="mainNavItems" />
            <NavMain
                v-if="documentNavItems.length"
                :items="documentNavItems"
                label="Documents"
            />
        </SidebarContent>

        <SidebarFooter>
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
