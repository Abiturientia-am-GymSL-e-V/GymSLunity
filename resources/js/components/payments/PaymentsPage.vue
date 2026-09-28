<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import {
    ArrowDownToLine,
    Banknote,
    BellRing,
    CalendarPlus,
    FileText,
    Landmark,
    ReceiptText,
    RefreshCcw,
    WalletCards,
} from '@lucide/vue';
import type { PaymentsTab } from '@/types/payments';

defineProps<{ active: PaymentsTab }>();

const tabs = [
    ['overview', 'Übersicht', WalletCards, '/beitraege'],
    ['mandates', 'Mandatsverwaltung', FileText, '/beitraege/mandate'],
    ['create', 'Beiträge anlegen', CalendarPlus, '/beitraege/anlegen'],
    ['invoices', 'Beitragsrechnungen', ReceiptText, '/beitraege/rechnungen'],
    ['dunning', 'Mahnwesen', BellRing, '/beitraege/mahnwesen'],
    ['sepa', 'SEPA-Export', ArrowDownToLine, '/beitraege/sepa-export'],
    ['bank', 'Bankimport', Landmark, '/beitraege/bankimport'],
    [
        'returns',
        'Rücklastschriften',
        RefreshCcw,
        '/beitraege/ruecklastschriften',
    ],
    ['manual', 'Manuell buchen', Banknote, '/beitraege/manuell-buchen'],
] as const;
</script>

<template>
    <Head title="Beiträge" />
    <div class="mx-auto w-full max-w-[1200px] space-y-6 p-4 sm:p-6">
        <header>
            <h1 class="text-2xl font-semibold tracking-tight">Beiträge</h1>
            <p class="mt-1 text-sm text-muted-foreground">
                Beiträge festsetzen, abrechnen, einziehen und verbuchen.
            </p>
        </header>
        <nav
            class="flex flex-wrap gap-2 border-b pb-4"
            aria-label="Beitragsbereiche"
        >
            <Link
                v-for="tab in tabs"
                :key="tab[0]"
                :href="tab[3]"
                :aria-current="active === tab[0] ? 'page' : undefined"
                prefetch
                class="flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-medium transition-colors hover:bg-muted"
                :class="
                    active === tab[0]
                        ? 'bg-muted text-foreground'
                        : 'text-muted-foreground'
                "
            >
                <component :is="tab[2]" class="size-4" />{{ tab[1] }}
            </Link>
        </nav>
        <slot />
    </div>
</template>
