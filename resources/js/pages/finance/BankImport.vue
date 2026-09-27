<script setup lang="ts">
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { Landmark, Upload } from '@lucide/vue';
import { computed, ref } from 'vue';
import InvoiceNav from '@/components/finance/InvoiceNav.vue';
import InputError from '@/components/InputError.vue';
import SearchableDropdown from '@/components/SearchableDropdown.vue';
import StatusAlert from '@/components/StatusAlert.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';

type Invoice = {
    id: number;
    invoice_number: string;
    recipient_name: string;
    amount_cents: number;
    currency: string;
};
type BankRow = {
    id: number;
    finance_bank_import_id: number;
    row_number: number;
    booking_date: string | null;
    amount_cents: number;
    purpose: string | null;
    reference: string | null;
    reason: string | null;
    original_name: string;
};
const props = defineProps<{
    recentImports: Array<{
        id: number;
        original_name: string;
        row_count: number;
        imported_count: number;
        unmatched_count: number;
        created_at: string;
    }>;
    unmatchedRows: BankRow[];
    openInvoices: Invoice[];
}>();
defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Buchhaltung', href: '/buchhaltung' },
            { title: 'Rechnungswesen', href: '/buchhaltung/rechnungen' },
            { title: 'Bankimport' },
        ],
    },
});
const upload = useForm<{ csv: File | null }>({ csv: null });
const assignments = ref<Record<number, string>>({});
const busyRow = ref<number | null>(null);
const page = usePage();
const errors = computed(() => Object.values(page.props.errors ?? {}));
const invoiceOptions = computed(() =>
    props.openInvoices.map((invoice) => ({
        value: String(invoice.id),
        label: `${invoice.invoice_number} · ${invoice.recipient_name} · ${money(invoice.amount_cents, invoice.currency)}`,
        searchText: `${invoice.invoice_number} ${invoice.recipient_name}`,
    })),
);
const money = (cents: number, currency = 'EUR') =>
    new Intl.NumberFormat('de-DE', { style: 'currency', currency }).format(
        cents / 100,
    );
const date = (value: string | null) =>
    value
        ? new Date(`${value.slice(0, 10)}T00:00:00`).toLocaleDateString('de-DE')
        : '–';
const assign = (row: BankRow) => {
    busyRow.value = row.id;
    router.post(
        `/buchhaltung/rechnungen/bankimport/${row.finance_bank_import_id}/zeilen/${row.id}/zuordnen`,
        { invoice_id: Number(assignments.value[row.id]) },
        { preserveScroll: true, onFinish: () => (busyRow.value = null) },
    );
};
const ignore = (row: BankRow) => {
    busyRow.value = row.id;
    router.post(
        `/buchhaltung/rechnungen/bankimport/${row.finance_bank_import_id}/zeilen/${row.id}/ignorieren`,
        {},
        { preserveScroll: true, onFinish: () => (busyRow.value = null) },
    );
};
</script>

<template>
    <Head title="Bankimport · Rechnungswesen" />
    <div class="mx-auto w-full max-w-[1200px] space-y-6 p-4 sm:p-6">
        <header>
            <h1 class="text-2xl font-semibold tracking-tight">
                Rechnungswesen
            </h1>
            <p class="mt-1 text-sm text-muted-foreground">
                Zahlungseingänge aus Bankumsätzen offenen Rechnungen zuordnen.
            </p>
        </header>
        <InvoiceNav active="bank" />
        <StatusAlert
            v-if="errors.length"
            type="error"
            title="Bankimport nicht abgeschlossen"
            :messages="errors"
        />

        <div class="grid items-start gap-5 lg:grid-cols-2">
            <form
                class="space-y-5 rounded-xl border bg-card p-5"
                @submit.prevent="
                    upload.post('/buchhaltung/rechnungen/bankimport', {
                        preserveScroll: true,
                    })
                "
            >
                <div>
                    <h2 class="font-semibold">SEPA-Umsatzliste importieren</h2>
                    <p class="mt-1 text-sm text-muted-foreground">
                        Benötigt werden Datum/Buchungsdatum und Betrag. Die
                        automatische Zuordnung erfolgt ausschließlich über
                        Rechnungsnummern oder Mandatsreferenzen aus diesem
                        Rechnungsbereich. Beitragsmandate werden nicht
                        verwendet.
                    </p>
                </div>
                <div class="min-w-0 space-y-2">
                    <Label for="finance-bank-csv">CSV-Datei</Label>
                    <Input
                        id="finance-bank-csv"
                        type="file"
                        accept=".csv,text/csv,text/plain"
                        @change="
                            upload.csv =
                                ($event.target as HTMLInputElement)
                                    .files?.[0] || null
                        "
                    />
                    <InputError :message="upload.errors.csv" />
                </div>
                <Button
                    type="submit"
                    :disabled="!upload.csv || upload.processing"
                >
                    <Spinner v-if="upload.processing" />
                    <Upload v-else class="size-4" />
                    Importieren
                </Button>
            </form>

            <section class="rounded-xl border bg-card">
                <h2 class="border-b p-5 font-semibold">Letzte Importe</h2>
                <ul class="divide-y">
                    <li
                        v-for="item in recentImports"
                        :key="item.id"
                        class="p-4"
                    >
                        <strong>{{ item.original_name }}</strong>
                        <span class="block text-sm text-muted-foreground">
                            {{ item.imported_count }}/{{
                                item.row_count
                            }}
                            verbucht · {{ item.unmatched_count }} offen ·
                            {{ date(item.created_at) }}
                        </span>
                    </li>
                    <li
                        v-if="!recentImports.length"
                        class="p-5 text-muted-foreground"
                    >
                        Noch keine Importe.
                    </li>
                </ul>
            </section>
        </div>

        <section class="rounded-xl border bg-card">
            <div class="border-b p-5">
                <h2 class="font-semibold">Offene Zahlungseingänge</h2>
                <p class="mt-1 text-sm text-muted-foreground">
                    Nicht automatisch erkannte positive Umsätze können einer
                    betragsgleichen offenen Rechnung zugeordnet werden. Negative
                    Umsätze erscheinen unter „Rücklastschriften“.
                </p>
            </div>
            <div class="divide-y">
                <article
                    v-for="row in unmatchedRows"
                    :key="row.id"
                    class="grid gap-4 p-4 lg:grid-cols-[minmax(0,1fr)_minmax(18rem,30rem)_auto] lg:items-end"
                >
                    <div class="min-w-0 text-sm">
                        <div class="flex flex-wrap items-center gap-2">
                            <strong>{{ money(row.amount_cents) }}</strong>
                            <span class="text-muted-foreground">{{
                                date(row.booking_date)
                            }}</span>
                        </div>
                        <p class="mt-1 break-words">
                            {{ row.purpose || 'Kein Verwendungszweck' }}
                        </p>
                        <span
                            class="block text-xs break-all text-muted-foreground"
                        >
                            {{ row.original_name }} · Zeile {{ row.row_number }}
                            <template v-if="row.reference">
                                · {{ row.reference }}</template
                            >
                            <template v-if="row.reason">
                                · {{ row.reason }}</template
                            >
                        </span>
                    </div>
                    <div class="min-w-0 space-y-2">
                        <Label :for="`finance-bank-invoice-${row.id}`"
                            >Rechnung</Label
                        >
                        <SearchableDropdown
                            :id="`finance-bank-invoice-${row.id}`"
                            :model-value="assignments[row.id] || ''"
                            :options="invoiceOptions"
                            placeholder="Offene Rechnung auswählen"
                            search-placeholder="Nummer oder Empfänger suchen"
                            empty-text="Keine passende offene Rechnung"
                            aria-label="Offene Rechnung zuordnen"
                            @update:model-value="assignments[row.id] = $event"
                        />
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <Button
                            size="sm"
                            :disabled="
                                !assignments[row.id] || busyRow === row.id
                            "
                            @click="assign(row)"
                        >
                            <Spinner v-if="busyRow === row.id" />
                            <Landmark v-else class="size-4" />
                            Zuordnen
                        </Button>
                        <Button
                            size="sm"
                            variant="outline"
                            :disabled="busyRow === row.id"
                            @click="ignore(row)"
                        >
                            Ignorieren
                        </Button>
                    </div>
                </article>
                <p
                    v-if="!unmatchedRows.length"
                    class="p-8 text-center text-sm text-muted-foreground"
                >
                    Keine offenen Zahlungseingänge.
                </p>
            </div>
        </section>
    </div>
</template>
