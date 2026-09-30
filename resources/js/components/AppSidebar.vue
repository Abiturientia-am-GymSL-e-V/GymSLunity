<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import {
    BookOpen,
    FolderGit2,
    LayoutGrid,
    UsersRound,
    Settings2,
    MessageSquareText,
    HandCoins,
    ChartNoAxesCombined,
    Landmark,
    FileText,
    HeartHandshake,
    Boxes,
    Info,
    CalendarDays,
    CalendarRange,
    ScrollText,
    Crown,
    Network,
    Award,
} from '@lucide/vue';
import { computed } from 'vue';
import AppLogo from '@/components/AppLogo.vue';
import NavFooter from '@/components/NavFooter.vue';
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
    SidebarSeparator,
} from '@/components/ui/sidebar';
import {
    dashboard,
    donations,
    finance,
    forms,
    inventory,
    kommunikation,
    payments,
    statistics,
} from '@/routes';
import { departments, honors, offices } from '@/routes/assignments';
import { index as auditIndex } from '@/routes/audit';
import { index as members } from '@/routes/members';
import { edit as clubSettings } from '@/routes/configuration/club';
import type { NavItem } from '@/types';

const page = usePage();
const mainNavItems = computed<NavItem[]>(() => [
    {
        title: 'Übersicht',
        href: dashboard(),
        icon: LayoutGrid,
    },
    ...(page.props.can.viewMembers
        ? [{ title: 'Mitglieder', href: members(), icon: UsersRound }]
        : []),
    ...(page.props.can.viewAssignments.includes('office')
        ? [
              {
                  title: 'Vorstand / Ämter',
                  href: offices(),
                  icon: Crown,
                  isActive: page.url.startsWith('/aemter'),
              },
          ]
        : []),
    ...(page.props.can.viewAssignments.includes('department')
        ? [{ title: 'Abteilungen', href: departments(), icon: Network }]
        : []),
    ...(page.props.can.viewAssignments.includes('honor')
        ? [{ title: 'Ereignisse / Ehrungen', href: honors(), icon: Award }]
        : []),
    ...(page.props.can.viewPayments
        ? [{ title: 'Beiträge', href: payments(), icon: HandCoins }]
        : []),
    ...(page.props.can.viewStatistics
        ? [
              {
                  title: 'Auswertungen',
                  href: statistics(),
                  icon: ChartNoAxesCombined,
              },
          ]
        : []),
    ...(page.props.can.viewFinance
        ? [{ title: 'Buchhaltung', href: finance(), icon: Landmark }]
        : []),
    ...(page.props.can.viewForms
        ? [{ title: 'Formulare', href: forms(), icon: FileText }]
        : []),
    ...(page.props.can.viewDonations
        ? [{ title: 'Spenden', href: donations(), icon: HeartHandshake }]
        : []),
    ...(page.props.can.viewInventory
        ? [{ title: 'Inventar', href: inventory(), icon: Boxes }]
        : []),
    ...(page.props.can.viewCalendar
        ? [{ title: 'Kalender', href: '/kalender', icon: CalendarDays }]
        : []),
    ...(page.props.can.viewBookings
        ? [{ title: 'Buchungen', href: '/buchungen', icon: CalendarRange }]
        : []),
    ...(page.props.can.viewCommunication
        ? [
              {
                  title: 'Kommunikation',
                  href: kommunikation(),
                  icon: MessageSquareText,
              },
          ]
        : []),
    ...(page.props.can.viewAudit
        ? [
              {
                  title: 'Auditlog',
                  href: auditIndex(),
                  icon: ScrollText,
              },
          ]
        : []),
    ...(page.props.can.manageConfiguration
        ? [
              {
                  title: 'Konfiguration',
                  href: clubSettings(),
                  icon: Settings2,
                  isActive: page.url.startsWith('/konfiguration'),
              },
          ]
        : []),
]);

const footerNavItems: NavItem[] = [
    {
        title: 'Über GymSLunity',
        href: '/ueber-gymslunity',
        icon: Info,
    },
    {
        title: 'Dokumentation',
        href: 'https://github.com/Abiturientia-am-GymSL-e-V/GymSLunity',
        icon: BookOpen,
    },
];
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
            <div
                v-if="page.props.logoUrl || page.props.clubName"
                class="group-data-[collapsible=icon]:hidden"
            >
                <SidebarSeparator class="my-3" />
                <div
                    class="flex flex-col items-center gap-2 px-4 py-2 text-center"
                >
                    <img
                        v-if="page.props.logoUrl"
                        :src="page.props.logoUrl"
                        :alt="`${page.props.clubName ?? 'Verein'} – Vereinslogo`"
                        class="max-h-24 max-w-full object-contain"
                    />
                    <span
                        v-if="page.props.clubName"
                        class="max-w-full text-sm font-semibold break-words"
                        >{{ page.props.clubName }}</span
                    >
                </div>
                <SidebarSeparator class="my-3" />
            </div>
            <NavMain :items="mainNavItems" />
        </SidebarContent>

        <SidebarFooter>
            <NavFooter :items="footerNavItems" />
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
