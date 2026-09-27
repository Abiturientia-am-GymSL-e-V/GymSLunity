<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    ArrowDownToLine,
    Banknote,
    BellRing,
    CalendarPlus,
    FileDown,
    FileText,
    Landmark,
    Mail,
    Plus,
    Printer,
    ReceiptText,
    RefreshCcw,
    Search,
    Upload,
    WalletCards,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import BookingFields from '@/components/payments/BookingFields.vue';
import SearchableDropdown from '@/components/SearchableDropdown.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';

type Member = {
    member_number: number;
    name: string;
    first_name: string;
    last_name: string;
    email: string | null;
    payment_method: string | null;
    mandate_reference?: string | null;
    missing?: string[];
};
type Contribution = {
    id: number;
    member_number: number;
    member_name: string;
    email: string | null;
    description: string;
    payment_reference: string | null;
    kind: string;
    amount_cents: number;
    remaining_cents: number;
    period_start: string;
    period_end: string;
    due_date: string;
    invoice_number: string | null;
    invoice_sent_at: string | null;
    mandate_sequence: string | null;
    sepa_ready: boolean;
};
type FilterField = {
    key: string;
    label: string;
    type:
        | 'text'
        | 'email'
        | 'tel'
        | 'date'
        | 'number'
        | 'decimal'
        | 'select'
        | 'boolean';
    options: Record<string, string>;
};
type BankImportRow = {
    id: number;
    payment_import_id: number;
    row_number: number;
    booking_date: string | null;
    amount_cents: number;
    purpose: string | null;
    reference: string | null;
    type: 'payment' | 'return_debit';
    reason: string;
    original_name: string;
};
type Transaction = {
    id: number;
    member_number: number;
    member_name: string;
    kind: string;
    amount_cents: number;
    booking_date: string;
    description: string;
    reference: string | null;
};
type OpenDebtor = {
    member_number: number;
    member_name: string;
    email: string | null;
    address_ready: boolean;
    open_count: number;
    open_cents: number;
    overdue_count: number;
    overdue_cents: number;
    earliest_due_date: string | null;
};
const props = defineProps<{
    activeTab:
        | 'overview'
        | 'mandates'
        | 'create'
        | 'invoices'
        | 'dunning'
        | 'sepa'
        | 'bank'
        | 'returns'
        | 'manual';
    filters: { from: string; to: string };
    summary: {
        missing_mandates: number;
        open_count: number;
        open_cents: number;
        contribution_count: number;
        contribution_cents: number;
        paid_cents: number;
    };
    missingMandates: Member[];
    contributions: Contribution[];
    openDebtors: OpenDebtor[];
    transactions: Transaction[];
    members: Member[];
    filterOptions: {
        membership_types: string[];
        payment_methods: string[];
        fields: FilterField[];
    };
    club: { tax_deductible_enabled: boolean; sepa_ready: boolean };
    recentImports: Array<{
        id: number;
        original_name: string;
        row_count: number;
        imported_count: number;
        unmatched_count: number;
        created_at: string;
    }>;
    unmatchedBankRows: BankImportRow[];
}>();
defineOptions({
    layout: { breadcrumbs: [{ title: 'Beiträge', href: '/beitraege' }] },
});

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
const money = (cents: number) =>
    new Intl.NumberFormat('de-DE', {
        style: 'currency',
        currency: 'EUR',
    }).format(cents / 100);
const date = (value: string | null) =>
    value
        ? new Intl.DateTimeFormat('de-DE').format(
              new Date(value.slice(0, 10) + 'T00:00:00'),
          )
        : '–';
const today = new Date().toISOString().slice(0, 10);
const year = new Date().getFullYear();
const filterForm = useForm({ from: props.filters.from, to: props.filters.to });
function filter() {
    const url =
        tabs.find((tab) => tab[0] === props.activeTab)?.[3] ?? '/beitraege';
    router.get(url, filterForm.data(), {
        preserveState: true,
        preserveScroll: true,
    });
}

const createForm = useForm({
    period_start: year + '-01-01',
    period_end: year + '-12-31',
    due_date: today,
    description: 'Mitgliedsbeitrag ' + year,
    amount_mode: 'fixed',
    amount: '',
    membership_type: '',
    payment_method: '',
    honorary: 'exclude',
    tax_deductible: false,
    filters: [] as Array<{ key: string; value: string }>,
});
const filterFieldToAdd = ref('');
const availableContributionFilters = computed(() =>
    props.filterOptions.fields.filter(
        (field) =>
            !createForm.filters.some((filter) => filter.key === field.key),
    ),
);
const membershipOptions = computed(() => [
    { value: '', label: 'Alle Mitgliedschaften' },
    ...props.filterOptions.membership_types.map((value) => ({
        value,
        label: value,
    })),
]);
const paymentMethodOptions = computed(() => [
    { value: '', label: 'Alle Zahlungsarten' },
    ...props.filterOptions.payment_methods.map((value) => ({
        value,
        label: value,
    })),
]);
const additionalFilterOptions = computed(() => [
    { value: '', label: 'Feld auswählen' },
    ...availableContributionFilters.value.map((field) => ({
        value: field.key,
        label: field.label,
    })),
]);
function contributionFilterField(key: string) {
    return props.filterOptions.fields.find((field) => field.key === key);
}
function contributionValueOptions(key: string) {
    const field = contributionFilterField(key);
    if (field?.type === 'boolean') {
        return [
            { value: '1', label: 'Ja' },
            { value: '0', label: 'Nein' },
        ];
    }
    return Object.entries(field?.options || {}).map(([value, label]) => ({
        value,
        label,
    }));
}
function addContributionFilter() {
    const field = contributionFilterField(filterFieldToAdd.value);
    if (!field) return;
    createForm.filters.push({
        key: field.key,
        value: field.type === 'boolean' ? '1' : '',
    });
    filterFieldToAdd.value = '';
}
function removeContributionFilter(key: string) {
    createForm.filters = createForm.filters.filter(
        (filter) => filter.key !== key,
    );
}
function period(value: string) {
    const values: Record<string, [string, string, string]> = {
        year: [year + '-01-01', year + '-12-31', 'Mitgliedsbeitrag ' + year],
        h1: [
            year + '-01-01',
            year + '-06-30',
            'Mitgliedsbeitrag 1. Halbjahr ' + year,
        ],
        h2: [
            year + '-07-01',
            year + '-12-31',
            'Mitgliedsbeitrag 2. Halbjahr ' + year,
        ],
        q1: [
            year + '-01-01',
            year + '-03-31',
            'Mitgliedsbeitrag 1. Quartal ' + year,
        ],
        q2: [
            year + '-04-01',
            year + '-06-30',
            'Mitgliedsbeitrag 2. Quartal ' + year,
        ],
        q3: [
            year + '-07-01',
            year + '-09-30',
            'Mitgliedsbeitrag 3. Quartal ' + year,
        ],
        q4: [
            year + '-10-01',
            year + '-12-31',
            'Mitgliedsbeitrag 4. Quartal ' + year,
        ],
    };
    if (values[value])
        [
            createForm.period_start,
            createForm.period_end,
            createForm.description,
        ] = values[value];
}
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
const sepaRows = computed(() =>
    props.contributions.filter((item) => item.sepa_ready),
);
const sepaSelection = ref<number[]>([]);
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
function toggleSepa(id: number) {
    sepaSelection.value = sepaSelection.value.includes(id)
        ? sepaSelection.value.filter((value) => value !== id)
        : [...sepaSelection.value, id];
}
function toggleAllSepa() {
    const ids = sepaRows.value.map((item) => item.id);
    sepaSelection.value =
        ids.length > 0 && ids.every((id) => sepaSelection.value.includes(id))
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
        const token = document.cookie
            .split('; ')
            .find((value) => value.startsWith('XSRF-TOKEN='))
            ?.slice('XSRF-TOKEN='.length);
        const response = await fetch('/beitraege/rechnungen/sammeldownload', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/pdf, application/json',
                'X-XSRF-TOKEN': decodeURIComponent(token || ''),
            },
            body: JSON.stringify({ ids: invoiceSelection.value }),
        });
        if (!response.ok || response.redirected) {
            const failure =
                response.status === 422 ? await response.json() : null;
            throw new Error(
                failure?.errors?.ids?.[0] ||
                    'Der Sammeldownload konnte nicht erstellt werden.',
            );
        }
        const objectUrl = URL.createObjectURL(await response.blob());
        const link = document.createElement('a');
        link.href = objectUrl;
        link.download = 'beitragsrechnungen.pdf';
        document.body.append(link);
        link.click();
        link.remove();
        setTimeout(() => URL.revokeObjectURL(objectUrl), 1000);
    } catch (cause) {
        invoiceDownloadError.value =
            cause instanceof Error
                ? cause.message
                : 'Der Sammeldownload ist fehlgeschlagen.';
    } finally {
        invoiceDownloadBusy.value = false;
    }
}
const collectionDate = ref(today);
const sepaBusy = ref(false);
const sepaError = ref('');
async function sepaExport() {
    sepaBusy.value = true;
    sepaError.value = '';
    try {
        const token = document.cookie
            .split('; ')
            .find((value) => value.startsWith('XSRF-TOKEN='))
            ?.slice('XSRF-TOKEN='.length);
        const response = await fetch('/beitraege/sepa-export', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-XSRF-TOKEN': decodeURIComponent(token || ''),
            },
            body: JSON.stringify({
                ids: sepaSelection.value,
                collection_date: collectionDate.value,
            }),
        });
        if (!response.ok || response.redirected) {
            const failure =
                response.status === 422
                    ? ((await response.json()) as {
                          errors?: Record<string, string[]>;
                      })
                    : null;
            throw new Error(
                failure?.errors?.ids?.[0] ||
                    'Der SEPA-Export konnte nicht erstellt werden.',
            );
        }
        const objectUrl = URL.createObjectURL(await response.blob());
        const link = document.createElement('a');
        link.href = objectUrl;
        link.download = 'sepa-lastschriften.xml';
        document.body.append(link);
        link.click();
        link.remove();
        setTimeout(() => URL.revokeObjectURL(objectUrl), 1000);
        sepaSelection.value = [];
        router.reload();
    } catch (cause) {
        sepaError.value =
            cause instanceof Error
                ? cause.message
                : 'Der SEPA-Export ist fehlgeschlagen.';
    } finally {
        sepaBusy.value = false;
    }
}
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
const returnForm = useForm({
    member_number: '',
    amount: '5.00',
    booking_date: today,
    description: 'Rücklastschriftgebühr',
    reference: '',
});
const manualForm = useForm({
    member_number: '',
    direction: 'payment' as 'payment' | 'charge',
    amount: '',
    booking_date: today,
    description: '',
    reference: '',
});
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

const dunningQuery = ref('');
const dunningSelection = ref<number[]>([]);
const dunningForm = useForm<{ member_numbers: number[] }>({
    member_numbers: [],
});
const dunningRows = computed(() => {
    const query = dunningQuery.value.trim().toLocaleLowerCase('de');
    return props.openDebtors.filter(
        (item) =>
            !query ||
            `${item.member_name} ${item.member_number} ${item.email || ''}`
                .toLocaleLowerCase('de')
                .includes(query),
    );
});
const dunningTableUrl = computed(() => {
    const params = new URLSearchParams();
    if (dunningQuery.value.trim()) {
        params.set('q', dunningQuery.value.trim());
    }
    const query = params.toString();
    return `/beitraege/mahnwesen/tabelle${query ? `?${query}` : ''}`;
});
function toggleDunning(memberNumber: number) {
    dunningSelection.value = dunningSelection.value.includes(memberNumber)
        ? dunningSelection.value.filter((value) => value !== memberNumber)
        : [...dunningSelection.value, memberNumber];
}
function toggleAllDunning() {
    const ids = dunningRows.value.map((item) => item.member_number);
    dunningSelection.value =
        ids.length > 0 && ids.every((id) => dunningSelection.value.includes(id))
            ? []
            : ids;
}
function sendDunning() {
    dunningForm.member_numbers = dunningSelection.value;
    dunningForm.post('/beitraege/mahnwesen/versenden', {
        preserveScroll: true,
        onSuccess: () => (dunningSelection.value = []),
    });
}
const dunningDownloadBusy = ref(false);
const dunningDownloadError = ref('');
async function downloadDunningLetters() {
    dunningDownloadBusy.value = true;
    dunningDownloadError.value = '';
    try {
        const token = document.cookie
            .split('; ')
            .find((value) => value.startsWith('XSRF-TOKEN='))
            ?.slice('XSRF-TOKEN='.length);
        const response = await fetch('/beitraege/mahnwesen/briefe', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/pdf, application/json',
                'X-XSRF-TOKEN': decodeURIComponent(token || ''),
            },
            body: JSON.stringify({
                member_numbers: dunningSelection.value,
            }),
        });
        if (!response.ok || response.redirected) {
            const failure =
                response.status === 422 ? await response.json() : null;
            throw new Error(
                failure?.errors?.member_numbers?.[0] ||
                    'Das PDF konnte nicht erstellt werden.',
            );
        }
        const objectUrl = URL.createObjectURL(await response.blob());
        const link = document.createElement('a');
        link.href = objectUrl;
        link.download = 'zahlungserinnerungen.pdf';
        document.body.append(link);
        link.click();
        link.remove();
        setTimeout(() => URL.revokeObjectURL(objectUrl), 1000);
    } catch (cause) {
        dunningDownloadError.value =
            cause instanceof Error
                ? cause.message
                : 'Das PDF konnte nicht erstellt werden.';
    } finally {
        dunningDownloadBusy.value = false;
    }
}
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
                :aria-current="activeTab === tab[0] ? 'page' : undefined"
                prefetch
                class="flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-medium transition-colors hover:bg-muted"
                :class="
                    activeTab === tab[0]
                        ? 'bg-muted text-foreground'
                        : 'text-muted-foreground'
                "
            >
                <component :is="tab[2]" class="size-4" />{{ tab[1] }}
            </Link>
        </nav>

        <template v-if="activeTab === 'overview'">
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
                        money(summary.open_cents)
                    }}</strong
                    ><small>{{ summary.open_count }} Posten</small>
                </div>
                <div class="rounded-xl border bg-card p-5">
                    <span class="text-sm text-muted-foreground"
                        >Beiträge im Zeitraum</span
                    ><strong class="mt-2 block text-3xl">{{
                        money(summary.contribution_cents)
                    }}</strong
                    ><small>{{ summary.contribution_count }} Beiträge</small>
                </div>
                <div class="rounded-xl border bg-card p-5">
                    <span class="text-sm text-muted-foreground"
                        >Davon bezahlt</span
                    ><strong class="mt-2 block text-3xl text-emerald-700">{{
                        money(summary.paid_cents)
                    }}</strong>
                </div>
            </div>
            <section class="overflow-hidden rounded-xl border bg-card">
                <div class="space-y-4 border-b p-5">
                    <div
                        class="flex flex-wrap items-start justify-between gap-3"
                    >
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
                                    {{ date(entry.booking_date) }}
                                </td>
                                <td class="p-3">
                                    <a
                                        class="font-medium hover:underline"
                                        :href="
                                            '/mitglieder/' + entry.member_number
                                        "
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
                                    }}<small
                                        v-if="entry.reference"
                                        class="block"
                                        >{{ entry.reference }}</small
                                    >
                                </td>
                                <td
                                    class="p-3 text-right font-medium"
                                    :class="{
                                        'text-emerald-700':
                                            entry.amount_cents < 0,
                                    }"
                                >
                                    {{ money(entry.amount_cents) }}
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
        </template>

        <section
            v-else-if="activeTab === 'mandates'"
            class="overflow-hidden rounded-xl border bg-card"
        >
            <div
                class="flex flex-wrap items-center justify-between gap-3 border-b p-5"
            >
                <div class="space-y-2">
                    <h2 class="font-semibold">Fehlende SEPA-Mandate</h2>
                    <p class="text-sm text-muted-foreground">
                        Unvollständige Mandatsdaten bei Zahlungsart
                        SEPA-Lastschrift.
                    </p>
                </div>
                <div class="flex gap-2">
                    <Button as-child variant="outline"
                        ><a href="/beitraege/mandate/export?format=csv"
                            ><FileDown class="size-4" />CSV</a
                        ></Button
                    ><Button as-child variant="outline"
                        ><a
                            href="/beitraege/mandate/export?format=print"
                            target="_blank"
                            ><Printer class="size-4" />Drucken</a
                        ></Button
                    >
                </div>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left">
                        <tr>
                            <th class="p-3">Mitglied</th>
                            <th class="p-3">E-Mail</th>
                            <th class="p-3">Fehlt</th>
                            <th class="p-3">Referenz</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr
                            v-for="member in missingMandates"
                            :key="member.member_number"
                        >
                            <td class="p-3">
                                <a
                                    class="font-medium hover:underline"
                                    :href="
                                        '/mitglieder/' + member.member_number
                                    "
                                    >{{ member.name }}</a
                                ><small class="block"
                                    >Nr. {{ member.member_number }}</small
                                >
                            </td>
                            <td class="p-3">{{ member.email || '–' }}</td>
                            <td class="p-3">
                                <Badge
                                    v-for="reason in member.missing"
                                    :key="reason"
                                    variant="outline"
                                    class="mr-1"
                                    >{{ reason }}</Badge
                                >
                            </td>
                            <td class="p-3">
                                {{ member.mandate_reference || '–' }}
                            </td>
                        </tr>
                        <tr v-if="!missingMandates.length">
                            <td
                                colspan="4"
                                class="p-8 text-center text-muted-foreground"
                            >
                                Alle SEPA-Mandate sind vollständig.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section
            v-else-if="activeTab === 'dunning'"
            class="overflow-hidden rounded-xl border bg-card"
        >
            <div class="space-y-4 border-b p-5">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h2 class="font-semibold">Offene Beiträge</h2>
                        <p class="text-sm text-muted-foreground">
                            Mitglieder auswählen und per E-Mail oder PDF an
                            offene Beträge erinnern.
                        </p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <Button as-child variant="outline">
                            <a href="/beitraege/mahnwesen/export">
                                <FileDown class="size-4" />CSV
                            </a>
                        </Button>
                        <Button as-child variant="outline">
                            <a :href="dunningTableUrl" target="_blank">
                                <Printer class="size-4" />Drucken
                            </a>
                        </Button>
                        <Button
                            variant="outline"
                            :disabled="
                                !dunningSelection.length || dunningDownloadBusy
                            "
                            @click="downloadDunningLetters"
                        >
                            <Spinner v-if="dunningDownloadBusy" />
                            <FileDown v-else class="size-4" />PDF
                        </Button>
                        <Button
                            :disabled="
                                !dunningSelection.length ||
                                dunningForm.processing
                            "
                            @click="sendDunning"
                        >
                            <Spinner v-if="dunningForm.processing" />
                            <Mail v-else class="size-4" />Mail senden
                        </Button>
                    </div>
                </div>
                <InputError :message="dunningForm.errors.member_numbers" />
                <InputError :message="dunningDownloadError" />
                <div class="relative max-w-md">
                    <Search
                        class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                    />
                    <Input
                        v-model="dunningQuery"
                        type="search"
                        class="pl-9"
                        placeholder="Name, Mitgliedsnummer oder E-Mail"
                        aria-label="Offene Beiträge durchsuchen"
                    />
                </div>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left">
                        <tr>
                            <th class="p-3">
                                <input
                                    type="checkbox"
                                    aria-label="Alle sichtbaren Mitglieder auswählen"
                                    @change="toggleAllDunning"
                                />
                            </th>
                            <th class="p-3">Mitglied</th>
                            <th class="p-3">Kontakt</th>
                            <th class="p-3">Älteste Fälligkeit</th>
                            <th class="p-3 text-right">Offen</th>
                            <th class="p-3">Dokumente</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr
                            v-for="item in dunningRows"
                            :key="item.member_number"
                        >
                            <td class="p-3">
                                <input
                                    type="checkbox"
                                    :checked="
                                        dunningSelection.includes(
                                            item.member_number,
                                        )
                                    "
                                    :aria-label="`${item.member_name} auswählen`"
                                    @change="toggleDunning(item.member_number)"
                                />
                            </td>
                            <td class="p-3">
                                <a
                                    class="font-medium hover:underline"
                                    :href="'/mitglieder/' + item.member_number"
                                >
                                    {{ item.member_name }}
                                </a>
                                <small class="block"
                                    >Nr. {{ item.member_number }} ·
                                    {{ item.open_count }} Posten</small
                                >
                            </td>
                            <td class="p-3">
                                <span>{{ item.email || 'keine E-Mail' }}</span>
                                <small
                                    v-if="!item.address_ready"
                                    class="block text-amber-700"
                                    >Postanschrift unvollständig</small
                                >
                            </td>
                            <td class="p-3">
                                {{ date(item.earliest_due_date) }}
                                <small class="block">
                                    {{ item.overdue_count }} überfällig ·
                                    {{ money(item.overdue_cents) }}
                                </small>
                            </td>
                            <td class="p-3 text-right font-medium">
                                {{ money(item.open_cents) }}
                            </td>
                            <td class="p-3">
                                <div class="flex gap-1">
                                    <Button
                                        as-child
                                        size="icon-sm"
                                        variant="ghost"
                                    >
                                        <a
                                            :href="
                                                '/beitraege/mahnwesen/' +
                                                item.member_number +
                                                '?format=pdf'
                                            "
                                            :aria-label="`Zahlungserinnerung für ${item.member_name} herunterladen`"
                                            title="PDF herunterladen"
                                        >
                                            <FileDown class="size-4" />
                                        </a>
                                    </Button>
                                    <Button
                                        as-child
                                        size="icon-sm"
                                        variant="ghost"
                                    >
                                        <a
                                            :href="
                                                '/beitraege/mahnwesen/' +
                                                item.member_number +
                                                '?format=print'
                                            "
                                            target="_blank"
                                            :aria-label="`Zahlungserinnerung für ${item.member_name} drucken`"
                                            title="Zahlungserinnerung drucken"
                                        >
                                            <Printer class="size-4" />
                                        </a>
                                    </Button>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="!dunningRows.length">
                            <td
                                colspan="6"
                                class="p-8 text-center text-muted-foreground"
                            >
                                Keine passenden offenen Beiträge.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <form
            v-else-if="activeTab === 'create'"
            class="space-y-5 rounded-xl border bg-card p-5"
            @submit.prevent="
                createForm.post('/beitraege/anlegen', { preserveScroll: true })
            "
        >
            <div>
                <h2 class="font-semibold">Offene Beiträge anlegen</h2>
                <p class="text-sm text-muted-foreground">
                    Aktive Mitglieder nach Eigenschaften filtern; identische
                    Beiträge werden übersprungen.
                </p>
            </div>
            <div class="grid gap-5 md:grid-cols-2 2xl:grid-cols-3">
                <div class="min-w-0 space-y-2">
                    <Label for="period">Zeitraumvorlage</Label
                    ><select
                        id="period"
                        class="field"
                        @change="
                            period(($event.target as HTMLSelectElement).value)
                        "
                    >
                        <option value="year">Jahr {{ year }}</option>
                        <option value="h1">1. Halbjahr</option>
                        <option value="h2">2. Halbjahr</option>
                        <option value="q1">1. Quartal</option>
                        <option value="q2">2. Quartal</option>
                        <option value="q3">3. Quartal</option>
                        <option value="q4">4. Quartal</option>
                    </select>
                </div>
                <div class="min-w-0 space-y-2">
                    <Label for="period-start">Von</Label
                    ><Input
                        id="period-start"
                        v-model="createForm.period_start"
                        type="date"
                        class="date-safe"
                    /><InputError :message="createForm.errors.period_start" />
                </div>
                <div class="min-w-0 space-y-2">
                    <Label for="period-end">Bis</Label
                    ><Input
                        id="period-end"
                        v-model="createForm.period_end"
                        type="date"
                        class="date-safe"
                    /><InputError :message="createForm.errors.period_end" />
                </div>
                <div class="min-w-0 space-y-2">
                    <Label for="due-date">Fällig am</Label
                    ><Input
                        id="due-date"
                        v-model="createForm.due_date"
                        type="date"
                        class="date-safe"
                    /><InputError :message="createForm.errors.due_date" />
                </div>
                <div class="space-y-2 md:col-span-2">
                    <Label for="description">Bezeichnung</Label
                    ><Input
                        id="description"
                        v-model="createForm.description"
                    /><InputError :message="createForm.errors.description" />
                </div>
                <div class="space-y-2">
                    <Label for="amount-mode">Betragsquelle</Label
                    ><select
                        id="amount-mode"
                        v-model="createForm.amount_mode"
                        class="field"
                    >
                        <option value="fixed">Fester Betrag</option>
                        <option value="member">
                            Förderbeitrag des Mitglieds
                        </option>
                    </select>
                </div>
                <div
                    v-if="createForm.amount_mode === 'fixed'"
                    class="space-y-2"
                >
                    <Label for="amount">Betrag in Euro</Label
                    ><Input
                        id="amount"
                        v-model="createForm.amount"
                        type="number"
                        min="0.01"
                        step="0.01"
                    /><InputError :message="createForm.errors.amount" />
                </div>
                <div class="space-y-2">
                    <Label for="membership">Mitgliedschaft</Label
                    ><SearchableDropdown
                        id="membership"
                        v-model="createForm.membership_type"
                        :options="membershipOptions"
                        search-placeholder="Mitgliedschaft suchen"
                        aria-label="Mitgliedschaft filtern"
                        trigger-class="h-9 w-full rounded-md border border-input bg-background px-3"
                    />
                </div>
                <div class="space-y-2">
                    <Label for="payment-method">Zahlungsart</Label
                    ><SearchableDropdown
                        id="payment-method"
                        v-model="createForm.payment_method"
                        :options="paymentMethodOptions"
                        search-placeholder="Zahlungsart suchen"
                        aria-label="Zahlungsart filtern"
                        trigger-class="h-9 w-full rounded-md border border-input bg-background px-3"
                    />
                </div>
                <div class="space-y-2">
                    <Label for="honorary">Ehrenmitglieder</Label
                    ><select
                        id="honorary"
                        v-model="createForm.honorary"
                        class="field"
                    >
                        <option value="exclude">Ausschließen</option>
                        <option value="include">Einschließen</option>
                        <option value="only">Nur Ehrenmitglieder</option>
                    </select>
                </div>
            </div>
            <section class="space-y-4 rounded-lg border bg-muted/20 p-4">
                <div>
                    <h3 class="text-sm font-medium">Weitere Filter</h3>
                    <p class="text-xs text-muted-foreground">
                        Verfügbare Filter richten sich nach der
                        Mitgliederfeld-Konfiguration.
                    </p>
                </div>
                <div
                    v-if="createForm.filters.length"
                    class="grid gap-4 md:grid-cols-2 xl:grid-cols-3"
                >
                    <div
                        v-for="filterItem in createForm.filters"
                        :key="filterItem.key"
                        class="min-w-0 space-y-2"
                    >
                        <div class="flex items-center justify-between gap-2">
                            <Label
                                :for="`contribution-filter-${filterItem.key}`"
                            >
                                {{
                                    contributionFilterField(filterItem.key)
                                        ?.label
                                }}
                            </Label>
                            <button
                                type="button"
                                class="text-xs text-muted-foreground hover:text-foreground"
                                @click="
                                    removeContributionFilter(filterItem.key)
                                "
                            >
                                Entfernen
                            </button>
                        </div>
                        <SearchableDropdown
                            v-if="
                                ['select', 'boolean'].includes(
                                    contributionFilterField(filterItem.key)
                                        ?.type || '',
                                )
                            "
                            :id="`contribution-filter-${filterItem.key}`"
                            v-model="filterItem.value"
                            :options="contributionValueOptions(filterItem.key)"
                            search-placeholder="Wert suchen"
                            :aria-label="
                                contributionFilterField(filterItem.key)?.label
                            "
                            trigger-class="h-9 w-full rounded-md border border-input bg-background px-3"
                        />
                        <Input
                            v-else
                            :id="`contribution-filter-${filterItem.key}`"
                            v-model="filterItem.value"
                            :type="
                                contributionFilterField(filterItem.key)
                                    ?.type === 'date'
                                    ? 'date'
                                    : ['number', 'decimal'].includes(
                                            contributionFilterField(
                                                filterItem.key,
                                            )?.type || '',
                                        )
                                      ? 'number'
                                      : 'text'
                            "
                            :step="
                                contributionFilterField(filterItem.key)
                                    ?.type === 'decimal'
                                    ? '0.01'
                                    : undefined
                            "
                            :class="{
                                'date-safe':
                                    contributionFilterField(filterItem.key)
                                        ?.type === 'date',
                            }"
                            required
                        />
                    </div>
                </div>
                <div
                    v-if="availableContributionFilters.length"
                    class="flex flex-col gap-2 sm:flex-row"
                >
                    <SearchableDropdown
                        v-model="filterFieldToAdd"
                        id="additional-contribution-filter"
                        :options="additionalFilterOptions"
                        root-class="w-full sm:max-w-sm"
                        trigger-class="h-9 w-full rounded-md border border-input bg-background px-3"
                        aria-label="Weiteren Filter auswählen"
                        search-placeholder="Feld suchen"
                    />
                    <Button
                        type="button"
                        variant="outline"
                        :disabled="!filterFieldToAdd"
                        @click="addContributionFilter"
                    >
                        <Plus class="size-4" />Filter hinzufügen
                    </Button>
                </div>
                <p v-else class="text-xs text-muted-foreground">
                    Alle verfügbaren Filter wurden hinzugefügt.
                </p>
            </section>
            <label v-if="club.tax_deductible_enabled" class="flex gap-2 text-sm"
                ><input
                    v-model="createForm.tax_deductible"
                    type="checkbox"
                />Als möglicherweise steuerlich abzugsfähig kennzeichnen</label
            >
            <Button type="submit" :disabled="createForm.processing"
                ><Spinner v-if="createForm.processing" /><CalendarPlus
                    v-else
                    class="size-4"
                />Beiträge anlegen</Button
            >
        </form>

        <section
            v-else-if="activeTab === 'invoices'"
            class="overflow-hidden rounded-xl border bg-card"
        >
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
                                    >{{ date(item.period_start) }}–{{
                                        date(item.period_end)
                                    }}</small
                                ><small
                                    v-if="item.payment_reference"
                                    class="block font-mono"
                                >
                                    {{ item.payment_reference }}
                                </small>
                            </td>
                            <td class="p-3">{{ date(item.due_date) }}</td>
                            <td class="p-3 text-right">
                                {{ money(item.amount_cents) }}
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

        <section
            v-else-if="activeTab === 'sepa'"
            class="overflow-hidden rounded-xl border bg-card"
        >
            <div
                class="flex flex-wrap items-end justify-between gap-3 border-b p-5"
            >
                <div>
                    <h2 class="font-semibold">SEPA-Lastschrift-Datei</h2>
                    <p class="text-sm text-muted-foreground">
                        PAIN.008 exportieren und Beiträge als bezahlt verbuchen.
                    </p>
                </div>
                <div
                    class="grid w-full gap-3 2xl:w-auto 2xl:grid-cols-[minmax(0,18rem)_auto] 2xl:items-end"
                >
                    <div class="min-w-0 space-y-2">
                        <Label for="collection-date">Einzugsdatum</Label
                        ><Input
                            id="collection-date"
                            v-model="collectionDate"
                            type="date"
                            :min="today"
                            class="date-safe"
                        />
                    </div>
                    <Button
                        :disabled="
                            !sepaSelection.length ||
                            sepaBusy ||
                            !club.sepa_ready
                        "
                        @click="sepaExport"
                        ><Spinner v-if="sepaBusy" /><ArrowDownToLine
                            v-else
                            class="size-4"
                        />Exportieren</Button
                    >
                </div>
                <p
                    v-if="!club.sepa_ready"
                    class="basis-full text-sm text-amber-700"
                >
                    Vereinsname, Vereins-IBAN und Gläubiger-ID fehlen in der
                    Konfiguration.
                </p>
                <p v-if="sepaError" class="basis-full text-sm text-destructive">
                    {{ sepaError }}
                </p>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left">
                        <tr>
                            <th class="p-3">
                                <input
                                    type="checkbox"
                                    @change="toggleAllSepa"
                                />
                            </th>
                            <th class="p-3">Mitglied</th>
                            <th class="p-3">Beitrag</th>
                            <th class="p-3">Fällig</th>
                            <th class="p-3 text-right">Einzug</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="item in sepaRows" :key="item.id">
                            <td class="p-3">
                                <input
                                    type="checkbox"
                                    :checked="sepaSelection.includes(item.id)"
                                    @change="toggleSepa(item.id)"
                                />
                            </td>
                            <td class="p-3">
                                {{ item.member_name
                                }}<small class="block"
                                    >Nr. {{ item.member_number }}</small
                                >
                            </td>
                            <td class="p-3">{{ item.description }}</td>
                            <td class="p-3">{{ date(item.due_date) }}</td>
                            <td class="p-3 text-right font-medium">
                                {{ money(item.remaining_cents) }}
                            </td>
                        </tr>
                        <tr v-if="!sepaRows.length">
                            <td
                                colspan="5"
                                class="p-8 text-center text-muted-foreground"
                            >
                                Keine einziehbaren Beiträge im Zeitraum.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <div
            v-else-if="activeTab === 'bank'"
            class="grid items-start gap-5 lg:grid-cols-2"
        >
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
                            {{ date(item.created_at) }}</small
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
                                <strong>{{ money(row.amount_cents) }}</strong>
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
                                    {{ date(row.booking_date) }}
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

        <form
            v-else-if="activeTab === 'returns'"
            class="max-w-3xl space-y-5 rounded-xl border bg-card p-5"
            @submit.prevent="
                returnForm.post('/beitraege/ruecklastschriften', {
                    preserveScroll: true,
                })
            "
        >
            <div>
                <h2 class="font-semibold">Rücklastschriftgebühr anlasten</h2>
                <p class="text-sm text-muted-foreground">
                    Die Gebühr wird als offener Posten auf dem Beitragskonto
                    gebucht.
                </p>
            </div>
            <BookingFields
                :form="returnForm"
                :members="members"
                amount-label="Gebühr in Euro"
            />
            <Button type="submit" :disabled="returnForm.processing"
                ><Spinner v-if="returnForm.processing" /><RefreshCcw
                    v-else
                    class="size-4"
                />Gebühr anlasten</Button
            >
        </form>

        <form
            v-else-if="activeTab === 'manual'"
            class="max-w-3xl space-y-5 rounded-xl border bg-card p-5"
            @submit.prevent="
                manualForm.post('/beitraege/manuell-buchen', {
                    preserveScroll: true,
                })
            "
        >
            <div>
                <h2 class="font-semibold">Zahlung manuell verbuchen</h2>
                <p class="text-sm text-muted-foreground">
                    Die Zahlung wird auf die ältesten offenen Beiträge verteilt.
                </p>
            </div>
            <BookingFields
                :form="manualForm"
                :members="members"
                :amount-label="
                    manualForm.direction === 'payment'
                        ? 'Zahlbetrag in Euro'
                        : 'Forderungsbetrag in Euro'
                "
                show-direction
            />
            <Button type="submit" :disabled="manualForm.processing"
                ><Spinner v-if="manualForm.processing" /><Banknote
                    v-else
                    class="size-4"
                />{{
                    manualForm.direction === 'payment'
                        ? 'Zahlung verbuchen'
                        : 'Forderung verbuchen'
                }}</Button
            >
        </form>
    </div>
</template>

<style scoped>
.field {
    height: 2.25rem;
    width: 100%;
    border-radius: 0.375rem;
    border: 1px solid var(--border);
    background: var(--background);
    padding: 0 0.75rem;
    font-size: 0.875rem;
}
small {
    color: var(--muted-foreground);
}
</style>
