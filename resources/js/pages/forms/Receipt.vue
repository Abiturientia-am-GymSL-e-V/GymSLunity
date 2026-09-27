<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import StatusAlert from '@/components/StatusAlert.vue';
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
import { Textarea } from '@/components/ui/textarea';
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
        exported_at: string | null;
        cancelled_at: string | null;
        cancelled_by_name: string | null;
        cancellation_reason: string | null;
        can_cancel: boolean;
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
const cancelOpen = ref(false);
const cancelForm = useForm({ reason: '' });
function cancelReceipt() {
    cancelForm.post(
        '/formulare/quittungen/' + props.receipt.id + '/stornieren',
        {
            preserveScroll: true,
            onSuccess: () => {
                cancelOpen.value = false;
                cancelForm.reset();
            },
        },
    );
}
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
            <Button as-child variant="outline">
                <Link href="/formulare/quittungen">Zu den Quittungen</Link>
            </Button>
        </header>
        <StatusAlert
            v-if="receipt.cancelled_at"
            type="error"
            title="Quittung storniert"
        >
            Storniert von {{ receipt.cancelled_by_name }} am
            {{ new Date(receipt.cancelled_at).toLocaleString('de-DE') }}.
            Begründung: {{ receipt.cancellation_reason }}
        </StatusAlert>
        <StatusAlert
            v-else-if="receipt.exported_at"
            type="info"
            title="Quittung wurde ausgegeben"
        >
            Die Quittung wurde bereits heruntergeladen, gedruckt oder versendet
            und kann daher nicht mehr storniert werden.
        </StatusAlert>
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
        <div v-if="!receipt.cancelled_at" class="grid gap-5 md:grid-cols-2">
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
                    <StatusAlert
                        v-if="Object.keys(edition.form.errors).length"
                        type="error"
                        title="E-Mail nicht versendet"
                        :messages="Object.values(edition.form.errors)"
                    />
                    <Button
                        variant="outline"
                        :disabled="edition.form.processing"
                        >Per E-Mail versenden</Button
                    >
                </form>
            </section>
        </div>
        <section
            v-if="receipt.can_cancel"
            class="flex flex-wrap items-center justify-between gap-4 rounded-xl border border-destructive/30 bg-card p-5"
        >
            <div>
                <h2 class="font-medium">Quittung stornieren</h2>
                <p class="mt-1 text-sm text-muted-foreground">
                    Nur möglich, solange der Beleg weder exportiert noch
                    versendet wurde.
                </p>
            </div>
            <Button variant="destructive" @click="cancelOpen = true"
                >Quittung stornieren</Button
            >
        </section>
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
    <Dialog :open="cancelOpen" @update:open="cancelOpen = $event">
        <DialogContent>
            <DialogHeader>
                <DialogTitle>Quittung stornieren</DialogTitle>
                <DialogDescription
                    >Die Stornierung wird dauerhaft protokolliert. Die
                    Begründung ist erforderlich.</DialogDescription
                >
            </DialogHeader>
            <form class="space-y-4" @submit.prevent="cancelReceipt">
                <div class="space-y-2">
                    <Label for="cancellation-reason">Begründung *</Label>
                    <Textarea
                        id="cancellation-reason"
                        v-model="cancelForm.reason"
                        rows="4"
                        maxlength="1000"
                        required
                    />
                </div>
                <StatusAlert
                    v-if="Object.keys(cancelForm.errors).length"
                    type="error"
                    title="Stornierung nicht möglich"
                    :messages="Object.values(cancelForm.errors)"
                />
                <DialogFooter>
                    <Button
                        type="button"
                        variant="outline"
                        @click="cancelOpen = false"
                        >Abbrechen</Button
                    >
                    <Button
                        variant="destructive"
                        :disabled="cancelForm.processing"
                        >Verbindlich stornieren</Button
                    >
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
