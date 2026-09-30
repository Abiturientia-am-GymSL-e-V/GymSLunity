<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import {
    CalendarRange,
    FileUp,
    UserMinus,
    UserPlus,
    UsersRound,
} from '@lucide/vue';
import { create, index } from '@/routes/members';
import { index as importAssignments } from '@/routes/members/assignment-import';
import { index as importMembers } from '@/routes/members/import';

const page = usePage();
const links = [
    { label: 'Verzeichnis', url: index.url(), icon: UsersRound, visible: true },
    {
        label: 'Beitrittsanträge',
        url: '/mitglieder/antraege',
        icon: UserPlus,
        visible: page.props.can.createMembers,
    },
    {
        label: 'Kündigungen',
        url: '/mitglieder/kuendigungen',
        icon: UserMinus,
        visible: page.props.can.createMembers,
    },
    {
        label: 'Mitglied anlegen',
        url: create.url(),
        icon: UserPlus,
        visible: page.props.can.createMembers,
    },
    {
        label: 'Mitglieder importieren',
        url: importMembers.url(),
        icon: FileUp,
        visible: page.props.can.createMembers,
    },
    {
        label: 'Zuordnungen importieren',
        url: importAssignments.url(),
        icon: CalendarRange,
        visible:
            page.props.can.manageAssignments &&
            page.props.can.viewAssignments.length > 0,
    },
];
</script>

<template>
    <nav aria-label="Mitglieder" class="flex flex-wrap gap-2 border-b pb-4">
        <Link
            v-for="link in links.filter((item) => item.visible)"
            :key="link.url"
            :href="link.url"
            :aria-current="
                page.url.split('?')[0] === link.url ? 'page' : undefined
            "
            class="flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-medium transition-colors hover:bg-muted"
            :class="
                page.url.split('?')[0] === link.url
                    ? 'bg-muted text-foreground'
                    : 'text-muted-foreground'
            "
            ><component :is="link.icon" class="size-4" />{{ link.label }}</Link
        >
    </nav>
</template>
