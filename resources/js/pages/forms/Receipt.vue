<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
const props = defineProps<{
    receipt: {
        id: number;
        receipt_number: string;
        amount_cents: number;
        net_cents: number;
        vat_cents: number;
        vat_rate: number;
        vat_reason: string | null;
        currency: string;
        amount_words: string;
        payer: string;
        payee: string;
        purpose: string;
        receipt_date: string;
        signer_name: string;
        created_by_name: string;
        created_at: string;
        payer_email?: string;
        payee_email?: string;
    };
    deliveries: Array<{
        edition: string;
        recipient: string;
        sent_by_name: string;
        created_at: string;
    }>;
}>();
defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Formulare', href: '/formulare' },
            { title: 'Quittungen', href: '/formulare/quittungen' },
            { title: 'Ausgestellte Quittung' },
        ],
    },
});
const original = useForm({
    edition: 'original',
    recipient: props.receipt.payer_email ?? '',
});
const copy = useForm({
    edition: 'copy',
    recipient: props.receipt.payee_email ?? '',
});
const editions = [
    {
        key: 'original',
        label: 'Original für den Zahlungsgeber',
        form: original,
    },
    { key: 'copy', label: 'Kopie für den Zahlungsempfänger', form: copy },
];
const money = (value: number) =>
    (value / 100).toLocaleString('de-DE', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });
</script>
<template>
    <Head :title="`Quittung ${receipt.receipt_number}`" />
    <div class="mx-auto w-full max-w-[1200px] space-y-6 p-4 sm:p-6">
        <header class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h1 class="text-2xl font-semibold tracking-tight">
                    Quittung {{ receipt.receipt_number }}
                </h1>
                <p class="mt-1 text-sm text-muted-foreground">
                    Ausgestellten Beleg prüfen, herunterladen, drucken oder
                    versenden.
                </p>
            </div>
            <Link href="/formulare/quittungen" class="text-sm underline"
                >Zu den Quittungen</Link
            >
        </header>
        <section class="space-y-4 rounded-xl border bg-card p-5">
            <p class="text-2xl font-semibold">
                {{ money(receipt.amount_cents) }} {{ receipt.currency }}
            </p>
            <p>{{ receipt.amount_words }}</p>
            <p class="text-sm">
                Netto {{ money(receipt.net_cents) }} · Umsatzsteuer
                {{ receipt.vat_rate }} %: {{ money(receipt.vat_cents) }}
                {{ receipt.currency
                }}<span v-if="receipt.vat_reason">
                    · {{ receipt.vat_reason }}</span
                >
            </p>
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <h2 class="font-medium">Zahlung von</h2>
                    <p class="whitespace-pre-wrap">{{ receipt.payer }}</p>
                </div>
                <div>
                    <h2 class="font-medium">Zahlung an</h2>
                    <p class="whitespace-pre-wrap">{{ receipt.payee }}</p>
                </div>
            </div>
            <div>
                <h2 class="font-medium">Für / Zahlungsgrund</h2>
                <p class="whitespace-pre-wrap">{{ receipt.purpose }}</p>
            </div>
            <p class="text-sm text-muted-foreground">
                Belegdatum:
                {{
                    new Date(
                        receipt.receipt_date + 'T00:00:00',
                    ).toLocaleDateString('de-DE')
                }}
                · Unterschrieben von {{ receipt.signer_name }}<br />Ausgestellt
                von {{ receipt.created_by_name }} am {{ receipt.created_at }}
            </p>
        </section>
        <div class="grid gap-5 md:grid-cols-2">
            <section
                v-for="edition in editions"
                :key="edition.key"
                class="space-y-4 rounded-xl border bg-card p-5"
            >
                <h2 class="text-lg font-medium">{{ edition.label }}</h2>
                <div class="flex flex-wrap gap-3">
                    <Button as-child
                        ><a
                            :href="`/formulare/quittungen/${receipt.id}/pdf/${edition.key}`"
                            >PDF herunterladen</a
                        ></Button
                    ><Button as-child variant="outline"
                        ><a
                            :href="`/formulare/quittungen/${receipt.id}/pdf/${edition.key}?inline=1`"
                            target="_blank"
                            rel="noopener"
                            >Ansehen / Drucken</a
                        ></Button
                    >
                </div>
                <form
                    class="space-y-3 border-t pt-4"
                    @submit.prevent="
                        edition.form.post(
                            `/formulare/quittungen/${receipt.id}/versenden`,
                            { preserveScroll: true },
                        )
                    "
                >
                    <div class="space-y-2">
                        <Label :for="`receipt-recipient-${edition.key}`"
                            >Empfängeradresse</Label
                        ><Input
                            :id="`receipt-recipient-${edition.key}`"
                            v-model="edition.form.recipient"
                            type="email"
                            required
                        />
                    </div>
                    <p
                        v-for="error in edition.form.errors"
                        :key="error"
                        role="alert"
                        class="text-sm text-destructive"
                    >
                        {{ error }}
                    </p>
                    <Button
                        variant="outline"
                        :disabled="edition.form.processing"
                        >Per E-Mail versenden</Button
                    >
                </form>
            </section>
        </div>
        <section class="space-y-3">
            <h2 class="text-lg font-medium">Versandprotokoll</h2>
            <p v-if="!deliveries.length" class="text-sm text-muted-foreground">
                Noch kein Versand protokolliert.
            </p>
            <p
                v-for="(delivery, index) in deliveries"
                :key="index"
                class="rounded-md border p-3 text-sm"
            >
                {{ delivery.edition === 'original' ? 'Original' : 'Kopie' }} an
                {{ delivery.recipient }} · {{ delivery.sent_by_name }} ·
                {{ new Date(delivery.created_at).toLocaleString('de-DE') }}
            </p>
        </section>
    </div>
</template>
