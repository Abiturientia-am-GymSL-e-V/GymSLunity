<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import {
    Building2,
    HandHeart,
    ListFilter,
    Mail,
    ServerCog,
    ShieldCheck,
    UsersRound,
} from '@lucide/vue';
import { edit } from '@/routes/configuration/club';
import { index as fields } from '@/routes/configuration/fields';
import { index as users } from '@/routes/configuration/users';
const page = usePage();
const links = [
    { label: 'Vereinsdaten', url: edit.url(), icon: Building2 },
    { label: 'Mitgliedsfelder', url: fields.url(), icon: ListFilter },
    { label: 'Benutzer & Rechte', url: users.url(), icon: UsersRound },
    { label: 'E-Mail-Versand', url: '/konfiguration/email', icon: Mail },
    {
        label: 'Selfservice & Formulare',
        url: '/konfiguration/selfservice',
        icon: UsersRound,
    },
    { label: 'Spenden', url: '/konfiguration/spenden', icon: HandHeart },
    {
        label: 'Sicherheitsprotokoll',
        url: '/konfiguration/sicherheitsprotokoll',
        icon: ShieldCheck,
    },
    { label: 'System', url: '/konfiguration/system', icon: ServerCog },
];
</script>

<template>
    <nav aria-label="Konfiguration" class="flex flex-wrap gap-2 border-b pb-4">
        <Link
            v-for="link in links"
            :key="link.url"
            :href="link.url"
            :aria-current="page.url.startsWith(link.url) ? 'page' : undefined"
            class="flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-medium transition-colors hover:bg-muted"
            :class="
                page.url.startsWith(link.url)
                    ? 'bg-muted text-foreground'
                    : 'text-muted-foreground'
            "
            ><component :is="link.icon" class="size-4" />{{ link.label }}</Link
        >
    </nav>
</template>
