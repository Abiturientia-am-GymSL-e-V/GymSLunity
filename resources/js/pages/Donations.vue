<script setup lang="ts">
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import {
    Ban,
    BookOpen,
    Download,
    FileClock,
    FilePlus2,
    Mail,
    PenLine,
    RotateCcw,
    Settings2,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import CountryInput from '@/components/CountryInput.vue';
import InputError from '@/components/InputError.vue';
import SearchableDropdown from '@/components/SearchableDropdown.vue';
import SignaturePad from '@/components/SignaturePad.vue';
import StatusAlert from '@/components/StatusAlert.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
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
import { formatDate, formatMoney } from '@/lib/format';

type Certificate = {
    id: number;
    number: string;
    signed_at: string;
    signed_by: string;
    sent_at: string | null;
    sent_to: string | null;
    revoked_at: string | null;
    revoked_by: string | null;
    revocation_reason: string | null;
    print_only: boolean;
};
type Donation = {
    id: number;
    receipt_number: string;
    donor_name: string;
    donor_email: string | null;
    donation_type: 'money' | 'material' | 'membership_fee' | 'expense_waiver';
    amount_cents: number;
    donated_at: string;
    purpose_label: string;
    description: string | null;
    certificate: Certificate | null;
};
const props = defineProps<{
    activeTab: 'ledger' | 'create' | 'open';
    donations: Donation[];
    openDonations: Donation[];
    purposes: Array<{ value: string; label: string }>;
    configuration: {
        ready: boolean;
        errors: string[];
        contributions_tax_deductible: boolean;
        digital_delivery_allowed: boolean;
    };
    hasProfileSignature: boolean;
    summary: {
        count: number;
        amount_cents: number;
        open_count: number;
        issued_count: number;
        revoked_count: number;
    };
}>();
defineOptions({
    layout: { breadcrumbs: [{ title: 'Spenden', href: '/spenden' }] },
});

const tabs = [
    ['ledger', 'Spendenbuch', BookOpen, '/spenden'],
    ['create', 'Spende anlegen', FilePlus2, '/spenden/anlegen'],
    [
        'open',
        'Offene Zuwendungsbestätigungen',
        FileClock,
        '/spenden/offene-bestaetigungen',
    ],
] as const;
const typeLabels: Record<Donation['donation_type'], string> = {
    money: 'Geldzuwendung',
    material: 'Sachzuwendung',
    membership_fee: 'Mitgliedsbeitrag',
    expense_waiver: 'Aufwandsspende',
};
const donationTypeOptions = Object.entries(typeLabels).map(
    ([value, label]) => ({
        value,
        label,
    }),
);
const assetOriginOptions = [
    { value: 'private', label: 'Privatvermögen' },
    { value: 'business', label: 'Betriebsvermögen' },
    { value: 'unknown', label: 'Keine Angabe trotz Aufforderung' },
];
const filterTypeOptions = [
    { value: 'all', label: 'Alle Spendenarten' },
    ...donationTypeOptions,
];
const filterStatusOptions = [
    { value: 'all', label: 'Alle Bestätigungsstatus' },
    { value: 'open', label: 'Offen' },
    { value: 'issued', label: 'Ausgestellt' },
    { value: 'revoked', label: 'Widerrufen' },
];
const ledgerFilters = ref({
    q: '',
    from: '',
    to: '',
    donation_type: 'all',
    certificate_status: 'all',
});
const filteredDonations = computed(() => {
    const query = ledgerFilters.value.q.trim().toLocaleLowerCase('de');
    return props.donations.filter((donation) => {
        const status = !donation.certificate
            ? 'open'
            : donation.certificate.revoked_at
              ? 'revoked'
              : 'issued';
        return (
            (!query ||
                [
                    donation.receipt_number,
                    donation.donor_name,
                    donation.donor_email ?? '',
                ]
                    .join(' ')
                    .toLocaleLowerCase('de')
                    .includes(query)) &&
            (!ledgerFilters.value.from ||
                donation.donated_at >= ledgerFilters.value.from) &&
            (!ledgerFilters.value.to ||
                donation.donated_at <= ledgerFilters.value.to) &&
            (ledgerFilters.value.donation_type === 'all' ||
                donation.donation_type === ledgerFilters.value.donation_type) &&
            (ledgerFilters.value.certificate_status === 'all' ||
                status === ledgerFilters.value.certificate_status)
        );
    });
});
const reportUrl = computed(() => {
    const query = new URLSearchParams();
    for (const [key, value] of Object.entries(ledgerFilters.value)) {
        if (value && value !== 'all') query.set(key, value);
    }
    return `/spenden/spendenbuch.pdf?${query.toString()}`;
});
function resetLedgerFilters() {
    ledgerFilters.value = {
        q: '',
        from: '',
        to: '',
        donation_type: 'all',
        certificate_status: 'all',
    };
}
const today = new Date().toISOString().slice(0, 10);
const createForm = useForm({
    donor_name: '',
    donor_street: '',
    donor_postal_code: '',
    donor_city: '',
    donor_country: String(usePage().props.defaultCountry || 'DE'),
    donor_email: '',
    donation_type: 'money' as Donation['donation_type'],
    amount: '',
    donated_at: today,
    purpose_code: props.purposes[0]?.value ?? '',
    description: '',
    asset_origin: 'private',
    valuation_document_reference: '',
});
function store() {
    createForm.post('/spenden', {
        preserveScroll: true,
        onSuccess: () => {
            createForm.reset();
        },
    });
}
type SignatureMethod = 'digital' | 'profile' | 'drawn' | 'print';
const issueDialogOpen = ref(false);
const selectedDonation = ref<Donation | null>(null);
const issueForm = useForm<{
    signature_method: SignatureMethod;
    signature_data: string | null;
}>({ signature_method: 'digital', signature_data: null });
function issue(donation: Donation) {
    selectedDonation.value = donation;
    issueForm.reset();
    issueForm.signature_method = props.configuration.digital_delivery_allowed
        ? 'digital'
        : 'print';
    issueForm.clearErrors();
    issueDialogOpen.value = true;
}
function submitIssue() {
    if (!selectedDonation.value) return;
    issueForm.post(`/spenden/${selectedDonation.value.id}/ausstellen`, {
        preserveScroll: true,
        onSuccess: () => {
            issueDialogOpen.value = false;
            selectedDonation.value = null;
            issueForm.reset();
        },
    });
}
const sendForm = useForm({});
const revokeDialogOpen = ref(false);
const selectedCertificate = ref<Certificate | null>(null);
const revokeForm = useForm({ reason: '', originals_recovered: false });
function startRevoke(certificate: Certificate) {
    selectedCertificate.value = certificate;
    revokeForm.reset();
    revokeForm.clearErrors();
    revokeDialogOpen.value = true;
}
function submitRevoke() {
    if (!selectedCertificate.value) return;
    revokeForm.post(
        `/spenden/bestaetigungen/${selectedCertificate.value.id}/widerrufen`,
        {
            preserveScroll: true,
            onSuccess: () => {
                revokeDialogOpen.value = false;
                selectedCertificate.value = null;
                revokeForm.reset();
            },
        },
    );
}
const issueError = computed(() => {
    const errors = issueForm.errors as Record<string, string>;

    return (
        errors.signature_method || errors.signature_data || errors.certificate
    );
});
const actionError = computed(
    () =>
        issueError.value ||
        (sendForm.errors as Record<string, string>).email ||
        revokeForm.errors.reason ||
        revokeForm.errors.originals_recovered,
);
function send(certificate: Certificate) {
    sendForm.post(`/spenden/bestaetigungen/${certificate.id}/versenden`, {
        preserveScroll: true,
    });
}
</script>

<template>
    <Head title="Spenden" />

    <div class="mx-auto w-full max-w-[1200px] space-y-6 p-4 sm:p-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h1 class="text-2xl font-semibold tracking-tight">Spenden</h1>
                <p class="mt-1 text-sm text-muted-foreground">
                    Spenden fortlaufend erfassen und Zuwendungsbestätigungen
                    ausstellen.
                </p>
            </div>
            <a
                v-if="$page.props.can.manageConfiguration"
                href="/konfiguration/spenden"
                class="inline-flex h-9 items-center gap-2 rounded-md border bg-background px-3 text-sm font-medium shadow-xs hover:bg-accent"
                ><Settings2 class="size-4" />Spenden konfigurieren</a
            >
        </div>

        <div class="grid gap-3 sm:grid-cols-3">
            <div class="rounded-xl border bg-card p-4">
                <p class="text-sm text-muted-foreground">Spenden gesamt</p>
                <p class="mt-1 text-2xl font-semibold">{{ summary.count }}</p>
                <p class="text-sm text-muted-foreground">
                    {{ formatMoney(summary.amount_cents) }}
                </p>
            </div>
            <div class="rounded-xl border bg-card p-4">
                <p class="text-sm text-muted-foreground">
                    Offene Bestätigungen
                </p>
                <p class="mt-1 text-2xl font-semibold">
                    {{ summary.open_count }}
                </p>
            </div>
            <div class="rounded-xl border bg-card p-4">
                <p class="text-sm text-muted-foreground">Ausgestellt</p>
                <p class="mt-1 text-2xl font-semibold">
                    {{ summary.issued_count }}
                </p>
                <p
                    v-if="summary.revoked_count"
                    class="text-sm text-muted-foreground"
                >
                    davon {{ summary.revoked_count }} widerrufen
                </p>
            </div>
        </div>

        <StatusAlert
            v-if="!configuration.ready"
            type="warning"
            title="Vor der ersten Ausstellung fehlen Stammdaten"
        >
            {{ configuration.errors.join(' ') }}
        </StatusAlert>
        <StatusAlert
            v-if="
                configuration.ready && !configuration.digital_delivery_allowed
            "
            type="info"
            title="Bestätigungen werden nur zum Drucken erstellt"
        >
            Das maschinelle Verfahren wurde dem Finanzamt nicht als angezeigt
            bestätigt. Neue Belege erhalten deshalb ein freies Feld für die
            eigenhändige Unterschrift und können nicht per E-Mail versendet
            werden.
        </StatusAlert>
        <StatusAlert
            v-if="actionError"
            type="error"
            title="Aktion fehlgeschlagen"
        >
            {{ actionError }}
        </StatusAlert>

        <nav
            aria-label="Spendenbereiche"
            class="flex flex-wrap gap-2 border-b pb-4"
        >
            <Link
                v-for="tab in tabs"
                :key="tab[0]"
                :href="tab[3]"
                :aria-current="activeTab === tab[0] ? 'page' : undefined"
                class="flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-medium transition-colors hover:bg-muted"
                :class="
                    activeTab === tab[0]
                        ? 'bg-muted text-foreground'
                        : 'text-muted-foreground'
                "
            >
                <component :is="tab[2]" class="size-4" />{{ tab[1] }}
                <Badge v-if="tab[0] === 'open'" variant="secondary">{{
                    summary.open_count
                }}</Badge>
            </Link>
        </nav>

        <section
            v-if="activeTab === 'ledger'"
            class="overflow-hidden rounded-xl border bg-card"
            aria-label="Spendenbuch"
        >
            <div class="border-b px-5 py-4">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h2 class="font-semibold">Spendenbuch</h2>
                        <p class="mt-1 text-sm text-muted-foreground">
                            Fortlaufende, nach Zuwendungsdatum sortierte
                            Aufzeichnung.
                        </p>
                    </div>
                    <Button as-child variant="outline">
                        <a :href="reportUrl"><Download class="size-4" />PDF</a>
                    </Button>
                </div>
            </div>
            <div
                class="grid gap-3 border-b p-4 sm:grid-cols-2 xl:grid-cols-[minmax(0,1fr)_minmax(0,.7fr)_minmax(0,.7fr)_minmax(0,1fr)_minmax(0,1fr)_auto] xl:items-end"
            >
                <div class="min-w-0 space-y-2">
                    <Label for="donation-filter-query">Suche</Label>
                    <Input
                        id="donation-filter-query"
                        v-model="ledgerFilters.q"
                        placeholder="Name, E-Mail oder Nummer"
                    />
                </div>
                <div class="min-w-0 space-y-2">
                    <Label for="donation-filter-from">Von</Label>
                    <Input
                        id="donation-filter-from"
                        v-model="ledgerFilters.from"
                        type="date"
                    />
                </div>
                <div class="min-w-0 space-y-2">
                    <Label for="donation-filter-to">Bis</Label>
                    <Input
                        id="donation-filter-to"
                        v-model="ledgerFilters.to"
                        type="date"
                        :min="ledgerFilters.from || undefined"
                    />
                </div>
                <div class="min-w-0 space-y-2">
                    <Label for="donation-filter-type">Spendenart</Label>
                    <SearchableDropdown
                        id="donation-filter-type"
                        v-model="ledgerFilters.donation_type"
                        :options="filterTypeOptions"
                        search-placeholder="Spendenart suchen"
                        empty-text="Keine Spendenart gefunden"
                        aria-label="Spendenart filtern"
                    />
                </div>
                <div class="min-w-0 space-y-2">
                    <Label for="donation-filter-status"
                        >Bestätigungsstatus</Label
                    >
                    <SearchableDropdown
                        id="donation-filter-status"
                        v-model="ledgerFilters.certificate_status"
                        :options="filterStatusOptions"
                        search-placeholder="Status suchen"
                        empty-text="Kein Status gefunden"
                        aria-label="Bestätigungsstatus filtern"
                    />
                </div>
                <Button
                    type="button"
                    variant="outline"
                    @click="resetLedgerFilters"
                    ><RotateCcw class="size-4" />Zurücksetzen</Button
                >
            </div>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[1050px] text-sm">
                    <thead class="bg-muted/60 text-left">
                        <tr>
                            <th class="px-4 py-3 font-medium">Nr.</th>
                            <th class="px-4 py-3 font-medium">Datum</th>
                            <th class="px-4 py-3 font-medium">Spender</th>
                            <th class="px-4 py-3 font-medium">Typ</th>
                            <th class="px-4 py-3 text-right font-medium">
                                Betrag/Wert
                            </th>
                            <th class="px-4 py-3 font-medium">Zweck</th>
                            <th class="px-4 py-3 font-medium">
                                Zuwendungsbestätigung ausgestellt
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr
                            v-for="donation in filteredDonations"
                            :key="donation.id"
                        >
                            <td class="px-4 py-3 font-mono text-xs">
                                {{ donation.receipt_number }}
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap">
                                {{ formatDate(donation.donated_at) }}
                            </td>
                            <td class="px-4 py-3">
                                <p class="font-medium">
                                    {{ donation.donor_name }}
                                </p>
                                <p class="text-xs text-muted-foreground">
                                    {{ donation.donor_email || 'Keine E-Mail' }}
                                </p>
                            </td>
                            <td class="px-4 py-3">
                                <Badge variant="outline">{{
                                    typeLabels[donation.donation_type]
                                }}</Badge>
                            </td>
                            <td
                                class="px-4 py-3 text-right font-medium whitespace-nowrap"
                            >
                                {{ formatMoney(donation.amount_cents) }}
                            </td>
                            <td class="max-w-64 px-4 py-3 text-xs">
                                {{ donation.purpose_label }}
                            </td>
                            <td class="px-4 py-3">
                                <div
                                    v-if="donation.certificate"
                                    class="space-y-2"
                                >
                                    <div>
                                        <Badge
                                            :variant="
                                                donation.certificate.revoked_at
                                                    ? 'destructive'
                                                    : 'default'
                                            "
                                            >{{
                                                donation.certificate.revoked_at
                                                    ? 'Widerrufen'
                                                    : 'Ja'
                                            }}</Badge
                                        >
                                        <span class="ml-1 text-xs">{{
                                            donation.certificate.number
                                        }}</span>
                                    </div>
                                    <p
                                        v-if="donation.certificate.revoked_at"
                                        class="max-w-64 text-xs text-destructive"
                                    >
                                        {{
                                            formatDate(
                                                donation.certificate.revoked_at,
                                            )
                                        }}
                                        ·
                                        {{
                                            donation.certificate
                                                .revocation_reason
                                        }}
                                    </p>
                                    <div class="flex flex-wrap gap-2">
                                        <a
                                            :href="`/spenden/bestaetigungen/${donation.certificate.id}`"
                                            class="inline-flex items-center gap-1 text-xs font-medium text-primary hover:underline"
                                            ><Download class="size-3.5" />PDF</a
                                        >
                                        <button
                                            v-if="
                                                donation.donor_email &&
                                                configuration.digital_delivery_allowed &&
                                                !donation.certificate
                                                    .print_only &&
                                                !donation.certificate.revoked_at
                                            "
                                            type="button"
                                            class="inline-flex items-center gap-1 text-xs font-medium text-primary hover:underline disabled:opacity-50"
                                            :disabled="sendForm.processing"
                                            @click="send(donation.certificate)"
                                        >
                                            <Mail class="size-3.5" />{{
                                                donation.certificate.sent_at
                                                    ? 'Erneut senden'
                                                    : 'Senden'
                                            }}
                                        </button>
                                        <button
                                            v-if="
                                                !donation.certificate.revoked_at
                                            "
                                            type="button"
                                            class="inline-flex items-center gap-1 text-xs font-medium text-destructive hover:underline"
                                            @click="
                                                startRevoke(
                                                    donation.certificate,
                                                )
                                            "
                                        >
                                            <Ban class="size-3.5" />Widerrufen
                                        </button>
                                    </div>
                                </div>
                                <div v-else class="flex items-center gap-2">
                                    <Badge variant="secondary">Nein</Badge>
                                    <button
                                        type="button"
                                        class="inline-flex items-center gap-1 font-medium text-primary hover:underline disabled:opacity-50"
                                        :disabled="
                                            issueForm.processing ||
                                            !configuration.ready
                                        "
                                        @click="issue(donation)"
                                    >
                                        <PenLine class="size-3.5" />Ausstellen
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="filteredDonations.length === 0">
                            <td
                                colspan="7"
                                class="px-4 py-10 text-center text-muted-foreground"
                            >
                                Keine Spenden entsprechen den gewählten Filtern.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <form
            v-else-if="activeTab === 'create'"
            class="space-y-5"
            data-test="donation-form"
            novalidate
            @submit.prevent="store"
        >
            <section class="rounded-xl border bg-card">
                <div class="border-b px-5 py-4">
                    <h2 class="font-semibold">Spender</h2>
                    <p class="mt-1 text-sm text-muted-foreground">
                        Die Anschrift wird unverändert in die Bestätigung
                        übernommen.
                    </p>
                </div>
                <div class="grid gap-5 p-5 sm:grid-cols-2">
                    <div class="space-y-2 sm:col-span-2">
                        <Label for="donor-name">Name / Firma *</Label
                        ><Input
                            id="donor-name"
                            v-model="createForm.donor_name"
                            maxlength="255"
                            required
                        /><InputError :message="createForm.errors.donor_name" />
                    </div>
                    <div class="space-y-2 sm:col-span-2">
                        <Label for="donor-street">Straße und Hausnummer *</Label
                        ><Input
                            id="donor-street"
                            v-model="createForm.donor_street"
                            maxlength="255"
                            required
                        /><InputError
                            :message="createForm.errors.donor_street"
                        />
                    </div>
                    <div class="space-y-2">
                        <Label for="donor-postal">Postleitzahl *</Label
                        ><Input
                            id="donor-postal"
                            v-model="createForm.donor_postal_code"
                            maxlength="20"
                            required
                        /><InputError
                            :message="createForm.errors.donor_postal_code"
                        />
                    </div>
                    <div class="space-y-2">
                        <Label for="donor-city">Ort *</Label
                        ><Input
                            id="donor-city"
                            v-model="createForm.donor_city"
                            maxlength="255"
                            required
                        /><InputError :message="createForm.errors.donor_city" />
                    </div>
                    <div class="space-y-2">
                        <Label for="donor-country">Land *</Label
                        ><CountryInput
                            id="donor-country"
                            v-model="createForm.donor_country"
                            required
                        /><InputError
                            :message="createForm.errors.donor_country"
                        />
                    </div>
                    <div class="space-y-2">
                        <Label for="donor-email">E-Mail-Adresse</Label
                        ><Input
                            id="donor-email"
                            v-model="createForm.donor_email"
                            type="email"
                            autocomplete="email"
                            maxlength="255"
                        /><InputError
                            :message="createForm.errors.donor_email"
                        />
                        <p class="text-xs text-muted-foreground">
                            Für den Versand der Zuwendungsbestätigung.
                        </p>
                    </div>
                </div>
            </section>

            <section class="rounded-xl border bg-card">
                <div class="border-b px-5 py-4">
                    <h2 class="font-semibold">Zuwendung</h2>
                </div>
                <div class="grid gap-5 p-5 sm:grid-cols-2">
                    <div class="space-y-2">
                        <Label for="donation-type">Typ *</Label
                        ><SearchableDropdown
                            id="donation-type"
                            v-model="createForm.donation_type"
                            :options="
                                donationTypeOptions.filter(
                                    (option) =>
                                        option.value !== 'membership_fee' ||
                                        configuration.contributions_tax_deductible,
                                )
                            "
                            search-placeholder="Spendenart suchen"
                            empty-text="Keine Spendenart gefunden"
                            aria-label="Spendenart auswählen"
                            trigger-class="h-9 w-full rounded-md border border-input bg-background px-3"
                        /><InputError
                            :message="createForm.errors.donation_type"
                        />
                    </div>
                    <div class="space-y-2">
                        <Label for="donated-at">Tag der Zuwendung *</Label
                        ><Input
                            id="donated-at"
                            v-model="createForm.donated_at"
                            type="date"
                            :max="today"
                            required
                        /><InputError :message="createForm.errors.donated_at" />
                    </div>
                    <div class="space-y-2">
                        <Label for="amount">Betrag / Wert in Euro *</Label
                        ><Input
                            id="amount"
                            v-model="createForm.amount"
                            inputmode="decimal"
                            placeholder="0,00"
                            required
                        /><InputError :message="createForm.errors.amount" />
                    </div>
                    <div class="space-y-2">
                        <Label for="purpose">Steuerbegünstigter Zweck *</Label
                        ><SearchableDropdown
                            id="purpose"
                            v-model="createForm.purpose_code"
                            :options="purposes"
                            placeholder="Zweck auswählen"
                            search-placeholder="Zweck suchen"
                            empty-text="Kein Zweck gefunden"
                            aria-label="Steuerbegünstigten Zweck auswählen"
                            trigger-class="h-9 w-full rounded-md border border-input bg-background px-3"
                        /><InputError
                            :message="createForm.errors.purpose_code"
                        />
                        <p
                            v-if="purposes.length === 0"
                            class="text-xs text-destructive"
                        >
                            Bitte zuerst Zwecke in der Konfiguration
                            hinterlegen.
                        </p>
                    </div>
                    <template v-if="createForm.donation_type === 'material'">
                        <div class="space-y-2 sm:col-span-2">
                            <Label for="description"
                                >Genaue Bezeichnung, Alter, Zustand, Kaufpreis
                                *</Label
                            ><Textarea
                                id="description"
                                v-model="createForm.description"
                                rows="3"
                                maxlength="600"
                                required
                            /><InputError
                                :message="createForm.errors.description"
                            />
                        </div>
                        <div class="space-y-2">
                            <Label for="asset-origin">Herkunft *</Label
                            ><SearchableDropdown
                                id="asset-origin"
                                v-model="createForm.asset_origin"
                                :options="assetOriginOptions"
                                search-placeholder="Herkunft suchen"
                                empty-text="Keine Herkunft gefunden"
                                aria-label="Herkunft der Sachzuwendung auswählen"
                                trigger-class="h-9 w-full rounded-md border border-input bg-background px-3"
                            /><InputError
                                :message="createForm.errors.asset_origin"
                            />
                        </div>
                        <div class="space-y-2">
                            <Label for="valuation"
                                >Unterlagen zur Wertermittlung</Label
                            ><Input
                                id="valuation"
                                v-model="
                                    createForm.valuation_document_reference
                                "
                                placeholder="z. B. Rechnung vom …, Gutachten …"
                                maxlength="255"
                            /><InputError
                                :message="
                                    createForm.errors
                                        .valuation_document_reference
                                "
                            />
                        </div>
                    </template>
                    <div
                        v-else-if="
                            createForm.donation_type === 'expense_waiver'
                        "
                        class="rounded-lg border bg-muted/40 p-4 text-sm sm:col-span-2"
                    >
                        Die Bestätigung kennzeichnet den Verzicht auf Erstattung
                        von Aufwendungen mit „Ja“. Voraussetzung ist ein vorab
                        eingeräumter, nicht unter Verzichtsbedingung stehender
                        Erstattungsanspruch.
                    </div>
                </div>
            </section>
            <div class="flex justify-end">
                <Button
                    :disabled="createForm.processing || purposes.length === 0"
                    ><Spinner v-if="createForm.processing" /><FilePlus2
                        v-else
                        class="size-4"
                    />Spende verbindlich anlegen</Button
                >
            </div>
        </form>

        <section
            v-else
            class="rounded-xl border bg-card"
            aria-label="Offene Zuwendungsbestätigungen"
        >
            <div class="border-b px-5 py-4">
                <h2 class="font-semibold">Offene Zuwendungsbestätigungen</h2>
                <p class="mt-1 text-sm text-muted-foreground">
                    Spenden, für die noch keine Bestätigung ausgestellt wurde.
                </p>
            </div>
            <div class="divide-y">
                <div
                    v-for="donation in openDonations"
                    :key="donation.id"
                    class="flex flex-wrap items-center justify-between gap-4 p-5"
                >
                    <div>
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="font-medium">{{
                                donation.donor_name
                            }}</span
                            ><Badge variant="outline">{{
                                typeLabels[donation.donation_type]
                            }}</Badge>
                        </div>
                        <p class="mt-1 text-sm text-muted-foreground">
                            {{ donation.receipt_number }} ·
                            {{ formatDate(donation.donated_at) }} ·
                            {{ formatMoney(donation.amount_cents) }}
                        </p>
                        <p class="mt-1 max-w-2xl text-xs text-muted-foreground">
                            {{ donation.purpose_label }}
                        </p>
                    </div>
                    <Button
                        variant="outline"
                        :disabled="issueForm.processing || !configuration.ready"
                        @click="issue(donation)"
                        ><PenLine class="size-4" />{{
                            configuration.digital_delivery_allowed
                                ? 'Ausstellen & unterzeichnen'
                                : 'Druckversion erstellen'
                        }}</Button
                    >
                </div>
                <p
                    v-if="openDonations.length === 0"
                    class="p-10 text-center text-sm text-muted-foreground"
                >
                    Keine offenen Zuwendungsbestätigungen.
                </p>
            </div>
        </section>

        <Dialog v-model:open="issueDialogOpen">
            <DialogContent class="sm:max-w-xl">
                <DialogHeader>
                    <DialogTitle>{{
                        configuration.digital_delivery_allowed
                            ? 'Zuwendungsbestätigung unterzeichnen'
                            : 'Zuwendungsbestätigung zum Drucken erstellen'
                    }}</DialogTitle>
                    <DialogDescription>
                        <template v-if="configuration.digital_delivery_allowed"
                            >{{ $address('Wähle', 'Wählen Sie') }} die
                            Unterschriftsart für</template
                        >
                        <template v-else
                            >Erstelle eine Druckversion mit freiem
                            Unterschriftsfeld für</template
                        >
                        <strong>{{ selectedDonation?.donor_name }}</strong
                        >. Die Bestätigung wird danach unveränderlich
                        ausgestellt.
                    </DialogDescription>
                </DialogHeader>

                <form class="space-y-4" @submit.prevent="submitIssue">
                    <fieldset class="space-y-2">
                        <legend
                            v-if="configuration.digital_delivery_allowed"
                            class="text-sm font-medium"
                        >
                            Unterschriftsart
                        </legend>

                        <template v-if="configuration.digital_delivery_allowed">
                            <label
                                class="flex cursor-pointer gap-3 rounded-lg border p-3 has-checked:border-primary has-checked:bg-muted/50"
                            >
                                <input
                                    v-model="issueForm.signature_method"
                                    type="radio"
                                    value="digital"
                                    class="mt-1"
                                />
                                <span>
                                    <span class="block text-sm font-medium"
                                        >Digitale Signatur</span
                                    >
                                    <span
                                        class="block text-xs text-muted-foreground"
                                        >Digitale Freigabe mit prüfbarem
                                        Signaturcode wie bisher.</span
                                    >
                                </span>
                            </label>
                        </template>
                        <StatusAlert
                            v-else
                            type="warning"
                            title="Eigenhändige Unterschrift erforderlich"
                        >
                            Der Beleg wird ausschließlich zum Ausdrucken
                            erstellt. Fordere die verantwortliche Person auf,
                            das ausgedruckte Dokument eigenhändig zu
                            unterschreiben. Ein E-Mail-Versand ist gesperrt.
                        </StatusAlert>

                        <label
                            v-if="configuration.digital_delivery_allowed"
                            class="flex gap-3 rounded-lg border p-3 has-checked:border-primary has-checked:bg-muted/50"
                            :class="
                                props.hasProfileSignature
                                    ? 'cursor-pointer'
                                    : 'cursor-not-allowed opacity-60'
                            "
                        >
                            <input
                                v-model="issueForm.signature_method"
                                type="radio"
                                value="profile"
                                class="mt-1"
                                :disabled="!props.hasProfileSignature"
                            />
                            <span>
                                <span class="block text-sm font-medium"
                                    >Unterschrift aus dem Profil</span
                                >
                                <span
                                    class="block text-xs text-muted-foreground"
                                >
                                    <template v-if="props.hasProfileSignature">
                                        Die hinterlegte Unterschriftsgrafik in
                                        das Dokument einsetzen.
                                    </template>
                                    <template v-else>
                                        Noch keine Unterschrift hinterlegt.
                                        <a
                                            href="/settings/profile"
                                            class="font-medium text-primary hover:underline"
                                            >Jetzt im Profil hochladen</a
                                        >.
                                    </template>
                                </span>
                            </span>
                        </label>

                        <label
                            v-if="configuration.digital_delivery_allowed"
                            class="flex cursor-pointer gap-3 rounded-lg border p-3 has-checked:border-primary has-checked:bg-muted/50"
                        >
                            <input
                                v-model="issueForm.signature_method"
                                type="radio"
                                value="drawn"
                                class="mt-1"
                            />
                            <span>
                                <span class="block text-sm font-medium"
                                    >Mit Maus oder Touch unterschreiben</span
                                >
                                <span
                                    class="block text-xs text-muted-foreground"
                                    >Unterschrift direkt für dieses Dokument
                                    zeichnen.</span
                                >
                            </span>
                        </label>
                    </fieldset>

                    <SignaturePad
                        v-if="issueForm.signature_method === 'drawn'"
                        v-model="issueForm.signature_data"
                    />

                    <InputError :message="issueError" />

                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            @click="issueDialogOpen = false"
                        >
                            Abbrechen
                        </Button>
                        <Button
                            type="submit"
                            :disabled="
                                issueForm.processing ||
                                (issueForm.signature_method === 'drawn' &&
                                    !issueForm.signature_data)
                            "
                        >
                            <Spinner v-if="issueForm.processing" />
                            {{
                                issueForm.signature_method === 'print'
                                    ? 'Druckversion verbindlich erstellen'
                                    : 'Verbindlich unterzeichnen & ausstellen'
                            }}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <Dialog v-model:open="revokeDialogOpen">
            <DialogContent class="sm:max-w-xl">
                <DialogHeader>
                    <DialogTitle>Zuwendungsbestätigung widerrufen</DialogTitle>
                    <DialogDescription>
                        Der Widerruf wird dauerhaft protokolliert. Künftige
                        PDF-Abrufe erhalten ein deutliches Wasserzeichen
                        „WIDERRUFEN“.
                    </DialogDescription>
                </DialogHeader>
                <form class="space-y-4" @submit.prevent="submitRevoke">
                    <StatusAlert
                        type="warning"
                        title="Originale zuerst zurückfordern"
                    >
                        Fordere vor dem Widerruf alle ausgegebenen Originale und
                        Kopien von der spendenden Person zurück und informiere
                        sie, dass die Bestätigung nicht mehr steuerlich
                        verwendet werden darf.
                    </StatusAlert>
                    <div class="space-y-2">
                        <Label for="revocation-reason"
                            >Grund des Widerrufs *</Label
                        >
                        <Textarea
                            id="revocation-reason"
                            v-model="revokeForm.reason"
                            rows="4"
                            maxlength="1000"
                            required
                        />
                        <InputError :message="revokeForm.errors.reason" />
                    </div>
                    <label class="flex items-start gap-3 rounded-lg border p-4">
                        <input
                            v-model="revokeForm.originals_recovered"
                            type="checkbox"
                            class="mt-1 size-4 rounded border-input"
                        />
                        <span class="text-sm"
                            >Ich bestätige, dass alle ausgegebenen Originale und
                            Kopien zurückgefordert wurden.</span
                        >
                    </label>
                    <InputError
                        :message="revokeForm.errors.originals_recovered"
                    />
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            @click="revokeDialogOpen = false"
                            >Abbrechen</Button
                        >
                        <Button
                            type="submit"
                            variant="destructive"
                            :disabled="
                                revokeForm.processing ||
                                !revokeForm.reason.trim() ||
                                !revokeForm.originals_recovered
                            "
                        >
                            <Spinner v-if="revokeForm.processing" /><Ban
                                v-else
                                class="size-4"
                            />Unwiderruflich widerrufen
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </div>
</template>
