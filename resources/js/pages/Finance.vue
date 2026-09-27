<script setup lang="ts">
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import {
    Ban,
    CheckCircle2,
    Download,
    FileCode2,
    Mail,
    Printer,
    Search,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import InvoiceNav from '@/components/finance/InvoiceNav.vue';
import InputError from '@/components/InputError.vue';
import StatusAlert from '@/components/StatusAlert.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';

type Invoice = {
    id: number;
    invoice_number: string;
    document_type: 'invoice' | 'cancellation';
    original_invoice_id: number | null;
    original_invoice_number: string | null;
    recipient_name: string;
    recipient_email: string;
    issue_date: string;
    due_date: string;
    payment_method: string;
    currency: string;
    total_cents: number;
    status: 'open' | 'paid' | 'cancelled';
    paid_at: string | null;
    cancellation_reason: string | null;
    partially_cancelled: boolean;
    cancellable_items: Array<{
        index: number;
        description: string;
        quantity: string;
        unit_code: string;
        total_cents: number;
    }>;
};
const props = defineProps<{
    invoices: {
        data: Invoice[];
        total: number;
        next_page_url: string | null;
        prev_page_url: string | null;
    };
    filters: {
        search: string;
        status: 'all' | 'open' | 'paid' | 'cancelled';
        document_type: 'all' | 'invoice' | 'cancellation';
        from: string;
        to: string;
    };
    summary: {
        count: number;
        open_count: number;
        open_cents: number;
        paid_cents: number;
    };
}>();
defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Buchhaltung', href: '/buchhaltung' },
            { title: 'Rechnungswesen' },
        ],
    },
});
const search = ref(props.filters.search);
const status = ref(props.filters.status);
const documentType = ref(props.filters.document_type);
const from = ref(props.filters.from);
const to = ref(props.filters.to);
let timer: ReturnType<typeof setTimeout> | undefined;
watch([search, status, documentType, from, to], () => {
    clearTimeout(timer);
    timer = setTimeout(
        () =>
            router.get(
                '/buchhaltung/rechnungen',
                {
                    search: search.value || undefined,
                    status: status.value,
                    document_type: documentType.value,
                    from: from.value || undefined,
                    to: to.value || undefined,
                },
                { preserveState: true, replace: true },
            ),
        250,
    );
});
const reportUrl = computed(() => {
    const query = new URLSearchParams();
    if (search.value.trim()) query.set('search', search.value.trim());
    if (status.value !== 'all') query.set('status', status.value);
    if (documentType.value !== 'all')
        query.set('document_type', documentType.value);
    if (from.value) query.set('from', from.value);
    if (to.value) query.set('to', to.value);
    return `/buchhaltung/rechnungen/rechnungsbuch.pdf?${query.toString()}`;
});
const resetFilters = () => {
    search.value = '';
    status.value = 'all';
    documentType.value = 'all';
    from.value = '';
    to.value = '';
};
const page = usePage();
const errors = computed(() => Object.values(page.props.errors ?? {}));
const money = (cents: number, currency = 'EUR') =>
    new Intl.NumberFormat('de-DE', { style: 'currency', currency }).format(
        cents / 100,
    );
const date = (value: string) =>
    new Date(`${value}T00:00:00`).toLocaleDateString('de-DE');
const paymentLabels: Record<string, string> = {
    bank_transfer: 'Überweisung',
    sepa_direct_debit: 'SEPA-Lastschrift',
    cash: 'Bar',
    card: 'Karte',
    other: 'Sonstige',
};
const unitLabels: Record<string, string> = {
    C62: 'Stk.',
    HUR: 'Std.',
    DAY: 'Tag(e)',
};
const statusLabel = (invoice: Invoice) => {
    if (invoice.document_type === 'cancellation') return 'Stornorechnung';
    if (invoice.status === 'cancelled')
        return invoice.paid_at ? 'Storniert · zuvor bezahlt' : 'Storniert';
    if (invoice.partially_cancelled)
        return invoice.status === 'paid'
            ? 'Teilweise storniert · bezahlt'
            : 'Teilweise storniert · offen';
    return invoice.status === 'paid' ? 'Bezahlt' : 'Offen';
};
const statusClass = (invoice: Invoice) => {
    if (invoice.status === 'cancelled')
        return 'border-destructive/30 bg-destructive/10 text-destructive';
    if (invoice.partially_cancelled)
        return 'border-warning/30 bg-warning/10 text-warning-foreground';
    if (invoice.status === 'paid')
        return 'border-success/30 bg-success/10 text-success';
    return 'border-warning/30 bg-warning/10 text-warning-foreground';
};
const send = (invoice: Invoice) =>
    router.post(
        `/buchhaltung/rechnungen/${invoice.id}/versenden`,
        { recipient: invoice.recipient_email },
        { preserveScroll: true },
    );
const markPaid = (invoice: Invoice) =>
    router.patch(
        `/buchhaltung/rechnungen/${invoice.id}/bezahlt`,
        {},
        { preserveScroll: true },
    );
const cancellationDialogOpen = ref(false);
const cancellingInvoice = ref<Invoice | null>(null);
const cancellationForm = useForm({
    reason: '',
    item_indices: [] as number[],
});
const selectedCancellationTotal = computed(() =>
    (cancellingInvoice.value?.cancellable_items ?? [])
        .filter((item) => cancellationForm.item_indices.includes(item.index))
        .reduce((sum, item) => sum + item.total_cents, 0),
);
const openCancellation = (invoice: Invoice) => {
    cancellingInvoice.value = invoice;
    cancellationForm.reset();
    cancellationForm.item_indices = invoice.cancellable_items.map(
        (item) => item.index,
    );
    cancellationForm.clearErrors();
    cancellationDialogOpen.value = true;
};
const setCancellationItem = (index: number, selected: boolean) => {
    cancellationForm.item_indices = selected
        ? [...new Set([...cancellationForm.item_indices, index])]
        : cancellationForm.item_indices.filter(
              (itemIndex) => itemIndex !== index,
          );
};
const cancelInvoice = () => {
    if (!cancellingInvoice.value) return;
    cancellationForm.post(
        `/buchhaltung/rechnungen/${cancellingInvoice.value.id}/stornieren`,
        {
            preserveScroll: true,
            onSuccess: () => {
                cancellationDialogOpen.value = false;
                cancellingInvoice.value = null;
                cancellationForm.reset();
            },
        },
    );
};
</script>

<template>
    <Head title="Rechnungswesen" />
    <div class="mx-auto w-full max-w-[1200px] space-y-6 p-4 sm:p-6">
        <header>
            <h1 class="text-2xl font-semibold tracking-tight">
                Rechnungswesen
            </h1>
            <p class="mt-1 text-sm text-muted-foreground">
                Rechnungen erstellen und offene Forderungen nachverfolgen.
            </p>
        </header>
        <InvoiceNav active="overview" />
        <StatusAlert
            v-if="errors.length"
            type="error"
            title="Aktion nicht abgeschlossen"
            :messages="errors"
        />
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <section class="rounded-xl border bg-card p-4">
                <p class="text-sm text-muted-foreground">Rechnungen</p>
                <p class="mt-1 text-2xl font-semibold">{{ summary.count }}</p>
            </section>
            <section class="rounded-xl border bg-card p-4">
                <p class="text-sm text-muted-foreground">Offene Forderungen</p>
                <p class="mt-1 text-2xl font-semibold">
                    {{ summary.open_count }}
                </p>
            </section>
            <section class="rounded-xl border bg-card p-4">
                <p class="text-sm text-muted-foreground">Offener Betrag</p>
                <p class="mt-1 text-2xl font-semibold">
                    {{ money(summary.open_cents) }}
                </p>
            </section>
            <section class="rounded-xl border bg-card p-4">
                <p class="text-sm text-muted-foreground">Als bezahlt erfasst</p>
                <p class="mt-1 text-2xl font-semibold">
                    {{ money(summary.paid_cents) }}
                </p>
            </section>
        </div>
        <section class="overflow-hidden rounded-xl border bg-card">
            <div class="border-b px-5 py-4">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h2 class="font-semibold">Rechnungsbuch</h2>
                        <p class="mt-1 text-sm text-muted-foreground">
                            Rechnungen und Stornobelege durchsuchen und als
                            gefilterte Liste ausgeben.
                        </p>
                    </div>
                    <Button as-child variant="outline">
                        <a :href="reportUrl"><Download class="size-4" />PDF</a>
                    </Button>
                </div>
            </div>
            <div
                class="grid gap-3 border-b p-4 sm:grid-cols-2 xl:grid-cols-[minmax(0,1fr)_minmax(0,.65fr)_minmax(0,.65fr)_minmax(0,.8fr)_minmax(0,.8fr)_auto] xl:items-end"
            >
                <div class="min-w-0 space-y-2">
                    <Label for="invoice-filter-search">Suche</Label>
                    <div class="relative">
                        <Search
                            class="pointer-events-none absolute top-2.5 left-3 size-4 text-muted-foreground"
                            aria-hidden="true"
                        />
                        <Input
                            id="invoice-filter-search"
                            v-model="search"
                            class="pl-9"
                            placeholder="Nummer, Empfänger oder Referenz suchen"
                        />
                    </div>
                </div>
                <div class="min-w-0 space-y-2">
                    <Label for="invoice-filter-from">Von</Label>
                    <Input
                        id="invoice-filter-from"
                        v-model="from"
                        type="date"
                    />
                </div>
                <div class="min-w-0 space-y-2">
                    <Label for="invoice-filter-to">Bis</Label>
                    <Input
                        id="invoice-filter-to"
                        v-model="to"
                        type="date"
                        :min="from || undefined"
                    />
                </div>
                <div class="min-w-0 space-y-2">
                    <Label for="invoice-filter-type">Belegart</Label>
                    <select
                        id="invoice-filter-type"
                        v-model="documentType"
                        class="h-9 w-full rounded-md border border-input bg-background px-3 text-base md:text-sm"
                    >
                        <option value="all">Alle Belegarten</option>
                        <option value="invoice">Rechnungen</option>
                        <option value="cancellation">Stornorechnungen</option>
                    </select>
                </div>
                <div class="min-w-0 space-y-2">
                    <Label for="invoice-filter-status">Status</Label>
                    <select
                        id="invoice-filter-status"
                        v-model="status"
                        class="h-9 w-full rounded-md border border-input bg-background px-3 text-base md:text-sm"
                    >
                        <option value="all">Alle Belege</option>
                        <option value="open">Offene Forderungen</option>
                        <option value="paid">Bezahlte Rechnungen</option>
                        <option value="cancelled">Stornierte Belege</option>
                    </select>
                </div>
                <Button variant="outline" type="button" @click="resetFilters">
                    Zurücksetzen
                </Button>
            </div>
            <div v-if="!invoices.data.length" class="p-10 text-center">
                <p class="font-medium">Keine Belege gefunden</p>
                <p class="mt-1 text-sm text-muted-foreground">
                    Lege die erste Rechnung an oder passe die Filter an.
                </p>
            </div>
            <div v-else class="overflow-x-auto">
                <table class="w-full min-w-[900px] text-sm">
                    <thead class="bg-muted/50 text-left">
                        <tr>
                            <th class="px-4 py-3 font-medium">Beleg</th>
                            <th class="px-4 py-3 font-medium">Empfänger</th>
                            <th class="px-4 py-3 font-medium">
                                Datum / Fällig
                            </th>
                            <th class="px-4 py-3 font-medium">Zahlungsart</th>
                            <th class="px-4 py-3 text-right font-medium">
                                Betrag
                            </th>
                            <th class="px-4 py-3 font-medium">Status</th>
                            <th class="px-4 py-3 text-right font-medium">
                                Aktionen
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="invoice in invoices.data" :key="invoice.id">
                            <td class="px-4 py-3 font-medium">
                                <span class="block">{{
                                    invoice.invoice_number
                                }}</span>
                                <span
                                    v-if="
                                        invoice.document_type === 'cancellation'
                                    "
                                    class="text-xs font-normal text-muted-foreground"
                                >
                                    zu {{ invoice.original_invoice_number }}
                                </span>
                            </td>
                            <td class="px-4 py-3">
                                <span class="block">{{
                                    invoice.recipient_name
                                }}</span
                                ><span class="text-xs text-muted-foreground">{{
                                    invoice.recipient_email
                                }}</span>
                            </td>
                            <td class="px-4 py-3">
                                {{ date(invoice.issue_date) }}<br /><span
                                    class="text-xs text-muted-foreground"
                                    >{{
                                        invoice.document_type === 'cancellation'
                                            ? 'Stornodatum'
                                            : `fällig ${date(invoice.due_date)}`
                                    }}</span
                                >
                            </td>
                            <td class="px-4 py-3">
                                {{
                                    invoice.document_type === 'cancellation'
                                        ? 'Gegenbeleg'
                                        : paymentLabels[invoice.payment_method]
                                }}
                            </td>
                            <td class="px-4 py-3 text-right font-medium">
                                {{
                                    money(
                                        invoice.document_type === 'cancellation'
                                            ? -invoice.total_cents
                                            : invoice.total_cents,
                                        invoice.currency,
                                    )
                                }}
                            </td>
                            <td class="px-4 py-3">
                                <Badge
                                    variant="outline"
                                    :class="statusClass(invoice)"
                                    >{{ statusLabel(invoice) }}</Badge
                                >
                                <span
                                    v-if="invoice.cancellation_reason"
                                    class="mt-1 block max-w-52 text-xs text-muted-foreground"
                                >
                                    {{ invoice.cancellation_reason }}
                                </span>
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex justify-end gap-1">
                                    <Button
                                        as-child
                                        variant="ghost"
                                        size="icon"
                                        title="PDF ansehen und drucken"
                                        ><a
                                            :href="`/buchhaltung/rechnungen/${invoice.id}/pdf?inline=1`"
                                            target="_blank"
                                            rel="noopener"
                                            ><Printer class="size-4" /><span
                                                class="sr-only"
                                                >PDF ansehen und drucken</span
                                            ></a
                                        ></Button
                                    >
                                    <Button
                                        as-child
                                        variant="ghost"
                                        size="icon"
                                        title="XRechnung herunterladen"
                                        ><a
                                            :href="`/buchhaltung/rechnungen/${invoice.id}/xrechnung`"
                                            ><FileCode2 class="size-4" /><span
                                                class="sr-only"
                                                >XRechnung herunterladen</span
                                            ></a
                                        ></Button
                                    >
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        title="Per E-Mail versenden"
                                        @click="send(invoice)"
                                        ><Mail class="size-4" /><span
                                            class="sr-only"
                                            >Per E-Mail versenden</span
                                        ></Button
                                    >
                                    <Button
                                        v-if="
                                            invoice.document_type ===
                                                'invoice' &&
                                            invoice.status === 'open'
                                        "
                                        variant="ghost"
                                        size="icon"
                                        title="Als bezahlt markieren"
                                        @click="markPaid(invoice)"
                                        ><CheckCircle2 class="size-4" /><span
                                            class="sr-only"
                                            >Als bezahlt markieren</span
                                        ></Button
                                    >
                                    <Button
                                        v-if="
                                            invoice.document_type ===
                                                'invoice' &&
                                            invoice.cancellable_items.length > 0
                                        "
                                        variant="ghost"
                                        size="icon"
                                        class="text-destructive hover:text-destructive"
                                        title="Rechnung stornieren"
                                        @click="openCancellation(invoice)"
                                        ><Ban class="size-4" /><span
                                            class="sr-only"
                                            >Rechnung stornieren</span
                                        ></Button
                                    >
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div
                v-if="invoices.prev_page_url || invoices.next_page_url"
                class="flex justify-between border-t p-4"
            >
                <Button
                    as-child
                    variant="outline"
                    :disabled="!invoices.prev_page_url"
                    ><Link :href="invoices.prev_page_url ?? '#'"
                        >Zurück</Link
                    ></Button
                >
                <Button
                    as-child
                    variant="outline"
                    :disabled="!invoices.next_page_url"
                    ><Link :href="invoices.next_page_url ?? '#'"
                        >Weiter</Link
                    ></Button
                >
            </div>
        </section>

        <Dialog v-model:open="cancellationDialogOpen">
            <DialogContent class="sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle>Rechnung stornieren</DialogTitle>
                    <DialogDescription>
                        Für {{ cancellingInvoice?.invoice_number }} wird eine
                        eigenständige Stornorechnung mit neuer Belegnummer
                        erstellt. Die ursprüngliche Rechnung bleibt unverändert
                        erhalten.
                    </DialogDescription>
                </DialogHeader>
                <form class="space-y-4" @submit.prevent="cancelInvoice">
                    <StatusAlert
                        v-if="cancellingInvoice?.paid_at"
                        type="warning"
                        title="Zahlung bereits erfasst"
                    >
                        Die Stornierung verbucht keine automatische Erstattung.
                        Prüfe und veranlasse die Rückzahlung separat.
                    </StatusAlert>
                    <div class="space-y-2">
                        <div class="flex items-center justify-between gap-4">
                            <p class="text-sm font-medium">
                                Zu stornierende Positionen *
                            </p>
                            <span class="text-sm font-medium">
                                {{ money(selectedCancellationTotal) }}
                            </span>
                        </div>
                        <div
                            class="max-h-64 space-y-2 overflow-y-auto rounded-lg border p-2"
                        >
                            <label
                                v-for="item in cancellingInvoice?.cancellable_items"
                                :key="item.index"
                                class="flex cursor-pointer items-start gap-3 rounded-md p-2 hover:bg-muted/50"
                            >
                                <Checkbox
                                    class="mt-0.5"
                                    :model-value="
                                        cancellationForm.item_indices.includes(
                                            item.index,
                                        )
                                    "
                                    @update:model-value="
                                        setCancellationItem(
                                            item.index,
                                            Boolean($event),
                                        )
                                    "
                                />
                                <span class="min-w-0 flex-1">
                                    <span class="block text-sm font-medium">{{
                                        item.description
                                    }}</span>
                                    <span
                                        class="block text-xs text-muted-foreground"
                                    >
                                        {{ item.quantity }}
                                        {{
                                            unitLabels[item.unit_code] ??
                                            item.unit_code
                                        }}
                                    </span>
                                </span>
                                <span class="text-sm font-medium">
                                    {{ money(item.total_cents) }}
                                </span>
                            </label>
                        </div>
                        <p class="text-sm text-muted-foreground">
                            Bereits stornierte Positionen werden nicht erneut
                            angeboten.
                        </p>
                        <InputError
                            :message="cancellationForm.errors.item_indices"
                        />
                    </div>
                    <div class="space-y-2">
                        <Label for="cancellation-reason"
                            >Stornierungsgrund *</Label
                        >
                        <Textarea
                            id="cancellation-reason"
                            v-model="cancellationForm.reason"
                            rows="4"
                            maxlength="1000"
                            required
                            placeholder="z. B. Auftrag wurde vollständig aufgehoben"
                        />
                        <p class="text-sm text-muted-foreground">
                            Der Grund erscheint auf der Stornorechnung und in
                            der XRechnung.
                        </p>
                        <InputError :message="cancellationForm.errors.reason" />
                    </div>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            @click="cancellationDialogOpen = false"
                        >
                            Abbrechen
                        </Button>
                        <Button
                            type="submit"
                            variant="destructive"
                            :disabled="
                                cancellationForm.processing ||
                                !cancellationForm.reason.trim() ||
                                cancellationForm.item_indices.length === 0
                            "
                        >
                            <Spinner v-if="cancellationForm.processing" />
                            Stornorechnung erstellen
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </div>
</template>
