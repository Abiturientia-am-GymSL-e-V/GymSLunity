<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    ArrowDownToLine,
    FilePlus2,
    Landmark,
    List,
    RefreshCcw,
} from '@lucide/vue';

defineProps<{
    active: 'overview' | 'create' | 'sepa' | 'bank' | 'returns';
}>();

const tabs = [
    {
        key: 'overview',
        label: 'Übersicht',
        href: '/buchhaltung/rechnungen',
        icon: List,
    },
    {
        key: 'create',
        label: 'Rechnung erstellen',
        href: '/buchhaltung/rechnungen/anlegen',
        icon: FilePlus2,
    },
    {
        key: 'sepa',
        label: 'SEPA-Export',
        href: '/buchhaltung/rechnungen/sepa-export',
        icon: ArrowDownToLine,
    },
    {
        key: 'bank',
        label: 'Bankimport',
        href: '/buchhaltung/rechnungen/bankimport',
        icon: Landmark,
    },
    {
        key: 'returns',
        label: 'Rücklastschriften',
        href: '/buchhaltung/rechnungen/ruecklastschriften',
        icon: RefreshCcw,
    },
] as const;
</script>

<template>
    <nav class="flex flex-wrap gap-2 border-b pb-4" aria-label="Rechnungswesen">
        <Link
            v-for="tab in tabs"
            :key="tab.key"
            :href="tab.href"
            :aria-current="active === tab.key ? 'page' : undefined"
            prefetch
            class="flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-medium transition-colors hover:bg-muted"
            :class="
                active === tab.key
                    ? 'bg-muted text-foreground'
                    : 'text-muted-foreground'
            "
        >
            <component :is="tab.icon" class="size-4" aria-hidden="true" />
            {{ tab.label }}
        </Link>
    </nav>
</template>
