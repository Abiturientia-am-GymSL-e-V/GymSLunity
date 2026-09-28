<script setup lang="ts">
import { Link, router, useForm } from '@inertiajs/vue3';
import { FileDown, Printer, Search } from '@lucide/vue';
import { computed, ref } from 'vue';
import PaymentsPage from '@/components/payments/PaymentsPage.vue';
import SearchableDropdown from '@/components/SearchableDropdown.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatDate, formatMoney } from '@/lib/format';
import type { Transaction } from '@/types/payments';

const props = defineProps<{
    filters: { from: string; to: string };
    summary: {
        missing_mandates: number;
        open_count: number;
        open_cents: number;
        contribution_count: number;
        contribution_cents: number;
        paid_cents: number;
    };
    transactions: Transaction[];
}>();
defineOptions({
    layout: { breadcrumbs: [{ title: 'Beiträge', href: '/beitraege' }] },
});

const filterForm = useForm({ from: props.filters.from, to: props.filters.to });
function filter() {
    router.get('/beitraege', filterForm.data(), {
        preserveState: true,
        preserveScroll: true,
    });
}
const kinds: Record<string, string> = {
    contribution: 'Beitrag',
    return_debit_fee: 'Rücklastschriftgebühr',
    manual_payment: 'Manuelle Zahlung',
    bank_payment: 'Bankimport',
    sepa_payment: 'SEPA-Zahlung',
    bank_return_debit: 'Rücklastschrift',
    manual_charge: 'Manuelle Forderung',
    booking: 'Ressourcenbuchung',
    booking_refund: 'Buchungsstorno',
};
const transactionQuery = ref('');
const transactionKind = ref('all');
const transactionDirection = ref('all');
const transactionKindOptions = computed(() => [
    { value: 'all', label: 'Alle Buchungsarten' },
    ...[...new Set(props.transactions.map((entry) => entry.kind))]
        .sort((a, b) => (kinds[a] || a).localeCompare(kinds[b] || b, 'de'))
        .map((value) => ({ value, label: kinds[value] || value })),
]);
const transactionDirectionOptions = [
    { value: 'all', label: 'Belastungen und Gutschriften' },
    { value: 'charge', label: 'Nur Belastungen' },
    { value: 'credit', label: 'Nur Gutschriften' },
];
const filteredTransactions = computed(() => {
    const query = transactionQuery.value.trim().toLocaleLowerCase('de');
    return props.transactions.filter((entry) => {
        const matchesQuery =
            !query ||
            [
                entry.member_name,
                String(entry.member_number),
                entry.description,
                entry.reference || '',
            ]
                .join(' ')
                .toLocaleLowerCase('de')
                .includes(query);
        const matchesKind =
            transactionKind.value === 'all' ||
            entry.kind === transactionKind.value;
        const matchesDirection =
            transactionDirection.value === 'all' ||
            (transactionDirection.value === 'charge'
                ? entry.amount_cents > 0
                : entry.amount_cents < 0);
        return matchesQuery && matchesKind && matchesDirection;
    });
});
function transactionReportUrl(format: 'csv' | 'print') {
    const params = new URLSearchParams({
        from: props.filters.from,
        to: props.filters.to,
        format,
    });
    if (transactionQuery.value.trim()) {
        params.set('q', transactionQuery.value.trim());
    }
    if (transactionKind.value !== 'all') {
        params.set('kind', transactionKind.value);
    }
    if (transactionDirection.value !== 'all') {
        params.set('direction', transactionDirection.value);
    }
    return `/beitraege/kontobuchungen/export?${params.toString()}`;
}
</script>

<template>
    <PaymentsPage active="overview">
        <form
            class="grid gap-3 rounded-xl border bg-card p-4 sm:grid-cols-2 2xl:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto] 2xl:items-end"
            @submit.prevent="filter"
        >
            <div class="min-w-0 space-y-2">
                <Label for="filter-from">Von</Label
                ><Input
                    id="filter-from"
                    v-model="filterForm.from"
                    type="date"
                    class="date-safe"
                />
            </div>
            <div class="min-w-0 space-y-2">
                <Label for="filter-to">Bis</Label
                ><Input
                    id="filter-to"
                    v-model="filterForm.to"
                    type="date"
                    class="date-safe"
                />
            </div>
            <Button
                type="submit"
                variant="outline"
                class="w-full sm:col-span-2 2xl:col-span-1 2xl:w-auto"
                >Zeitraum anwenden</Button
            >
        </form>
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <Link
                href="/beitraege/mandate"
                class="rounded-xl border bg-card p-5 text-left"
            >
                <span class="text-sm text-muted-foreground"
                    >Fehlende SEPA-Mandate</span
                ><strong class="mt-2 block text-3xl">{{
                    summary.missing_mandates
                }}</strong>
            </Link>
            <div class="rounded-xl border bg-card p-5">
                <span class="text-sm text-muted-foreground"
                    >Offene Beiträge</span
                ><strong class="mt-2 block text-3xl">{{
                    formatMoney(summary.open_cents)
                }}</strong
                ><small>{{ summary.open_count }} Posten</small>
            </div>
            <div class="rounded-xl border bg-card p-5">
                <span class="text-sm text-muted-foreground"
                    >Beiträge im Zeitraum</span
                ><strong class="mt-2 block text-3xl">{{
                    formatMoney(summary.contribution_cents)
                }}</strong
                ><small>{{ summary.contribution_count }} Beiträge</small>
            </div>
            <div class="rounded-xl border bg-card p-5">
                <span class="text-sm text-muted-foreground">Davon bezahlt</span
                ><strong class="mt-2 block text-3xl text-emerald-700">{{
                    formatMoney(summary.paid_cents)
                }}</strong>
            </div>
        </div>
        <section class="overflow-hidden rounded-xl border bg-card">
            <div class="space-y-4 border-b p-5">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h2 class="font-semibold">Kontobuchungen</h2>
                        <p class="text-sm text-muted-foreground">
                            {{ filteredTransactions.length }} von
                            {{ transactions.length }} Buchungen
                        </p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <Button as-child variant="outline">
                            <a :href="transactionReportUrl('csv')">
                                <FileDown class="size-4" />CSV
                            </a>
                        </Button>
                        <Button as-child variant="outline">
                            <a
                                :href="transactionReportUrl('print')"
                                target="_blank"
                            >
                                <Printer class="size-4" />Drucken
                            </a>
                        </Button>
                    </div>
                </div>
                <div class="grid gap-3 md:grid-cols-3">
                    <div class="relative min-w-0">
                        <Search
                            class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                        />
                        <Input
                            v-model="transactionQuery"
                            type="search"
                            class="pl-9"
                            placeholder="Mitglied, Beschreibung oder Referenz"
                            aria-label="Kontobuchungen durchsuchen"
                        />
                    </div>
                    <SearchableDropdown
                        id="transaction-kind-filter"
                        v-model="transactionKind"
                        :options="transactionKindOptions"
                        aria-label="Buchungsart filtern"
                        search-placeholder="Buchungsart suchen"
                        trigger-class="h-9 w-full rounded-md border border-input bg-background px-3"
                    />
                    <SearchableDropdown
                        id="transaction-direction-filter"
                        v-model="transactionDirection"
                        :options="transactionDirectionOptions"
                        aria-label="Buchungsrichtung filtern"
                        search-placeholder="Buchungsrichtung suchen"
                        trigger-class="h-9 w-full rounded-md border border-input bg-background px-3"
                    />
                </div>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left">
                        <tr>
                            <th class="p-3">Datum</th>
                            <th class="p-3">Mitglied</th>
                            <th class="p-3">Art</th>
                            <th class="p-3">Beschreibung</th>
                            <th class="p-3 text-right">Betrag</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr
                            v-for="entry in filteredTransactions"
                            :key="entry.id"
                        >
                            <td class="p-3">
                                {{ formatDate(entry.booking_date) }}
                            </td>
                            <td class="p-3">
                                <a
                                    class="font-medium hover:underline"
                                    :href="'/mitglieder/' + entry.member_number"
                                    >{{ entry.member_name }}</a
                                ><small class="block"
                                    >Nr. {{ entry.member_number }}</small
                                >
                            </td>
                            <td class="p-3">
                                {{ kinds[entry.kind] || entry.kind }}
                            </td>
                            <td class="p-3">
                                {{ entry.description
                                }}<small v-if="entry.reference" class="block">{{
                                    entry.reference
                                }}</small>
                            </td>
                            <td
                                class="p-3 text-right font-medium"
                                :class="{
                                    'text-emerald-700': entry.amount_cents < 0,
                                }"
                            >
                                {{ formatMoney(entry.amount_cents) }}
                            </td>
                        </tr>
                        <tr v-if="!filteredTransactions.length">
                            <td
                                colspan="5"
                                class="p-8 text-center text-muted-foreground"
                            >
                                Keine passenden Buchungen im Zeitraum.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </PaymentsPage>
</template>
