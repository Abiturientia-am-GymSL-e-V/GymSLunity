<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { FileDown, FileText, Mail, Printer } from '@lucide/vue';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import PaymentsPage from '@/components/payments/PaymentsPage.vue';
import SearchableDropdown from '@/components/SearchableDropdown.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { postDownload } from '@/lib/download';
import { formatDate, formatMoney } from '@/lib/format';
import type { Contribution, PaymentsClub } from '@/types/payments';

const props = defineProps<{
    contributions: Contribution[];
    club: PaymentsClub;
}>();
defineOptions({
    layout: { breadcrumbs: [{ title: 'Beiträge', href: '/beitraege' }] },
});

const invoiceDescription = ref('');
const invoiceState = ref('all');
const invoiceDescriptions = computed(() =>
    [
        ...new Set(
            props.contributions
                .filter((item) => item.kind === 'contribution')
                .map((item) => item.description),
        ),
    ].sort((a, b) => a.localeCompare(b, 'de')),
);
const invoiceDescriptionOptions = computed(() => [
    { value: '', label: 'Alle Beiträge' },
    ...invoiceDescriptions.value.map((value) => ({ value, label: value })),
]);
const invoiceStateOptions = [
    { value: 'all', label: 'Alle Rechnungsstatus' },
    { value: 'missing', label: 'Noch nicht erzeugt' },
    { value: 'generated', label: 'Erzeugt' },
    { value: 'sent', label: 'Per E-Mail versendet' },
];
const invoiceRows = computed(() =>
    props.contributions.filter(
        (item) =>
            item.kind === 'contribution' &&
            (!invoiceDescription.value ||
                item.description === invoiceDescription.value) &&
            (invoiceState.value === 'all' ||
                (invoiceState.value === 'generated' && !!item.invoice_number) ||
                (invoiceState.value === 'missing' && !item.invoice_number) ||
                (invoiceState.value === 'sent' && !!item.invoice_sent_at)),
    ),
);
const invoiceSelection = ref<number[]>([]);
const invoiceForm = useForm<{ ids: number[]; tax_deductible: boolean }>({
    ids: [],
    tax_deductible: false,
});
const selectedInvoiceRows = computed(() =>
    props.contributions.filter((item) =>
        invoiceSelection.value.includes(item.id),
    ),
);
const canGenerateInvoices = computed(() =>
    selectedInvoiceRows.value.some((item) => !item.invoice_number),
);
const canDownloadInvoices = computed(
    () =>
        selectedInvoiceRows.value.length > 0 &&
        selectedInvoiceRows.value.every((item) => !!item.invoice_number),
);
const invoiceDownloadBusy = ref(false);
const invoiceDownloadError = ref('');
function toggleInvoice(id: number) {
    invoiceSelection.value = invoiceSelection.value.includes(id)
        ? invoiceSelection.value.filter((value) => value !== id)
        : [...invoiceSelection.value, id];
}
function toggleAllInvoices() {
    const ids = invoiceRows.value.map((item) => item.id);
    invoiceSelection.value =
        ids.length > 0 && ids.every((id) => invoiceSelection.value.includes(id))
            ? []
            : ids;
}
function invoice(action: 'erzeugen' | 'versenden') {
    invoiceForm.ids = invoiceSelection.value;
    invoiceForm.post('/beitraege/rechnungen/' + action, {
        preserveScroll: true,
        onSuccess: () => {
            invoiceSelection.value = [];
        },
    });
}
async function downloadInvoices() {
    invoiceDownloadBusy.value = true;
    invoiceDownloadError.value = '';
    try {
        await postDownload(
            '/beitraege/rechnungen/sammeldownload',
            { ids: invoiceSelection.value },
            'beitragsrechnungen.pdf',
            {
                accept: 'application/pdf, application/json',
                errorKeys: ['ids'],
                fallback: 'Der Sammeldownload konnte nicht erstellt werden.',
            },
        );
    } catch (cause) {
        invoiceDownloadError.value =
            cause instanceof Error
                ? cause.message
                : 'Der Sammeldownload ist fehlgeschlagen.';
    } finally {
        invoiceDownloadBusy.value = false;
    }
}
</script>

<template>
    <PaymentsPage active="invoices">
        <section class="overflow-hidden rounded-xl border bg-card">
            <div
                class="flex flex-wrap items-center justify-between gap-3 border-b p-5"
            >
                <div>
                    <h2 class="font-semibold">Beitragsrechnungen</h2>
                    <p class="text-sm text-muted-foreground">
                        Auswahl erzeugen, als PDF drucken oder per E-Mail
                        versenden.
                    </p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <label
                        v-if="club.tax_deductible_enabled"
                        class="flex items-center gap-2 text-sm"
                        ><input
                            v-model="invoiceForm.tax_deductible"
                            type="checkbox"
                        />Abzugsfähig</label
                    ><Button
                        variant="outline"
                        :disabled="
                            !canGenerateInvoices || invoiceForm.processing
                        "
                        @click="invoice('erzeugen')"
                        ><FileText class="size-4" />Erzeugen</Button
                    ><Button
                        :disabled="
                            !invoiceSelection.length || invoiceForm.processing
                        "
                        @click="invoice('versenden')"
                        ><Mail class="size-4" />Mail senden</Button
                    ><Button
                        variant="outline"
                        :disabled="!canDownloadInvoices || invoiceDownloadBusy"
                        @click="downloadInvoices"
                        ><Spinner v-if="invoiceDownloadBusy" /><FileDown
                            v-else
                            class="size-4"
                        />Sammel-PDF</Button
                    >
                </div>
                <InputError
                    class="basis-full"
                    :message="invoiceForm.errors.ids"
                />
                <InputError
                    class="basis-full"
                    :message="invoiceDownloadError"
                />
                <div class="grid basis-full gap-3 border-t pt-4 sm:grid-cols-2">
                    <div class="space-y-2">
                        <Label for="invoice-description-filter">Beitrag</Label>
                        <SearchableDropdown
                            id="invoice-description-filter"
                            v-model="invoiceDescription"
                            :options="invoiceDescriptionOptions"
                            search-placeholder="Beitrag suchen"
                            aria-label="Beitrag filtern"
                            trigger-class="h-9 w-full rounded-md border border-input bg-background px-3"
                        />
                    </div>
                    <div class="space-y-2">
                        <Label for="invoice-state-filter"
                            >Rechnungsstatus</Label
                        >
                        <SearchableDropdown
                            id="invoice-state-filter"
                            v-model="invoiceState"
                            :options="invoiceStateOptions"
                            search-placeholder="Status suchen"
                            aria-label="Rechnungsstatus filtern"
                            trigger-class="h-9 w-full rounded-md border border-input bg-background px-3"
                        />
                    </div>
                </div>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left">
                        <tr>
                            <th class="p-3">
                                <input
                                    type="checkbox"
                                    @change="toggleAllInvoices"
                                />
                            </th>
                            <th class="p-3">Mitglied</th>
                            <th class="p-3">Beitrag</th>
                            <th class="p-3">Fällig</th>
                            <th class="p-3 text-right">Betrag</th>
                            <th class="p-3">Rechnung</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="item in invoiceRows" :key="item.id">
                            <td class="p-3">
                                <input
                                    type="checkbox"
                                    :checked="
                                        invoiceSelection.includes(item.id)
                                    "
                                    @change="toggleInvoice(item.id)"
                                />
                            </td>
                            <td class="p-3">
                                <a
                                    class="font-medium hover:underline"
                                    :href="'/mitglieder/' + item.member_number"
                                    >{{ item.member_name }}</a
                                ><small class="block">{{
                                    item.email || 'keine E-Mail'
                                }}</small>
                            </td>
                            <td class="p-3">
                                {{ item.description
                                }}<small class="block"
                                    >{{ formatDate(item.period_start) }}–{{
                                        formatDate(item.period_end)
                                    }}</small
                                ><small
                                    v-if="item.payment_reference"
                                    class="block font-mono"
                                >
                                    {{ item.payment_reference }}
                                </small>
                            </td>
                            <td class="p-3">{{ formatDate(item.due_date) }}</td>
                            <td class="p-3 text-right">
                                {{ formatMoney(item.amount_cents) }}
                            </td>
                            <td class="p-3">
                                <template v-if="item.invoice_number"
                                    ><Badge>{{ item.invoice_number }}</Badge>
                                    <div class="mt-2 flex gap-1">
                                        <Button
                                            as-child
                                            size="icon-sm"
                                            variant="ghost"
                                            ><a
                                                :href="
                                                    '/beitraege/rechnungen/' +
                                                    item.id +
                                                    '?format=pdf'
                                                "
                                                ><FileDown
                                                    class="size-4" /></a></Button
                                        ><Button
                                            as-child
                                            size="icon-sm"
                                            variant="ghost"
                                            ><a
                                                :href="
                                                    '/beitraege/rechnungen/' +
                                                    item.id +
                                                    '?format=print'
                                                "
                                                target="_blank"
                                                ><Printer class="size-4" /></a
                                        ></Button></div></template
                                ><span v-else class="text-muted-foreground"
                                    >Nicht erzeugt</span
                                >
                            </td>
                        </tr>
                        <tr v-if="!invoiceRows.length">
                            <td
                                colspan="6"
                                class="p-8 text-center text-muted-foreground"
                            >
                                Keine Beiträge im Zeitraum.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </PaymentsPage>
</template>
