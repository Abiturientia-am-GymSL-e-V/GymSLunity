<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ArrowLeft, FilePlus2, Save, Trash2 } from '@lucide/vue';
import { computed } from 'vue';
import InputError from '@/components/InputError.vue';
import SearchableDropdown from '@/components/SearchableDropdown.vue';
import StatusAlert from '@/components/StatusAlert.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { bookingStatusLabel, resourceLabel } from '@/lib/bookings';
import { formatDateTime, formatMoney } from '@/lib/format';
import type { BookingResource, ResourceBooking } from '@/types/bookings';

const props = defineProps<{
    booking: ResourceBooking;
    scope: 'occurrence' | 'series';
    series: ResourceBooking[];
    resources: BookingResource[];
    canCreateInvoice: boolean;
}>();
defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Buchungen', href: '/buchungen' },
            { title: 'Buchungsdetail' },
        ],
    },
});
const form = useForm({
    scope: props.scope,
    resource_id: String(props.booking.resource_id),
    title: props.booking.title,
    notes: props.booking.notes ?? '',
    starts_at: props.booking.starts_at,
    ends_at: props.booking.ends_at,
});
const cancelForm = useForm({ scope: props.scope });
const resourceOptions = computed(() =>
    props.resources.map((resource) => ({
        value: String(resource.id),
        label: resourceLabel(resource, props.resources),
        search: resource.name + ' ' + (resource.location ?? ''),
    })),
);
const active = computed(() =>
    ['requested', 'confirmed'].includes(props.booking.status),
);
function save() {
    form.patch('/buchungen/' + props.booking.id, { preserveScroll: true });
}
function cancel() {
    const object =
        props.scope === 'series'
            ? 'alle zukünftigen Termine dieser Serie'
            : 'diesen Buchungstermin';
    if (!window.confirm('Möchtest du ' + object + ' wirklich stornieren?'))
        return;
    cancelForm.patch('/buchungen/' + props.booking.id + '/stornieren');
}
</script>

<template>
    <Head :title="'Buchung · ' + booking.title" />
    <div class="mx-auto w-full max-w-[1200px] space-y-6 p-4 sm:p-6">
        <header>
            <Button as-child variant="ghost" size="sm">
                <Link href="/buchungen"
                    ><ArrowLeft class="size-4" />Zur Belegung</Link
                >
            </Button>
            <div class="mt-3 flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h1 class="text-2xl font-semibold tracking-tight">
                        {{ booking.title }}
                    </h1>
                    <p class="mt-1 text-sm text-muted-foreground">
                        {{ booking.resource_name }} ·
                        {{ formatDateTime(booking.starts_at) }}
                    </p>
                </div>
                <Badge variant="outline">{{
                    bookingStatusLabel(booking.status)
                }}</Badge>
            </div>
        </header>

        <nav
            v-if="booking.series_id"
            class="flex flex-wrap gap-2 border-b pb-4"
            aria-label="Buchungsumfang"
        >
            <Button
                as-child
                :variant="scope === 'occurrence' ? 'default' : 'outline'"
            >
                <Link :href="'/buchungen/' + booking.id">Einzeltermin</Link>
            </Button>
            <Button
                as-child
                :variant="scope === 'series' ? 'default' : 'outline'"
            >
                <Link :href="'/buchungen/' + booking.id + '?scope=series'"
                    >Gesamte Serie</Link
                >
            </Button>
        </nav>

        <StatusAlert v-if="!active" type="info" title="Abgeschlossene Buchung">
            Abgelehnte oder stornierte Buchungen können nicht mehr geändert
            werden.
        </StatusAlert>
        <StatusAlert
            v-if="form.hasErrors || cancelForm.hasErrors"
            type="error"
            title="Buchung nicht geändert"
            :messages="[
                ...Object.values(form.errors),
                ...Object.values(cancelForm.errors),
            ]"
        />

        <section class="rounded-xl border bg-card">
            <h2 class="border-b px-5 py-4 text-sm font-semibold">
                Buchungsdaten
            </h2>
            <form class="grid gap-5 p-5 sm:grid-cols-2" @submit.prevent="save">
                <div class="min-w-0 space-y-2 sm:col-span-2">
                    <Label for="booking-detail-resource">Ressource</Label>
                    <SearchableDropdown
                        id="booking-detail-resource"
                        :model-value="form.resource_id"
                        :options="resourceOptions"
                        :disabled="!active"
                        trigger-class="h-9 w-full rounded-md border border-input bg-background px-3"
                        aria-label="Ressource auswählen"
                        search-placeholder="Ressource suchen"
                        empty-text="Keine Ressource gefunden"
                        @update:model-value="form.resource_id = $event"
                    />
                    <InputError :message="form.errors.resource_id" />
                </div>
                <div class="min-w-0 space-y-2 sm:col-span-2">
                    <Label for="booking-detail-title">Anlass</Label>
                    <Input
                        id="booking-detail-title"
                        v-model="form.title"
                        :disabled="!active"
                        required
                    />
                    <InputError :message="form.errors.title" />
                </div>
                <div class="min-w-0 space-y-2 sm:col-span-2">
                    <Label for="booking-detail-start">Beginn</Label>
                    <Input
                        id="booking-detail-start"
                        v-model="form.starts_at"
                        type="datetime-local"
                        class="date-safe"
                        :disabled="!active"
                        required
                    />
                    <InputError :message="form.errors.starts_at" />
                </div>
                <div class="min-w-0 space-y-2 sm:col-span-2">
                    <Label for="booking-detail-end">Ende</Label>
                    <Input
                        id="booking-detail-end"
                        v-model="form.ends_at"
                        type="datetime-local"
                        class="date-safe"
                        :disabled="!active"
                        required
                    />
                    <InputError :message="form.errors.ends_at" />
                </div>
                <div class="min-w-0 space-y-2 sm:col-span-2">
                    <Label for="booking-detail-notes">Notizen</Label>
                    <Textarea
                        id="booking-detail-notes"
                        v-model="form.notes"
                        :disabled="!active"
                    />
                    <InputError :message="form.errors.notes" />
                </div>
                <div
                    class="space-y-1 text-sm text-muted-foreground sm:col-span-2"
                >
                    <p>Buchende Person: {{ booking.requester_name }}</p>
                    <p>Preis: {{ formatMoney(booking.price_cents) }}</p>
                    <p v-if="booking.created_by_name">
                        Angelegt durch: {{ booking.created_by_name }}
                    </p>
                </div>
                <div
                    v-if="active"
                    class="flex flex-wrap justify-between gap-3 sm:col-span-2"
                >
                    <Button
                        type="button"
                        variant="destructive"
                        :disabled="cancelForm.processing"
                        @click="cancel"
                    >
                        <Trash2 class="size-4" />
                        {{
                            scope === 'series'
                                ? 'Zukünftige Serie stornieren'
                                : 'Termin stornieren'
                        }}
                    </Button>
                    <Button :disabled="form.processing">
                        <Save class="size-4" />
                        {{
                            scope === 'series'
                                ? 'Serie speichern'
                                : 'Termin speichern'
                        }}
                    </Button>
                </div>
            </form>
        </section>

        <section
            v-if="scope === 'series'"
            class="overflow-hidden rounded-xl border bg-card"
        >
            <h2 class="border-b px-5 py-4 text-sm font-semibold">
                Termine der Serie
            </h2>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[650px] text-sm">
                    <thead class="bg-muted/60 text-left">
                        <tr>
                            <th class="px-4 py-3 font-semibold">Nr.</th>
                            <th class="px-4 py-3 font-semibold">Zeitraum</th>
                            <th class="px-4 py-3 font-semibold">Status</th>
                            <th class="px-4 py-3 font-semibold">Aktion</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="item in series" :key="item.id">
                            <td class="px-4 py-3">{{ item.occurrence }}</td>
                            <td class="px-4 py-3">
                                {{ formatDateTime(item.starts_at) }}–{{
                                    formatDateTime(item.ends_at)
                                }}
                            </td>
                            <td class="px-4 py-3">
                                {{ bookingStatusLabel(item.status) }}
                            </td>
                            <td class="px-4 py-3">
                                <Link
                                    :href="'/buchungen/' + item.id"
                                    class="font-medium underline"
                                    >Einzeltermin öffnen</Link
                                >
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section
            v-if="canCreateInvoice || booking.finance_invoice_id"
            class="rounded-xl border bg-card p-5"
        >
            <h2 class="font-semibold">Rechnungsstellung</h2>
            <p class="mt-1 text-sm text-muted-foreground">
                Externe kostenpflichtige Buchungen können in die
                Rechnungsverwaltung übernommen werden.
            </p>
            <Button v-if="canCreateInvoice" as-child class="mt-4">
                <Link
                    :href="
                        '/buchhaltung/rechnungen/anlegen?booking=' + booking.id
                    "
                    ><FilePlus2 class="size-4" />In Rechnung übernehmen</Link
                >
            </Button>
            <Button
                v-else-if="booking.finance_invoice_id"
                as-child
                class="mt-4"
                variant="outline"
            >
                <Link href="/buchhaltung/rechnungen"
                    >Zur Rechnungsverwaltung</Link
                >
            </Button>
        </section>
    </div>
</template>
