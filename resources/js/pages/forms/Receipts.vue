<script setup lang="ts">
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { List, ReceiptText } from '@lucide/vue';
import { computed, ref } from 'vue';
import SignaturePad from '@/components/SignaturePad.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
type Row = {
    id: number;
    receipt_number: string;
    receipt_date: string;
    amount_cents: number;
    currency: string;
    payer: string;
    payee: string;
    purpose: string;
};
const props = defineProps<{
    activeTab: 'create' | 'list';
    receipts: {
        data: Row[];
        total: number;
        next_page_url: string | null;
        prev_page_url: string | null;
    };
    search: string;
    creationKey: string;
    club: Record<string, string | null>;
    hasProfileSignature: boolean;
    today: string;
}>();
defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Formulare', href: '/formulare' },
            { title: 'Quittungen', href: '/formulare/quittungen' },
        ],
    },
});
const search = ref(props.search);
const signatureKey = ref(0);
const page = usePage();
const form = useForm({
    creation_key: props.creationKey,
    receipt_number: '',
    receipt_date: props.today,
    amount: '',
    currency: 'EUR',
    vat_rate: '19',
    vat_reason: '',
    payer_source: 'other',
    payee_source: 'club',
    payer: '',
    payee: '',
    payer_email: '',
    payee_email: props.club.email ?? '',
    purpose: '',
    signer_name: page.props.auth.user?.name ?? '',
    signature_method: 'digital' as 'digital' | 'profile' | 'drawn',
    signature_data: null as string | null,
    confirmed: false,
});
const clubAddress = [
    props.club.name,
    props.club.street,
    [props.club.postal_code, props.club.city].filter(Boolean).join(' '),
]
    .filter(Boolean)
    .join('\n');
const gross = computed(() => {
    const value = form.amount.replace(',', '.');
    return /^\d{1,9}(\.\d{1,2})?$/.test(value)
        ? Math.round(Number(value) * 100)
        : 0;
});
const net = computed(() =>
    Math.round((gross.value * 100) / (100 + Number(form.vat_rate))),
);
const money = (value: number) =>
    (value / 100).toLocaleString('de-DE', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });
function reset() {
    form.reset();
    form.clearErrors();
    form.creation_key = crypto.randomUUID();
    signatureKey.value++;
}
</script>
<template>
    <Head title="Quittung" />
    <div class="mx-auto w-full max-w-[1200px] space-y-6 p-4 sm:p-6">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight">Quittung</h1>
            <p class="mt-1 text-sm text-muted-foreground">
                Zahlungen bestätigen und ausgestellte Belege wiederfinden.
            </p>
        </div>
        <nav class="flex flex-wrap gap-2 border-b pb-4" aria-label="Quittungen">
            <Link
                href="/formulare/quittungen"
                :aria-current="activeTab === 'create' ? 'page' : undefined"
                prefetch
                class="flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-medium transition-colors hover:bg-muted"
                :class="
                    activeTab === 'create'
                        ? 'bg-muted text-foreground'
                        : 'text-muted-foreground'
                "
            >
                <ReceiptText class="size-4" />Quittung erstellen</Link
            ><Link
                href="/formulare/quittungen/archiv"
                :aria-current="activeTab === 'list' ? 'page' : undefined"
                prefetch
                class="flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-medium transition-colors hover:bg-muted"
                :class="
                    activeTab === 'list'
                        ? 'bg-muted text-foreground'
                        : 'text-muted-foreground'
                "
            >
                <List class="size-4" />Quittungen anzeigen ({{
                    receipts.total
                }})
            </Link>
        </nav>
        <form
            v-if="activeTab === 'create'"
            class="space-y-6"
            @submit.prevent="form.post('/formulare/quittungen')"
        >
            <section class="rounded-xl border bg-card">
                <h2 class="border-b px-5 py-4 text-sm font-semibold">
                    Betrag & Beleg
                </h2>
                <div class="grid gap-5 p-5 sm:grid-cols-2 lg:grid-cols-3">
                    <label class="space-y-2"
                        ><span class="block text-sm font-medium"
                            >Quittungsnummer (optional)</span
                        ><Input
                            v-model="form.receipt_number"
                            maxlength="40"
                            placeholder="Wird automatisch vergeben"
                        /><span class="text-xs text-muted-foreground"
                            >Automatisch: Q-Jahr-laufende Nummer</span
                        ></label
                    ><label class="space-y-2"
                        ><span class="block text-sm font-medium">Datum *</span
                        ><Input
                            v-model="form.receipt_date"
                            type="date"
                            :max="today"
                            required /></label
                    ><label class="space-y-2"
                        ><span class="block text-sm font-medium"
                            >Betrag brutto *</span
                        ><Input
                            v-model="form.amount"
                            inputmode="decimal"
                            placeholder="0,00"
                            required /></label
                    ><label class="space-y-2"
                        ><span class="block text-sm font-medium"
                            >Währung (ISO-Code) *</span
                        ><Input
                            v-model="form.currency"
                            list="currencies"
                            pattern="[A-Z]{3}"
                            maxlength="3"
                            required
                        /><datalist id="currencies">
                            <option>EUR</option>
                            <option>CHF</option>
                            <option>USD</option>
                            <option>GBP</option>
                        </datalist></label
                    ><label class="space-y-2"
                        ><span class="block text-sm font-medium"
                            >Umsatzsteuersatz *</span
                        ><select
                            v-model="form.vat_rate"
                            class="h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
                        >
                            <option value="19">19 %</option>
                            <option value="7">7 %</option>
                            <option value="0">0 %</option>
                        </select></label
                    >
                    <div class="space-y-2">
                        <span class="block text-sm font-medium"
                            >Berechnete Beträge</span
                        >
                        <p
                            class="flex h-9 items-center rounded-md bg-muted px-3 text-sm"
                        >
                            Netto {{ money(net) }} · USt.
                            {{ money(gross - net) }} {{ form.currency }}
                        </p>
                    </div>
                    <label
                        v-if="form.vat_rate !== '19'"
                        class="space-y-2 sm:col-span-2 lg:col-span-3"
                        ><span class="block text-sm font-medium"
                            >Grund für 0 % / ermäßigten Steuersatz *</span
                        ><Input
                            v-model="form.vat_reason"
                            maxlength="500"
                            required
                    /></label>
                    <p
                        class="text-xs text-muted-foreground sm:col-span-2 lg:col-span-3"
                    >
                        Steuersatz und Begründung passend zur Zahlung angeben.
                        Der Betrag in Worten wird automatisch auf dem Beleg
                        ergänzt. Beträge werden mit zwei Nachkommastellen
                        erfasst.
                    </p>
                </div>
            </section>
            <section class="rounded-xl border bg-card">
                <h2 class="border-b px-5 py-4 text-sm font-semibold">
                    Zahlungsgeber & Zahlungsempfänger
                </h2>
                <div class="grid gap-5 p-5 sm:grid-cols-2">
                    <div class="space-y-5">
                        <label class="block space-y-2"
                            ><span class="block text-sm font-medium"
                                >Zahlung von *</span
                            ><select
                                v-model="form.payer_source"
                                class="h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
                                @change="
                                    form.payer_email =
                                        form.payer_source === 'club'
                                            ? (club.email ?? '')
                                            : ''
                                "
                            >
                                <option value="other">
                                    Andere Person / Firma
                                </option>
                                <option value="club">Verein</option>
                            </select></label
                        ><label
                            v-if="form.payer_source === 'other'"
                            class="block space-y-2"
                            ><span class="block text-sm font-medium"
                                >Name und Adresse *</span
                            ><Textarea
                                v-model="form.payer"
                                rows="3"
                                maxlength="1000"
                                required
                            />
                        </label>
                        <p
                            v-else
                            class="rounded-md bg-muted p-3 whitespace-pre-line"
                        >
                            {{ clubAddress }}
                        </p>
                        <label class="block space-y-2"
                            ><span class="block text-sm font-medium"
                                >E-Mail Zahlungsgeber (optional)</span
                            ><Input v-model="form.payer_email" type="email"
                        /></label>
                    </div>
                    <div class="space-y-5">
                        <label class="block space-y-2"
                            ><span class="block text-sm font-medium"
                                >Zahlung an *</span
                            ><select
                                v-model="form.payee_source"
                                class="h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
                                @change="
                                    form.payee_email =
                                        form.payee_source === 'club'
                                            ? (club.email ?? '')
                                            : ''
                                "
                            >
                                <option value="club">Verein</option>
                                <option value="other">
                                    Andere Person / Firma
                                </option>
                            </select></label
                        ><label
                            v-if="form.payee_source === 'other'"
                            class="block space-y-2"
                            ><span class="block text-sm font-medium"
                                >Name und Adresse *</span
                            ><Textarea
                                v-model="form.payee"
                                rows="3"
                                maxlength="1000"
                                required
                            />
                        </label>
                        <p
                            v-else
                            class="rounded-md bg-muted p-3 whitespace-pre-line"
                        >
                            {{ clubAddress }}
                        </p>
                        <label class="block space-y-2"
                            ><span class="block text-sm font-medium"
                                >E-Mail Zahlungsempfänger (optional)</span
                            ><Input v-model="form.payee_email" type="email"
                        /></label>
                    </div>
                    <label class="block space-y-2 sm:col-span-2"
                        ><span class="block text-sm font-medium"
                            >Für / Zahlungsgrund *</span
                        ><Textarea
                            v-model="form.purpose"
                            required
                            rows="2"
                            maxlength="1000"
                        />
                    </label>
                </div>
            </section>
            <section class="rounded-xl border bg-card">
                <h2 class="border-b px-5 py-4 text-sm font-semibold">
                    Empfang bestätigen
                </h2>
                <div class="space-y-5 p-5">
                    <label class="block space-y-2"
                        ><span class="block text-sm font-medium"
                            >Name der unterschreibenden Person *</span
                        ><Input
                            v-model="form.signer_name"
                            maxlength="255"
                            required
                    /></label>
                    <fieldset class="space-y-3">
                        <legend class="text-sm font-medium">
                            Art der Unterzeichnung
                        </legend>
                        <label
                            class="flex cursor-pointer gap-3 rounded-lg border p-3 has-checked:border-primary has-checked:bg-muted/50"
                        >
                            <input
                                v-model="form.signature_method"
                                type="radio"
                                value="digital"
                                class="mt-1"
                            />
                            <span
                                ><span class="block text-sm font-medium"
                                    >Digital unterzeichnen</span
                                ><span
                                    class="block text-xs text-muted-foreground"
                                    >Mit deinem angemeldeten Benutzerkonto, Name
                                    und Zeitstempel unterzeichnen.</span
                                ></span
                            >
                        </label>
                        <label
                            class="flex cursor-pointer gap-3 rounded-lg border p-3 has-checked:border-primary has-checked:bg-muted/50"
                        >
                            <input
                                v-model="form.signature_method"
                                type="radio"
                                value="profile"
                                class="mt-1"
                                :disabled="!hasProfileSignature"
                            />
                            <span
                                ><span class="block text-sm font-medium"
                                    >Unterschrift aus dem Profil
                                    (Faksimile)</span
                                ><span
                                    class="block text-xs text-muted-foreground"
                                    ><template v-if="hasProfileSignature"
                                        >Die hinterlegte Unterschriftsgrafik in
                                        Original und Kopie einsetzen.</template
                                    ><template v-else
                                        >Noch keine Unterschrift hinterlegt.
                                        <Link
                                            href="/settings/profile"
                                            class="font-medium text-primary hover:underline"
                                            >Jetzt im Profil hochladen</Link
                                        >.</template
                                    ></span
                                ></span
                            >
                        </label>
                        <label
                            class="flex cursor-pointer gap-3 rounded-lg border p-3 has-checked:border-primary has-checked:bg-muted/50"
                        >
                            <input
                                v-model="form.signature_method"
                                type="radio"
                                value="drawn"
                                class="mt-1"
                            />
                            <span
                                ><span class="block text-sm font-medium"
                                    >Mit Maus oder Touch unterschreiben</span
                                ><span
                                    class="block text-xs text-muted-foreground"
                                    >Unterschrift direkt für diese Quittung
                                    zeichnen.</span
                                ></span
                            >
                        </label>
                    </fieldset>
                    <SignaturePad
                        v-if="form.signature_method === 'drawn'"
                        :key="signatureKey"
                        v-model="form.signature_data"
                    />
                    <label class="flex items-start gap-3"
                        ><input
                            v-model="form.confirmed"
                            type="checkbox"
                            required
                            class="mt-1"
                        /><span
                            >Der genannte Betrag wurde erhalten. Ich bestätige
                            die Angaben und möchte die Quittung verbindlich
                            ausstellen.</span
                        ></label
                    >
                    <p class="text-sm text-muted-foreground">
                        Original und Kopie werden unverändert gespeichert.
                        Anschließend kannst du sie herunterladen, drucken oder
                        per E-Mail versenden. Es erfolgt keine automatische
                        Buchung auf einem Beitragskonto.
                    </p>
                </div>
            </section>
            <div
                v-if="Object.keys(form.errors).length"
                role="alert"
                class="space-y-1 rounded-md border border-destructive p-3"
            >
                <p
                    v-for="error in form.errors"
                    :key="error"
                    class="text-sm text-destructive"
                >
                    {{ error }}
                </p>
            </div>
            <div class="flex gap-3">
                <Button
                    :disabled="
                        form.processing ||
                        (form.signature_method === 'drawn' &&
                            !form.signature_data)
                    "
                    >Quittung ausstellen</Button
                ><Button
                    type="button"
                    variant="outline"
                    :disabled="form.processing"
                    @click="reset"
                    >Zurücksetzen</Button
                >
            </div>
        </form>
        <section v-else class="space-y-4">
            <form
                class="flex gap-3"
                @submit.prevent="
                    router.get(
                        '/formulare/quittungen/archiv',
                        { search },
                        { preserveState: true },
                    )
                "
            >
                <Input
                    v-model="search"
                    aria-label="Quittungen suchen"
                    placeholder="Nummer, Name oder Zahlungsgrund"
                /><Button variant="outline">Suchen</Button>
            </form>
            <p v-if="!receipts.data.length" class="text-muted-foreground">
                Keine Quittungen gefunden.
            </p>
            <article
                v-for="receipt in receipts.data"
                :key="receipt.id"
                class="flex flex-wrap items-center justify-between gap-4 rounded-xl border bg-card p-5"
            >
                <div class="space-y-1">
                    <Link
                        :href="`/formulare/quittungen/${receipt.id}`"
                        class="font-semibold underline"
                        >{{ receipt.receipt_number }}</Link
                    >
                    <p class="text-sm">
                        {{
                            new Date(
                                receipt.receipt_date + 'T00:00:00',
                            ).toLocaleDateString('de-DE')
                        }}
                        · {{ money(receipt.amount_cents) }}
                        {{ receipt.currency }}
                    </p>
                    <p class="line-clamp-2 text-sm">
                        {{ receipt.payer }} → {{ receipt.payee }}
                    </p>
                    <p class="line-clamp-2 text-sm text-muted-foreground">
                        {{ receipt.purpose }}
                    </p>
                </div>
                <Button as-child variant="outline"
                    ><Link :href="`/formulare/quittungen/${receipt.id}`"
                        >Öffnen & herunterladen</Link
                    ></Button
                >
            </article>
            <div class="flex gap-4">
                <Link
                    v-if="receipts.prev_page_url"
                    :href="receipts.prev_page_url"
                    preserve-state
                    >Zurück</Link
                ><Link
                    v-if="receipts.next_page_url"
                    :href="receipts.next_page_url"
                    preserve-state
                    >Weiter</Link
                >
            </div>
        </section>
    </div>
</template>
