<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { ArrowDownToLine, RotateCcw } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import InvoiceNav from '@/components/finance/InvoiceNav.vue';
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
import { formatDate, formatMoney } from '@/lib/format';

type Invoice = {
    id: number;
    invoice_number: string;
    recipient_name: string;
    collection_from: string;
    currency: string;
    amount_cents: number;
    mandate_reference: string;
};
type SepaExport = {
    uuid: string;
    message_id: string;
    collection_date: string;
    actor_name: string;
    created_at: string;
    transaction_count: number;
    total_cents: number;
    has_returns: number;
    reverted_at: string | null;
    reverted_by_name: string | null;
    reversal_reason: string | null;
};

const props = defineProps<{
    invoices: Invoice[];
    exports: SepaExport[];
    today: string;
    sepaReady: boolean;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Buchhaltung', href: '/buchhaltung' },
            { title: 'Rechnungswesen', href: '/buchhaltung/rechnungen' },
            { title: 'SEPA-Export' },
        ],
    },
});

const collectionDate = ref(props.today);
const selection = ref<number[]>([]);
const busy = ref(false);
const exportError = ref('');
const reverseDialogOpen = ref(false);
const selectedExport = ref<SepaExport | null>(null);
const reverseForm = useForm({ reason: '' });
const eligibleInvoices = computed(() =>
    props.invoices.filter(
        (invoice) => invoice.collection_from <= collectionDate.value,
    ),
);
const selectedInvoices = computed(() =>
    eligibleInvoices.value.filter((invoice) =>
        selection.value.includes(invoice.id),
    ),
);
const selectedTotal = computed(() =>
    selectedInvoices.value.reduce(
        (total, invoice) => total + invoice.amount_cents,
        0,
    ),
);
const allSelected = computed(
    () =>
        eligibleInvoices.value.length > 0 &&
        eligibleInvoices.value.every((invoice) =>
            selection.value.includes(invoice.id),
        ),
);

watch(collectionDate, () => {
    const eligibleIds = new Set(
        eligibleInvoices.value.map((invoice) => invoice.id),
    );
    selection.value = selection.value.filter((id) => eligibleIds.has(id));
});

const isEligible = (invoice: Invoice) =>
    invoice.collection_from <= collectionDate.value;
const toggle = (id: number, checked: boolean) => {
    selection.value = checked
        ? [...new Set([...selection.value, id])]
        : selection.value.filter((selected) => selected !== id);
};
const toggleAll = () => {
    selection.value = allSelected.value
        ? []
        : eligibleInvoices.value.map((invoice) => invoice.id);
};

async function exportSepa() {
    busy.value = true;
    exportError.value = '';
    try {
        const token = document.cookie
            .split('; ')
            .find((value) => value.startsWith('XSRF-TOKEN='))
            ?.slice('XSRF-TOKEN='.length);
        const response = await fetch('/buchhaltung/rechnungen/sepa-export', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/xml, application/json',
                'X-XSRF-TOKEN': decodeURIComponent(token || ''),
            },
            body: JSON.stringify({
                ids: selection.value,
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
                failure?.errors?.collection_date?.[0] ||
                    failure?.errors?.ids?.[0] ||
                    'Der SEPA-Export konnte nicht erstellt werden.',
            );
        }
        const objectUrl = URL.createObjectURL(await response.blob());
        const link = document.createElement('a');
        link.href = objectUrl;
        link.download = 'sepa-rechnungen.xml';
        document.body.append(link);
        link.click();
        link.remove();
        setTimeout(() => URL.revokeObjectURL(objectUrl), 1000);
        selection.value = [];
        router.reload();
    } catch (cause) {
        exportError.value =
            cause instanceof Error
                ? cause.message
                : 'Der SEPA-Export ist fehlgeschlagen.';
    } finally {
        busy.value = false;
    }
}
const openReverse = (item: SepaExport) => {
    selectedExport.value = item;
    reverseForm.reset();
    reverseForm.clearErrors();
    reverseDialogOpen.value = true;
};
const reverseExport = () => {
    if (!selectedExport.value) return;
    reverseForm.post(
        `/buchhaltung/rechnungen/sepa-export/${selectedExport.value.uuid}/rueckgaengig`,
        {
            preserveScroll: true,
            onSuccess: () => {
                reverseDialogOpen.value = false;
                selectedExport.value = null;
                reverseForm.reset();
            },
        },
    );
};
</script>

<template>
    <Head title="SEPA-Export · Rechnungswesen" />
    <div class="mx-auto w-full max-w-[1200px] space-y-6 p-4 sm:p-6">
        <header>
            <h1 class="text-2xl font-semibold tracking-tight">
                Rechnungswesen
            </h1>
            <p class="mt-1 text-sm text-muted-foreground">
                Lastschriften aus ausgestellten Rechnungen als PAIN.008-Datei
                exportieren.
            </p>
        </header>
        <InvoiceNav active="sepa" />

        <StatusAlert
            v-if="!sepaReady"
            type="warning"
            title="SEPA-Konfiguration unvollständig"
        >
            Für den Export werden Vereinsname, Vereins-IBAN und
            SEPA-Gläubiger-ID benötigt.
        </StatusAlert>
        <StatusAlert
            v-if="exportError"
            type="error"
            title="Export fehlgeschlagen"
        >
            {{ exportError }}
        </StatusAlert>

        <section class="overflow-hidden rounded-xl border bg-card">
            <div
                class="flex flex-wrap items-end justify-between gap-4 border-b p-5"
            >
                <div>
                    <h2 class="font-semibold">SEPA-Lastschrift-Datei</h2>
                    <p class="mt-1 text-sm text-muted-foreground">
                        Exportierte Rechnungen werden als bezahlt markiert.
                    </p>
                </div>
                <div class="flex flex-wrap items-end gap-3">
                    <div class="space-y-2">
                        <Label for="collection-date">Einzugsdatum</Label>
                        <Input
                            id="collection-date"
                            v-model="collectionDate"
                            type="date"
                            :min="today"
                            class="date-safe w-48"
                        />
                    </div>
                    <Button
                        :disabled="busy || !selection.length || !sepaReady"
                        @click="exportSepa"
                    >
                        <Spinner v-if="busy" />
                        <ArrowDownToLine v-else class="size-4" />
                        Exportieren
                    </Button>
                </div>
                <p class="basis-full text-sm text-muted-foreground">
                    Es werden nur Rechnungen berücksichtigt, deren „Einzug
                    ab“-Datum am oder vor dem gewählten Einzugsdatum liegt.
                </p>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full min-w-[760px] text-sm">
                    <thead class="bg-muted/50 text-left">
                        <tr>
                            <th class="w-12 p-3">
                                <Checkbox
                                    :model-value="allSelected"
                                    aria-label="Alle verfügbaren Rechnungen auswählen"
                                    @update:model-value="toggleAll"
                                />
                            </th>
                            <th class="p-3 font-medium">Rechnung</th>
                            <th class="p-3 font-medium">Empfänger</th>
                            <th class="p-3 font-medium">Einzug ab</th>
                            <th class="p-3 font-medium">Mandat</th>
                            <th class="p-3 text-right font-medium">Einzug</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr
                            v-for="invoice in invoices"
                            :key="invoice.id"
                            :class="
                                !isEligible(invoice) && 'text-muted-foreground'
                            "
                        >
                            <td class="p-3">
                                <Checkbox
                                    :model-value="
                                        selection.includes(invoice.id)
                                    "
                                    :disabled="!isEligible(invoice)"
                                    :aria-label="`${invoice.invoice_number} auswählen`"
                                    @update:model-value="
                                        toggle(invoice.id, Boolean($event))
                                    "
                                />
                            </td>
                            <td class="p-3 font-medium">
                                {{ invoice.invoice_number }}
                            </td>
                            <td class="p-3">{{ invoice.recipient_name }}</td>
                            <td class="p-3">
                                {{ formatDate(invoice.collection_from) }}
                                <span
                                    v-if="!isEligible(invoice)"
                                    class="block text-xs"
                                >
                                    Noch nicht exportierbar
                                </span>
                            </td>
                            <td class="p-3">{{ invoice.mandate_reference }}</td>
                            <td class="p-3 text-right font-medium">
                                {{
                                    formatMoney(
                                        invoice.amount_cents,
                                        invoice.currency,
                                    )
                                }}
                            </td>
                        </tr>
                        <tr v-if="!invoices.length">
                            <td
                                colspan="6"
                                class="p-10 text-center text-muted-foreground"
                            >
                                Keine offenen SEPA-Rechnungen vorhanden.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div
                class="flex items-center justify-between border-t bg-muted/20 px-5 py-4"
            >
                <span class="text-sm text-muted-foreground">
                    {{ selectedInvoices.length }} Rechnung(en) ausgewählt
                </span>
                <span class="font-semibold">{{
                    formatMoney(selectedTotal)
                }}</span>
            </div>
        </section>

        <section class="overflow-hidden rounded-xl border bg-card">
            <div class="border-b px-5 py-4">
                <h2 class="font-semibold">Letzte Exporte</h2>
                <p class="mt-1 text-sm text-muted-foreground">
                    Nicht eingereichte oder vollständig abgelehnte Dateien
                    können zurückgesetzt werden. Die enthaltenen Rechnungen
                    werden dann wieder offen und exportierbar.
                </p>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[760px] text-sm">
                    <thead class="bg-muted/50 text-left">
                        <tr>
                            <th class="p-3 font-medium">Export</th>
                            <th class="p-3 font-medium">Einzug</th>
                            <th class="p-3 font-medium">Rechnungen</th>
                            <th class="p-3 text-right font-medium">Summe</th>
                            <th class="p-3 font-medium">Status</th>
                            <th class="p-3 text-right font-medium">Aktion</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="item in exports" :key="item.uuid">
                            <td class="p-3">
                                <span class="block font-medium">{{
                                    item.message_id
                                }}</span>
                                <span class="text-xs text-muted-foreground">
                                    {{
                                        formatDate(item.created_at.slice(0, 10))
                                    }}
                                    ·
                                    {{ item.actor_name }}
                                </span>
                            </td>
                            <td class="p-3">
                                {{ formatDate(item.collection_date) }}
                            </td>
                            <td class="p-3">{{ item.transaction_count }}</td>
                            <td class="p-3 text-right font-medium">
                                {{ formatMoney(Number(item.total_cents)) }}
                            </td>
                            <td class="p-3">
                                <Badge
                                    variant="outline"
                                    :class="
                                        item.reverted_at
                                            ? 'border-muted-foreground/30 text-muted-foreground'
                                            : 'border-success/30 bg-success/10 text-success'
                                    "
                                >
                                    {{
                                        item.reverted_at
                                            ? 'Zurückgesetzt'
                                            : 'Erstellt'
                                    }}
                                </Badge>
                                <span
                                    v-if="item.reversal_reason"
                                    class="mt-1 block max-w-72 text-xs text-muted-foreground"
                                >
                                    {{ item.reversal_reason }}
                                </span>
                            </td>
                            <td class="p-3 text-right">
                                <Button
                                    v-if="
                                        !item.reverted_at &&
                                        !Number(item.has_returns)
                                    "
                                    size="sm"
                                    variant="outline"
                                    @click="openReverse(item)"
                                >
                                    <RotateCcw class="size-4" />
                                    Zurücksetzen
                                </Button>
                                <span
                                    v-else-if="!item.reverted_at"
                                    class="text-xs text-muted-foreground"
                                >
                                    Enthält Rücklastschrift
                                </span>
                            </td>
                        </tr>
                        <tr v-if="!exports.length">
                            <td
                                colspan="6"
                                class="p-8 text-center text-muted-foreground"
                            >
                                Noch keine Exporte vorhanden.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <Dialog v-model:open="reverseDialogOpen">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>SEPA-Export zurücksetzen?</DialogTitle>
                    <DialogDescription>
                        Nur verwenden, wenn die Datei nicht bei der Bank
                        eingereicht oder vollständig abgelehnt wurde. Bereits
                        eingezogene Einzelbuchungen werden als Rücklastschrift
                        verarbeitet.
                    </DialogDescription>
                </DialogHeader>
                <div class="space-y-2">
                    <Label for="reverse-reason">Begründung *</Label>
                    <Textarea
                        id="reverse-reason"
                        v-model="reverseForm.reason"
                        maxlength="1000"
                        rows="4"
                    />
                    <p
                        v-if="reverseForm.errors.reason"
                        class="text-sm text-destructive"
                    >
                        {{ reverseForm.errors.reason }}
                    </p>
                </div>
                <DialogFooter>
                    <Button
                        variant="outline"
                        @click="reverseDialogOpen = false"
                    >
                        Abbrechen
                    </Button>
                    <Button
                        variant="destructive"
                        :disabled="
                            !reverseForm.reason.trim() || reverseForm.processing
                        "
                        @click="reverseExport"
                    >
                        <Spinner v-if="reverseForm.processing" />
                        Export zurücksetzen
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
