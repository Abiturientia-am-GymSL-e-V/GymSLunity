<script setup lang="ts">
import { useForm, usePage } from '@inertiajs/vue3';
import { donationTypeOptions } from '@/lib/donations';
import type { DonationConfiguration, DonationType } from '@/types/donations';
import { FilePlus2 } from '@lucide/vue';
import CountryInput from '@/components/CountryInput.vue';
import InputError from '@/components/InputError.vue';
import SearchableDropdown from '@/components/SearchableDropdown.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';

const props = defineProps<{
    purposes: Array<{ value: string; label: string }>;
    configuration: DonationConfiguration;
}>();

const today = new Date().toISOString().slice(0, 10);
const assetOriginOptions = [
    { value: 'private', label: 'Privatvermögen' },
    { value: 'business', label: 'Betriebsvermögen' },
    { value: 'unknown', label: 'Keine Angabe trotz Aufforderung' },
];
const createForm = useForm({
    donor_name: '',
    donor_street: '',
    donor_postal_code: '',
    donor_city: '',
    donor_country: String(usePage().props.defaultCountry || 'DE'),
    donor_email: '',
    donation_type: 'money' as DonationType,
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
</script>

<template>
    <form
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
                    /><InputError :message="createForm.errors.donor_street" />
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
                    /><InputError :message="createForm.errors.donor_country" />
                </div>
                <div class="space-y-2">
                    <Label for="donor-email">E-Mail-Adresse</Label
                    ><Input
                        id="donor-email"
                        v-model="createForm.donor_email"
                        type="email"
                        autocomplete="email"
                        maxlength="255"
                    /><InputError :message="createForm.errors.donor_email" />
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
                    /><InputError :message="createForm.errors.donation_type" />
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
                    /><InputError :message="createForm.errors.purpose_code" />
                    <p
                        v-if="purposes.length === 0"
                        class="text-xs text-destructive"
                    >
                        Bitte zuerst Zwecke in der Konfiguration hinterlegen.
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
                            v-model="createForm.valuation_document_reference"
                            placeholder="z. B. Rechnung vom …, Gutachten …"
                            maxlength="255"
                        /><InputError
                            :message="
                                createForm.errors.valuation_document_reference
                            "
                        />
                    </div>
                </template>
                <div
                    v-else-if="createForm.donation_type === 'expense_waiver'"
                    class="rounded-lg border bg-muted/40 p-4 text-sm sm:col-span-2"
                >
                    Die Bestätigung kennzeichnet den Verzicht auf Erstattung von
                    Aufwendungen mit „Ja“. Voraussetzung ist ein vorab
                    eingeräumter, nicht unter Verzichtsbedingung stehender
                    Erstattungsanspruch.
                </div>
            </div>
        </section>
        <div class="flex justify-end">
            <Button :disabled="createForm.processing || purposes.length === 0"
                ><Spinner v-if="createForm.processing" /><FilePlus2
                    v-else
                    class="size-4"
                />Spende verbindlich anlegen</Button
            >
        </div>
    </form>
</template>
