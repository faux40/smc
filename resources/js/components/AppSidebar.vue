<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import {
    CalendarDays,
    CheckCircle2,
    ClipboardCheck,
    ClipboardList,
    GraduationCap,
    LayoutGrid,
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
import { page as classesPage } from '@/routes/classes';
import { page as completionsPage } from '@/routes/completions';
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
        </SidebarContent>

        <SidebarFooter>
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
