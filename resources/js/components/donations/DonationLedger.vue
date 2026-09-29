<script setup lang="ts">
import { Ban, Download, Mail, PenLine, RotateCcw } from '@lucide/vue';
import { computed, ref } from 'vue';
import SearchableDropdown from '@/components/SearchableDropdown.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { donationTypeLabels, donationTypeOptions } from '@/lib/donations';
import { formatDate, formatMoney } from '@/lib/format';
import type {
    Certificate,
    Donation,
    DonationConfiguration,
} from '@/types/donations';

const props = defineProps<{
    donations: Donation[];
    configuration: DonationConfiguration;
    issuing: boolean;
    sending: boolean;
}>();
const emit = defineEmits<{
    issue: [donation: Donation];
    send: [certificate: Certificate];
    revoke: [certificate: Certificate];
}>();

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
const emptyFilters = () => ({
    q: '',
    from: '',
    to: '',
    donation_type: 'all',
    certificate_status: 'all',
});
const ledgerFilters = ref(emptyFilters());
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
    ledgerFilters.value = emptyFilters();
}
</script>

<template>
    <section
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
                <Label for="donation-filter-status">Bestätigungsstatus</Label>
                <SearchableDropdown
                    id="donation-filter-status"
                    v-model="ledgerFilters.certificate_status"
                    :options="filterStatusOptions"
                    search-placeholder="Status suchen"
                    empty-text="Kein Status gefunden"
                    aria-label="Bestätigungsstatus filtern"
                />
            </div>
            <Button type="button" variant="outline" @click="resetLedgerFilters"
                ><RotateCcw class="size-4" />Zurücksetzen</Button
            >
        </div>
        <div class="overflow-x-auto">
            <table class="w-full min-w-[880px] text-sm">
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
                            <abbr
                                title="Zuwendungsbestätigung ausgestellt"
                                class="no-underline"
                                >Bestätigung</abbr
                            >
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <tr
                        v-for="donation in filteredDonations"
                        :key="donation.id"
                    >
                        <td
                            class="px-4 py-3 font-mono text-xs whitespace-nowrap"
                        >
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
                                donationTypeLabels[donation.donation_type]
                            }}</Badge>
                        </td>
                        <td
                            class="px-4 py-3 text-right font-medium whitespace-nowrap"
                        >
                            {{ formatMoney(donation.amount_cents) }}
                        </td>
                        <td class="max-w-56 px-4 py-3 text-xs">
                            {{ donation.purpose_label }}
                        </td>
                        <td class="px-4 py-3">
                            <div v-if="donation.certificate" class="space-y-2">
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
                                    {{ donation.certificate.revocation_reason }}
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
                                            !donation.certificate.print_only &&
                                            !donation.certificate.revoked_at
                                        "
                                        type="button"
                                        class="inline-flex items-center gap-1 text-xs font-medium text-primary hover:underline disabled:opacity-50"
                                        :disabled="sending"
                                        @click="
                                            emit('send', donation.certificate)
                                        "
                                    >
                                        <Mail class="size-3.5" />{{
                                            donation.certificate.sent_at
                                                ? 'Erneut senden'
                                                : 'Senden'
                                        }}
                                    </button>
                                    <button
                                        v-if="!donation.certificate.revoked_at"
                                        type="button"
                                        class="inline-flex items-center gap-1 text-xs font-medium text-destructive hover:underline"
                                        @click="
                                            emit('revoke', donation.certificate)
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
                                    :disabled="issuing || !configuration.ready"
                                    @click="emit('issue', donation)"
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
</template>
