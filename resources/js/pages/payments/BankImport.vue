<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { Upload } from '@lucide/vue';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import PaymentsPage from '@/components/payments/PaymentsPage.vue';
import SearchableDropdown from '@/components/SearchableDropdown.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { formatDate, formatMoney } from '@/lib/format';
import type {
    BankImportRow,
    PaymentMember,
    RecentImport,
} from '@/types/payments';

const props = defineProps<{
    members: PaymentMember[];
    recentImports: RecentImport[];
    unmatchedBankRows: BankImportRow[];
}>();
defineOptions({
    layout: { breadcrumbs: [{ title: 'Beiträge', href: '/beitraege' }] },
});

const bankForm = useForm<{ csv: File | null }>({ csv: null });
const bankAssignments = ref<Record<number, string>>({});
const bankRowBusy = ref<number | null>(null);
const memberSearchOptions = computed(() =>
    props.members.map((member) => ({
        value: String(member.member_number),
        label: `${member.last_name}, ${member.first_name}`,
        suffix: `Nr. ${member.member_number}`,
        search: `${member.first_name} ${member.last_name} ${member.member_number}`,
    })),
);
function assignBankRow(row: BankImportRow) {
    const memberNumber = bankAssignments.value[row.id];
    if (!memberNumber) return;
    bankRowBusy.value = row.id;
    router.post(
        `/beitraege/bankimport/${row.payment_import_id}/zeilen/${row.id}/zuordnen`,
        { member_number: memberNumber },
        { preserveScroll: true, onFinish: () => (bankRowBusy.value = null) },
    );
}
function ignoreBankRow(row: BankImportRow) {
    bankRowBusy.value = row.id;
    router.post(
        `/beitraege/bankimport/${row.payment_import_id}/zeilen/${row.id}/ignorieren`,
        {},
        { preserveScroll: true, onFinish: () => (bankRowBusy.value = null) },
    );
}
</script>

<template>
    <PaymentsPage active="bank">
        <div class="grid items-start gap-5 lg:grid-cols-2">
            <form
                class="space-y-5 rounded-xl border bg-card p-5"
                @submit.prevent="
                    bankForm.post('/beitraege/bankimport', {
                        preserveScroll: true,
                    })
                "
            >
                <div class="space-y-2">
                    <h2 class="font-semibold">SEPA-Umsatzliste importieren</h2>
                    <p class="text-sm text-muted-foreground">
                        CSV mit Datum/Buchungsdatum und Betrag; Zuordnung über
                        Mitglieds-, Rechnungs- oder Mandatsnummer.
                    </p>
                </div>
                <div class="min-w-0 space-y-2">
                    <Label for="bank-csv">CSV-Datei</Label
                    ><Input
                        id="bank-csv"
                        type="file"
                        accept=".csv,text/csv,text/plain"
                        @change="
                            bankForm.csv =
                                ($event.target as HTMLInputElement)
                                    .files?.[0] || null
                        "
                    /><InputError :message="bankForm.errors.csv" />
                </div>
                <Button
                    type="submit"
                    :disabled="!bankForm.csv || bankForm.processing"
                    ><Spinner v-if="bankForm.processing" /><Upload
                        v-else
                        class="size-4"
                    />Importieren</Button
                >
            </form>
            <section class="rounded-xl border bg-card">
                <h2 class="border-b p-5 font-semibold">Letzte Importe</h2>
                <ul class="divide-y">
                    <li
                        v-for="item in recentImports"
                        :key="item.id"
                        class="p-4"
                    >
                        <strong>{{ item.original_name }}</strong
                        ><small class="block"
                            >{{ item.imported_count }}/{{
                                item.row_count
                            }}
                            verbucht · {{ item.unmatched_count }} offen ·
                            {{ formatDate(item.created_at) }}</small
                        >
                    </li>
                    <li
                        v-if="!recentImports.length"
                        class="p-5 text-muted-foreground"
                    >
                        Noch keine Importe.
                    </li>
                </ul>
            </section>
            <section class="rounded-xl border bg-card lg:col-span-2">
                <div class="border-b p-5">
                    <h2 class="font-semibold">Offene Bankbuchungen</h2>
                    <p class="mt-1 text-sm text-muted-foreground">
                        Automatisch abgeglichen wird – in dieser Reihenfolge –
                        über Zahlungsreferenz, Rechnungsnummer, eine explizite
                        Mitgliedsnummer, eine Mitgliedsnummer im Text oder die
                        Mandatsreferenz. Negative Beträge werden dabei als
                        Rücklastschrift erkannt und öffnen den betroffenen
                        Beitrag wieder.
                    </p>
                </div>
                <div class="divide-y">
                    <article
                        v-for="row in unmatchedBankRows"
                        :key="row.id"
                        class="grid gap-4 p-4 lg:grid-cols-[minmax(0,1fr)_minmax(16rem,24rem)_auto] lg:items-end"
                    >
                        <div class="min-w-0 text-sm">
                            <div class="flex flex-wrap items-center gap-2">
                                <strong>{{
                                    formatMoney(row.amount_cents)
                                }}</strong>
                                <Badge
                                    :variant="
                                        row.type === 'return_debit'
                                            ? 'destructive'
                                            : 'outline'
                                    "
                                >
                                    {{
                                        row.type === 'return_debit'
                                            ? 'Rücklastschrift'
                                            : 'Zahlung'
                                    }}
                                </Badge>
                                <span class="text-muted-foreground">
                                    {{ formatDate(row.booking_date) }}
                                </span>
                            </div>
                            <p class="mt-1 break-words">
                                {{ row.purpose || 'Kein Verwendungszweck' }}
                            </p>
                            <small class="block break-all">
                                {{ row.original_name }} · Zeile
                                {{ row.row_number }}
                                <template v-if="row.reference">
                                    · Referenz {{ row.reference }}
                                </template>
                                · {{ row.reason }}
                            </small>
                        </div>
                        <div class="space-y-2">
                            <Label :for="`bank-row-member-${row.id}`">
                                Mitglied zuordnen
                            </Label>
                            <SearchableDropdown
                                :id="`bank-row-member-${row.id}`"
                                :model-value="bankAssignments[row.id] || ''"
                                :options="memberSearchOptions"
                                placeholder="Mitglied auswählen"
                                search-placeholder="Name oder Nummer suchen"
                                trigger-class="h-9 w-full rounded-md border border-input bg-background px-3"
                                dropdown-class="max-w-[calc(100vw-2rem)]"
                                @update:model-value="
                                    bankAssignments[row.id] = $event
                                "
                            />
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <Button
                                size="sm"
                                :disabled="
                                    !bankAssignments[row.id] ||
                                    !row.booking_date ||
                                    row.amount_cents === 0 ||
                                    bankRowBusy === row.id
                                "
                                @click="assignBankRow(row)"
                            >
                                <Spinner v-if="bankRowBusy === row.id" />
                                Zuordnen
                            </Button>
                            <Button
                                size="sm"
                                variant="outline"
                                :disabled="bankRowBusy === row.id"
                                @click="ignoreBankRow(row)"
                            >
                                Ignorieren
                            </Button>
                        </div>
                    </article>
                    <p
                        v-if="!unmatchedBankRows.length"
                        class="p-6 text-center text-sm text-muted-foreground"
                    >
                        Keine offenen Bankbuchungen.
                    </p>
                </div>
            </section>
        </div>
    </PaymentsPage>
</template>
