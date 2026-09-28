<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { Plus, Trash2 } from '@lucide/vue';
import { computed, watch } from 'vue';
import CountryInput from '@/components/CountryInput.vue';
import InvoiceNav from '@/components/finance/InvoiceNav.vue';
import InputError from '@/components/InputError.vue';
import SearchableDropdown from '@/components/SearchableDropdown.vue';
import StatusAlert from '@/components/StatusAlert.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { formatMoney } from '@/lib/format';

type Item = {
    description: string;
    quantity: string;
    unit_code: 'C62' | 'HUR' | 'DAY';
    price_mode: 'net' | 'gross';
    unit_price: string;
    vat_rate: '0' | '7' | '19';
    tax_exemption_reason: string;
};
type FinanceMandate = {
    id: number;
    mandate_reference: string;
    debtor_name: string;
    debtor_email: string | null;
    iban: string;
    mandate_type: 'recurring' | 'one_off';
    signed_at: string;
};
const props = defineProps<{
    creationKey: string;
    today: string;
    defaultDueDate: string;
    defaultCountry: string;
    paymentReadiness: { bank_transfer: boolean; sepa_direct_debit: boolean };
    clubReadiness: { ready: boolean; missing: string[] };
    smallBusinessRegulationEnabled: boolean;
    smallBusinessNotice: string;
    financeMandates: FinanceMandate[];
    bookingPrefill: {
        id: number;
        recipient_name: string;
        buyer_reference: string;
        service_date: string;
        description: string;
        unit_price: string;
    } | null;
}>();
defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Buchhaltung', href: '/buchhaltung' },
            { title: 'Rechnungswesen', href: '/buchhaltung/rechnungen' },
            { title: 'Rechnung erstellen' },
        ],
    },
});
const emptyItem = (): Item => ({
    description: '',
    quantity: '1',
    unit_code: 'C62',
    price_mode: 'net',
    unit_price: '',
    vat_rate: props.smallBusinessRegulationEnabled ? '0' : '19',
    tax_exemption_reason: props.smallBusinessRegulationEnabled
        ? props.smallBusinessNotice
        : '',
});
const form = useForm({
    creation_key: props.creationKey,
    booking_id: props.bookingPrefill?.id ?? null,
    recipient_name: props.bookingPrefill?.recipient_name ?? '',
    recipient_street: '',
    recipient_postal_code: '',
    recipient_city: '',
    recipient_country: props.defaultCountry || 'DE',
    recipient_email: '',
    buyer_reference: props.bookingPrefill?.buyer_reference ?? '',
    issue_date: props.today,
    service_date: props.bookingPrefill?.service_date ?? props.today,
    due_date: props.defaultDueDate,
    currency: 'EUR',
    payment_method: (props.paymentReadiness.bank_transfer
        ? 'bank_transfer'
        : 'cash') as
        | 'bank_transfer'
        | 'sepa_direct_debit'
        | 'cash'
        | 'card'
        | 'other',
    finance_mandate_id: '',
    debtor_iban: '',
    mandate_reference: '',
    mandate_signed_at: '',
    mandate_type: 'recurring' as 'recurring' | 'one_off',
    notes: '',
    items: [
        {
            ...emptyItem(),
            description: props.bookingPrefill?.description ?? '',
            unit_price: props.bookingPrefill?.unit_price ?? '',
            price_mode: 'gross',
        } as Item,
    ],
});
const mandateOptions = computed(() =>
    props.financeMandates.map((mandate) => ({
        value: String(mandate.id),
        label: `${mandate.mandate_reference} · ${mandate.debtor_name}`,
        suffix: `IBAN •••• ${mandate.iban.slice(-4)} · ${mandate.mandate_type === 'one_off' ? 'Einmalig' : 'Wiederkehrend'}`,
        search: `${mandate.mandate_reference} ${mandate.debtor_name} ${mandate.debtor_email ?? ''} ${mandate.iban}`,
    })),
);
const selectedMandate = computed(() =>
    props.financeMandates.find(
        (mandate) => String(mandate.id) === form.finance_mandate_id,
    ),
);
watch(
    () => form.payment_method,
    (method) => {
        if (method !== 'sepa_direct_debit') form.finance_mandate_id = '';
    },
);
const parseCents = (value: string) => {
    const normalized = value.replace(',', '.');
    return /^\d{1,9}(\.\d{1,2})?$/.test(normalized)
        ? Math.round(Number(normalized) * 100)
        : 0;
};
const itemValues = computed(() =>
    form.items.map((item) => {
        const quantity = Math.round(
            (Number(item.quantity.replace(',', '.')) || 0) * 1000,
        );
        const lineAmount = Math.round(
            (parseCents(item.unit_price) * quantity) / 1000,
        );
        const rate = Number(item.vat_rate);
        if (item.price_mode === 'gross') {
            const net = Math.round((lineAmount * 100) / (100 + rate));
            return { net, tax: lineAmount - net, gross: lineAmount };
        }
        const tax = Math.round((lineAmount * rate) / 100);
        return { net: lineAmount, tax, gross: lineAmount + tax };
    }),
);
const totals = computed(() =>
    itemValues.value.reduce(
        (sum, item) => ({
            net: sum.net + item.net,
            tax: sum.tax + item.tax,
            gross: sum.gross + item.gross,
        }),
        { net: 0, tax: 0, gross: 0 },
    ),
);
const error = (index: number, field: keyof Item) =>
    form.errors[`items.${index}.${field}` as keyof typeof form.errors] as
        | string
        | undefined;
function addItem() {
    form.items.push(emptyItem());
}
function removeItem(index: number) {
    if (form.items.length > 1) form.items.splice(index, 1);
}
</script>

<template>
    <Head title="Rechnung erstellen" />
    <div class="mx-auto w-full max-w-[1200px] space-y-6 p-4 sm:p-6">
        <header>
            <h1 class="text-2xl font-semibold tracking-tight">
                Rechnungswesen
            </h1>
            <p class="mt-1 text-sm text-muted-foreground">
                Rechnungen erstellen und offene Forderungen nachverfolgen.
            </p>
        </header>
        <InvoiceNav active="create" />
        <StatusAlert
            v-if="!clubReadiness.ready"
            type="warning"
            title="Vereinsdaten vervollständigen"
        >
            Für die beim Erstellen erzeugte XRechnung fehlen:
            <strong>{{ clubReadiness.missing.join(', ') }}</strong
            >. Hinterlege diese Angaben in der
            <Link href="/konfiguration/verein" class="font-medium underline"
                >Vereinskonfiguration</Link
            >.
        </StatusAlert>
        <StatusAlert
            v-if="Object.keys(form.errors).length"
            type="error"
            title="Rechnung nicht erstellt"
            :messages="Object.values(form.errors)"
        />

        <form
            class="space-y-6"
            @submit.prevent="form.post('/buchhaltung/rechnungen')"
        >
            <section class="rounded-xl border bg-card">
                <h2 class="border-b px-5 py-4 text-sm font-semibold">
                    Rechnungsempfänger
                </h2>
                <div class="grid gap-5 p-5 sm:grid-cols-2">
                    <div class="space-y-2 sm:col-span-2">
                        <Label for="recipient-name">Name / Firma *</Label
                        ><Input
                            id="recipient-name"
                            v-model="form.recipient_name"
                            maxlength="255"
                            required
                        /><InputError :message="form.errors.recipient_name" />
                    </div>
                    <div class="space-y-2 sm:col-span-2">
                        <Label for="recipient-street"
                            >Straße und Hausnummer *</Label
                        ><Input
                            id="recipient-street"
                            v-model="form.recipient_street"
                            maxlength="255"
                            required
                        /><InputError :message="form.errors.recipient_street" />
                    </div>
                    <div class="space-y-2">
                        <Label for="recipient-postal-code">Postleitzahl *</Label
                        ><Input
                            id="recipient-postal-code"
                            v-model="form.recipient_postal_code"
                            maxlength="20"
                            required
                        /><InputError
                            :message="form.errors.recipient_postal_code"
                        />
                    </div>
                    <div class="space-y-2">
                        <Label for="recipient-city">Ort *</Label
                        ><Input
                            id="recipient-city"
                            v-model="form.recipient_city"
                            maxlength="255"
                            required
                        /><InputError :message="form.errors.recipient_city" />
                    </div>
                    <div class="space-y-2">
                        <Label for="recipient-country">Land *</Label
                        ><CountryInput
                            id="recipient-country"
                            v-model="form.recipient_country"
                        /><InputError
                            :message="form.errors.recipient_country"
                        />
                    </div>
                    <div class="space-y-2">
                        <Label for="recipient-email"
                            >E-Mail / elektronische Adresse *</Label
                        ><Input
                            id="recipient-email"
                            v-model="form.recipient_email"
                            type="email"
                            maxlength="255"
                            required
                        /><InputError :message="form.errors.recipient_email" />
                    </div>
                    <div class="space-y-2 sm:col-span-2">
                        <Label for="buyer-reference"
                            >Käuferreferenz / Leitweg-ID *</Label
                        ><Input
                            id="buyer-reference"
                            v-model="form.buyer_reference"
                            maxlength="100"
                            required
                        />
                        <p class="text-xs text-muted-foreground">
                            Bei Behörden die Leitweg-ID, sonst z. B.
                            Kundennummer, Auftrag oder Ansprechpartner.
                        </p>
                        <InputError :message="form.errors.buyer_reference" />
                    </div>
                </div>
            </section>

            <section class="rounded-xl border bg-card">
                <h2 class="border-b px-5 py-4 text-sm font-semibold">
                    Rechnungsdaten
                </h2>
                <div class="grid gap-5 p-5 sm:grid-cols-2 lg:grid-cols-4">
                    <div class="space-y-2">
                        <Label for="issue-date">Rechnungsdatum *</Label
                        ><Input
                            id="issue-date"
                            v-model="form.issue_date"
                            type="date"
                            :max="today"
                            required
                        /><InputError :message="form.errors.issue_date" />
                    </div>
                    <div class="space-y-2">
                        <Label for="service-date">Leistungsdatum *</Label
                        ><Input
                            id="service-date"
                            v-model="form.service_date"
                            type="date"
                            required
                        /><InputError :message="form.errors.service_date" />
                    </div>
                    <div class="space-y-2">
                        <Label for="due-date"
                            >{{
                                form.payment_method === 'sepa_direct_debit'
                                    ? 'Einzug ab'
                                    : 'Fällig am'
                            }}
                            *</Label
                        ><Input
                            id="due-date"
                            v-model="form.due_date"
                            type="date"
                            :min="form.issue_date"
                            required
                        /><InputError :message="form.errors.due_date" />
                    </div>
                    <div class="space-y-2">
                        <Label for="currency">Währung *</Label
                        ><Input
                            id="currency"
                            v-model="form.currency"
                            disabled
                        />
                    </div>
                    <div class="space-y-2 sm:col-span-2 lg:col-span-4">
                        <Label for="notes">Hinweise auf der Rechnung</Label
                        ><Textarea
                            id="notes"
                            v-model="form.notes"
                            maxlength="2000"
                            rows="3"
                        /><InputError :message="form.errors.notes" />
                    </div>
                </div>
            </section>

            <section class="rounded-xl border bg-card">
                <div
                    class="flex flex-wrap items-center justify-between gap-3 border-b px-5 py-4"
                >
                    <h2 class="text-sm font-semibold">Rechnungspositionen</h2>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        @click="addItem"
                        ><Plus class="size-4" />Position hinzufügen</Button
                    >
                </div>
                <div class="space-y-5 p-5">
                    <StatusAlert
                        v-if="smallBusinessRegulationEnabled"
                        type="info"
                        title="Kleinunternehmerregelung aktiv"
                    >
                        Alle Positionen werden mit 0 % Umsatzsteuer erstellt.
                        Auf PDF und XRechnung wird automatisch „{{
                            smallBusinessNotice
                        }}“ ausgegeben.
                    </StatusAlert>
                    <div
                        v-for="(item, index) in form.items"
                        :key="index"
                        class="rounded-lg border p-4"
                    >
                        <div class="mb-4 flex items-center justify-between">
                            <h3 class="font-medium">
                                Position {{ index + 1 }}
                            </h3>
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                :disabled="form.items.length === 1"
                                title="Position entfernen"
                                @click="removeItem(index)"
                                ><Trash2 class="size-4" /><span class="sr-only"
                                    >Position entfernen</span
                                ></Button
                            >
                        </div>
                        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-12">
                            <div class="space-y-2 sm:col-span-2 lg:col-span-8">
                                <Label :for="`description-${index}`"
                                    >Beschreibung *</Label
                                ><Input
                                    :id="`description-${index}`"
                                    v-model="item.description"
                                    maxlength="500"
                                    required
                                /><InputError
                                    :message="error(index, 'description')"
                                />
                            </div>
                            <div class="space-y-2 lg:col-span-2">
                                <Label :for="`quantity-${index}`">Menge *</Label
                                ><Input
                                    :id="`quantity-${index}`"
                                    v-model="item.quantity"
                                    inputmode="decimal"
                                    required
                                /><InputError
                                    :message="error(index, 'quantity')"
                                />
                            </div>
                            <div class="space-y-2 lg:col-span-2">
                                <Label :for="`unit-${index}`">Einheit *</Label
                                ><select
                                    :id="`unit-${index}`"
                                    v-model="item.unit_code"
                                    class="h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
                                >
                                    <option value="C62">Stück</option>
                                    <option value="HUR">Stunde</option>
                                    <option value="DAY">Tag</option>
                                </select>
                            </div>
                            <div class="space-y-2 lg:col-span-2">
                                <Label :for="`price-mode-${index}`"
                                    >Preisangabe *</Label
                                ><select
                                    :id="`price-mode-${index}`"
                                    v-model="item.price_mode"
                                    class="h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
                                >
                                    <option value="net">Netto</option>
                                    <option value="gross">Brutto</option>
                                </select>
                                <InputError
                                    :message="error(index, 'price_mode')"
                                />
                            </div>
                            <div class="space-y-2 lg:col-span-3">
                                <Label :for="`price-${index}`"
                                    >Einzelpreis
                                    {{
                                        item.price_mode === 'gross'
                                            ? 'brutto'
                                            : 'netto'
                                    }}
                                    *</Label
                                ><Input
                                    :id="`price-${index}`"
                                    v-model="item.unit_price"
                                    inputmode="decimal"
                                    placeholder="0,00"
                                    required
                                /><InputError
                                    :message="error(index, 'unit_price')"
                                />
                            </div>
                            <div class="space-y-2 lg:col-span-3">
                                <Label :for="`vat-${index}`"
                                    >Umsatzsteuer *</Label
                                ><select
                                    :id="`vat-${index}`"
                                    v-model="item.vat_rate"
                                    :disabled="smallBusinessRegulationEnabled"
                                    class="h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
                                >
                                    <option value="19">19 %</option>
                                    <option value="7">7 %</option>
                                    <option value="0">
                                        0 % / steuerbefreit
                                    </option>
                                </select>
                            </div>
                            <div
                                v-if="
                                    item.vat_rate === '0' &&
                                    !smallBusinessRegulationEnabled
                                "
                                class="space-y-2 sm:col-span-2 lg:col-span-6"
                            >
                                <Label :for="`reason-${index}`"
                                    >Steuerbefreiungsgrund *</Label
                                ><Input
                                    :id="`reason-${index}`"
                                    v-model="item.tax_exemption_reason"
                                    maxlength="500"
                                    required
                                /><InputError
                                    :message="
                                        error(index, 'tax_exemption_reason')
                                    "
                                />
                            </div>
                            <div class="space-y-2 lg:col-span-4">
                                <Label :for="`position-total-${index}`"
                                    >Positionssumme</Label
                                >
                                <output
                                    :id="`position-total-${index}`"
                                    class="flex h-9 items-center rounded-md bg-muted px-3 text-sm"
                                >
                                    Netto
                                    {{ formatMoney(itemValues[index].net) }} ·
                                    Brutto
                                    {{ formatMoney(itemValues[index].gross) }}
                                </output>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <section class="rounded-xl border bg-card">
                <h2 class="border-b px-5 py-4 text-sm font-semibold">
                    Zahlung
                </h2>
                <div class="grid gap-5 p-5 sm:grid-cols-2">
                    <div class="space-y-2">
                        <Label for="payment-method">Zahlungsart *</Label
                        ><select
                            id="payment-method"
                            v-model="form.payment_method"
                            class="h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
                        >
                            <option
                                value="bank_transfer"
                                :disabled="!paymentReadiness.bank_transfer"
                            >
                                Überweisung{{
                                    paymentReadiness.bank_transfer
                                        ? ''
                                        : ' (Bankdaten fehlen)'
                                }}
                            </option>
                            <option
                                value="sepa_direct_debit"
                                :disabled="!paymentReadiness.sepa_direct_debit"
                            >
                                SEPA-Lastschrift{{
                                    paymentReadiness.sepa_direct_debit
                                        ? ''
                                        : ' (nicht eingerichtet)'
                                }}
                            </option>
                            <option value="cash">Barzahlung</option>
                            <option value="card">Kartenzahlung</option>
                            <option value="other">
                                Sonstige Zahlungsart
                            </option></select
                        ><InputError :message="form.errors.payment_method" />
                    </div>
                    <StatusAlert
                        v-if="form.payment_method === 'bank_transfer'"
                        type="info"
                        >Die PDF-Rechnung enthält automatisch einen GiroCode mit
                        Betrag, IBAN und Rechnungsnummer.</StatusAlert
                    >
                    <template
                        v-if="form.payment_method === 'sepa_direct_debit'"
                    >
                        <div class="space-y-2 sm:col-span-2">
                            <Label for="finance-mandate"
                                >Unterschriebenes SEPA-Mandat *</Label
                            >
                            <SearchableDropdown
                                id="finance-mandate"
                                v-model="form.finance_mandate_id"
                                :options="mandateOptions"
                                placeholder="Mandat auswählen"
                                search-placeholder="Referenz, Name, E-Mail oder IBAN suchen"
                                empty-text="Kein verwendbares Mandat gefunden"
                                trigger-class="h-9 w-full rounded-md border border-input bg-background px-3"
                            />
                            <InputError
                                :message="form.errors.finance_mandate_id"
                            />
                            <p class="text-xs text-muted-foreground">
                                Nur unterschriebene Mandate aus dem
                                Formularbereich sind auswählbar.
                                <Link
                                    href="/formulare/sepa-mandate/anlegen"
                                    class="font-medium text-primary hover:underline"
                                    >Neues Mandat anlegen</Link
                                >
                            </p>
                        </div>
                        <div
                            v-if="selectedMandate"
                            class="rounded-lg bg-muted p-4 text-sm sm:col-span-2"
                        >
                            <p class="font-medium">
                                {{ selectedMandate.debtor_name }} ·
                                {{ selectedMandate.mandate_reference }}
                            </p>
                            <p class="mt-1 text-muted-foreground">
                                IBAN •••• {{ selectedMandate.iban.slice(-4) }} ·
                                {{
                                    selectedMandate.mandate_type === 'one_off'
                                        ? 'Einmalig'
                                        : 'Wiederkehrend'
                                }}
                                · erteilt am
                                {{
                                    new Date(
                                        selectedMandate.signed_at + 'T00:00:00',
                                    ).toLocaleDateString('de-DE')
                                }}
                            </p>
                        </div>
                    </template>
                </div>
            </section>

            <section
                class="flex flex-wrap items-center justify-between gap-4 rounded-xl border bg-card p-5"
            >
                <div>
                    <p class="text-sm text-muted-foreground">
                        Netto {{ formatMoney(totals.net) }} · Umsatzsteuer
                        {{ formatMoney(totals.tax) }}
                    </p>
                    <p class="text-2xl font-semibold">
                        Gesamt {{ formatMoney(totals.gross) }}
                    </p>
                </div>
                <Button
                    type="submit"
                    :disabled="form.processing || !clubReadiness.ready"
                    >{{
                        form.processing
                            ? 'Rechnung wird erstellt …'
                            : 'Rechnung verbindlich erstellen'
                    }}</Button
                >
            </section>
        </form>
    </div>
</template>
