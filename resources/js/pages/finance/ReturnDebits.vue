<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import { RefreshCcw } from '@lucide/vue';
import { computed, ref } from 'vue';
import InvoiceNav from '@/components/finance/InvoiceNav.vue';
import SearchableDropdown from '@/components/SearchableDropdown.vue';
import StatusAlert from '@/components/StatusAlert.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { formatDate, formatMoney } from '@/lib/format';

type Invoice = {
    id: number;
    invoice_number: string;
    recipient_name: string;
    amount_cents: number;
    currency: string;
    mandate_type: 'recurring' | 'one_off';
};
type ReturnRow = {
    id: number;
    row_number: number;
    booking_date: string | null;
    amount_cents: number;
    purpose: string | null;
    reference: string | null;
    reason: string | null;
    finance_invoice_id: number | null;
    original_name: string;
};
type RowForm = {
    invoice_id: string;
    fee_amount: string;
    description: string;
    due_date: string;
    vat_rate: '0' | '7' | '19';
    tax_exemption_reason: string;
};
const props = defineProps<{
    rows: ReturnRow[];
    invoices: Invoice[];
    defaultDueDate: string;
}>();
defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Buchhaltung', href: '/buchhaltung' },
            { title: 'Rechnungswesen', href: '/buchhaltung/rechnungen' },
            { title: 'Rücklastschriften' },
        ],
    },
});
const forms = ref<Record<number, RowForm>>(
    Object.fromEntries(
        props.rows.map((row) => [
            row.id,
            {
                invoice_id: row.finance_invoice_id
                    ? String(row.finance_invoice_id)
                    : '',
                fee_amount: '',
                description: 'Rücklastschriftkosten',
                due_date: props.defaultDueDate,
                vat_rate: '0',
                tax_exemption_reason: 'Nicht steuerbarer Schadensersatz.',
            },
        ]),
    ),
);
const busyRow = ref<number | null>(null);
const page = usePage();
const errors = computed(() => Object.values(page.props.errors ?? {}));
const invoiceOptions = computed(() =>
    props.invoices.map((invoice) => ({
        value: String(invoice.id),
        label: `${invoice.invoice_number} · ${invoice.recipient_name} · ${formatMoney(invoice.amount_cents, invoice.currency)}`,
        searchText: `${invoice.invoice_number} ${invoice.recipient_name}`,
    })),
);
const selectedInvoice = (row: ReturnRow) =>
    props.invoices.find(
        (invoice) => String(invoice.id) === forms.value[row.id].invoice_id,
    );
const selectInvoice = (row: ReturnRow, value: string) => {
    const form = forms.value[row.id];
    form.invoice_id = value;
    const invoice = selectedInvoice(row);
    if (!invoice) return;
    form.description = `Rücklastschriftkosten zu Rechnung ${invoice.invoice_number}`;
    const suggestedFee = Math.max(
        0,
        Math.abs(row.amount_cents) - invoice.amount_cents,
    );
    if (suggestedFee > 0) {
        form.fee_amount = (suggestedFee / 100).toFixed(2).replace('.', ',');
    }
};
const submit = (row: ReturnRow) => {
    busyRow.value = row.id;
    router.post(
        `/buchhaltung/rechnungen/ruecklastschriften/${row.id}`,
        forms.value[row.id],
        { preserveScroll: true, onFinish: () => (busyRow.value = null) },
    );
};
</script>

<template>
    <Head title="Rücklastschriften · Rechnungswesen" />
    <div class="mx-auto w-full max-w-[1200px] space-y-6 p-4 sm:p-6">
        <header>
            <h1 class="text-2xl font-semibold tracking-tight">
                Rechnungswesen
            </h1>
            <p class="mt-1 text-sm text-muted-foreground">
                Zurückgegebene Lastschriften zuordnen und entstandene Kosten
                weiterberechnen.
            </p>
        </header>
        <InvoiceNav active="returns" />
        <StatusAlert
            v-if="errors.length"
            type="error"
            title="Rücklastschrift nicht verarbeitet"
            :messages="errors"
        />
        <StatusAlert type="info" title="Auswirkung der Zuordnung">
            Die ursprüngliche Rechnung wird wieder geöffnet. Für die tatsächlich
            weiterzuberechnenden Rücklastschriftkosten wird eine eigene Rechnung
            erstellt. Einmalmandate können anschließend nicht erneut eingezogen
            werden.
        </StatusAlert>

        <div class="space-y-5">
            <section
                v-for="row in rows"
                :key="row.id"
                class="rounded-xl border bg-card"
            >
                <div class="border-b px-5 py-4">
                    <div
                        class="flex flex-wrap items-center justify-between gap-2"
                    >
                        <h2 class="font-semibold">
                            Rücklastschrift
                            {{ formatMoney(Math.abs(row.amount_cents)) }}
                        </h2>
                        <span class="text-sm text-muted-foreground">
                            {{ formatDate(row.booking_date) }}
                        </span>
                    </div>
                    <p class="mt-1 text-sm break-words">
                        {{ row.purpose || 'Kein Verwendungszweck' }}
                    </p>
                    <p class="mt-1 text-xs break-all text-muted-foreground">
                        {{ row.original_name }} · Zeile {{ row.row_number }}
                        <template v-if="row.reference">
                            · {{ row.reference }}</template
                        >
                    </p>
                </div>
                <div class="grid gap-5 p-5 sm:grid-cols-2">
                    <div class="min-w-0 space-y-2 sm:col-span-2">
                        <Label :for="`return-invoice-${row.id}`"
                            >Ursprüngliche Rechnung *</Label
                        >
                        <SearchableDropdown
                            :id="`return-invoice-${row.id}`"
                            :model-value="forms[row.id].invoice_id"
                            :options="invoiceOptions"
                            placeholder="Bezahlte SEPA-Rechnung auswählen"
                            search-placeholder="Nummer oder Empfänger suchen"
                            empty-text="Keine passende SEPA-Rechnung"
                            aria-label="Ursprüngliche Rechnung auswählen"
                            @update:model-value="selectInvoice(row, $event)"
                        />
                        <p
                            v-if="
                                selectedInvoice(row)?.mandate_type === 'one_off'
                            "
                            class="text-sm text-muted-foreground"
                        >
                            Einmalmandat: Die Forderung wird geöffnet, aber
                            nicht erneut für den SEPA-Export freigegeben.
                        </p>
                    </div>
                    <div class="min-w-0 space-y-2">
                        <Label :for="`return-fee-${row.id}`"
                            >Weiterzuberechnende Kosten in Euro *</Label
                        >
                        <Input
                            :id="`return-fee-${row.id}`"
                            v-model="forms[row.id].fee_amount"
                            inputmode="decimal"
                            placeholder="0,00"
                        />
                    </div>
                    <div class="min-w-0 space-y-2">
                        <Label :for="`return-due-${row.id}`">Fällig am *</Label>
                        <Input
                            :id="`return-due-${row.id}`"
                            v-model="forms[row.id].due_date"
                            type="date"
                            class="date-safe"
                        />
                    </div>
                    <div class="min-w-0 space-y-2 sm:col-span-2">
                        <Label :for="`return-description-${row.id}`"
                            >Rechnungsposition *</Label
                        >
                        <Input
                            :id="`return-description-${row.id}`"
                            v-model="forms[row.id].description"
                            maxlength="500"
                        />
                    </div>
                    <div class="min-w-0 space-y-2">
                        <Label :for="`return-vat-${row.id}`"
                            >Umsatzsteuer *</Label
                        >
                        <select
                            :id="`return-vat-${row.id}`"
                            v-model="forms[row.id].vat_rate"
                            class="h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
                        >
                            <option value="0">0 % / nicht steuerbar</option>
                            <option value="7">7 %</option>
                            <option value="19">19 %</option>
                        </select>
                    </div>
                    <div
                        v-if="forms[row.id].vat_rate === '0'"
                        class="min-w-0 space-y-2"
                    >
                        <Label :for="`return-tax-reason-${row.id}`"
                            >Begründung *</Label
                        >
                        <Input
                            :id="`return-tax-reason-${row.id}`"
                            v-model="forms[row.id].tax_exemption_reason"
                            maxlength="500"
                        />
                    </div>
                    <div class="sm:col-span-2">
                        <Button
                            :disabled="
                                !forms[row.id].invoice_id ||
                                !forms[row.id].fee_amount ||
                                busyRow === row.id
                            "
                            @click="submit(row)"
                        >
                            <Spinner v-if="busyRow === row.id" />
                            <RefreshCcw v-else class="size-4" />
                            Rücklastschrift verarbeiten und Rechnung erstellen
                        </Button>
                    </div>
                </div>
            </section>
            <StatusAlert
                v-if="!rows.length"
                type="success"
                title="Keine offenen Rücklastschriften"
            >
                Im Bankimport liegen derzeit keine negativen, noch zuzuordnenden
                Umsätze vor.
            </StatusAlert>
        </div>
    </div>
</template>
