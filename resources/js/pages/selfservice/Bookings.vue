<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ArrowLeft, CalendarRange, RotateCcw, X } from '@lucide/vue';
import { computed } from 'vue';
import InputError from '@/components/InputError.vue';
import SearchableDropdown from '@/components/SearchableDropdown.vue';
import Frame from '@/components/selfservice/Frame.vue';
import StatusAlert from '@/components/StatusAlert.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';

type Resource = {
    id: number;
    name: string;
    description: string | null;
    location: string | null;
    price_mode: 'free' | 'once' | 'hour' | 'day';
    price_cents: number;
    automatic: boolean;
};
type Booking = {
    id: number;
    resource_name: string;
    title: string;
    status: 'requested' | 'confirmed' | 'cancelled' | 'rejected';
    starts_at: string;
    ends_at: string;
    price_cents: number;
    series_id: string | null;
    can_cancel: boolean;
};
const props = defineProps<{
    member: { first_name: string; membership_type: string };
    minimumDateTime: string;
    resources: Resource[];
    bookings: Booking[];
}>();
const form = useForm({
    resource_id: '',
    title: '',
    notes: '',
    starts_at: '',
    ends_at: '',
    recurrence: 'none' as 'none' | 'weekly' | 'monthly',
    occurrences: 1,
});
const cancelForm = useForm({});
const options = computed(() =>
    props.resources.map((resource) => ({
        value: String(resource.id),
        label: resource.name,
        suffix: resource.location ?? undefined,
        search: `${resource.name} ${resource.location ?? ''}`,
    })),
);
const selected = computed(() =>
    props.resources.find(
        (resource) => String(resource.id) === form.resource_id,
    ),
);
const activeBookings = computed(() =>
    props.bookings.filter((booking) =>
        ['requested', 'confirmed'].includes(booking.status),
    ),
);
const pastBookings = computed(() =>
    props.bookings.filter(
        (booking) => !['requested', 'confirmed'].includes(booking.status),
    ),
);
const labels = {
    requested: 'Angefragt',
    confirmed: 'Bestätigt',
    cancelled: 'Storniert',
    rejected: 'Abgelehnt',
};
function money(cents: number) {
    return new Intl.NumberFormat('de-DE', {
        style: 'currency',
        currency: 'EUR',
    }).format(cents / 100);
}
function dateTime(value: string) {
    return new Intl.DateTimeFormat('de-DE', {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(new Date(value));
}
function price(resource: Resource) {
    if (resource.price_mode === 'free') return 'Kostenlos';
    const suffix = {
        once: 'einmalig',
        hour: 'je angefangene Stunde',
        day: 'je angefangenen Tag',
    }[resource.price_mode];
    return `${money(resource.price_cents)} ${suffix}`;
}
function submit() {
    form.post('/selfservice/buchungen', {
        preserveScroll: true,
        onSuccess: () => form.reset(),
    });
}
function cancel(booking: Booking) {
    if (!window.confirm('Diesen einzelnen Buchungstermin wirklich stornieren?'))
        return;
    cancelForm.delete(`/selfservice/buchungen/${booking.id}`, {
        preserveScroll: true,
    });
}
</script>

<template>
    <Head title="Meine Buchungen" />
    <Frame signed-in>
        <header>
            <Button as-child variant="ghost" size="sm">
                <Link href="/selfservice"
                    ><ArrowLeft class="size-4" />Zurück zum
                    Mitgliederbereich</Link
                >
            </Button>
            <h1 class="mt-3 text-3xl font-semibold">Ressourcen buchen</h1>
            <p class="mt-1 text-muted-foreground">
                {{
                    $address(
                        'Verfügbare Ressourcen für deine',
                        'Verfügbare Ressourcen für Ihre',
                    )
                }}
                Mitgliedschaft „{{ member.membership_type }}“.
            </p>
        </header>

        <StatusAlert
            v-if="!resources.length"
            type="info"
            title="Keine buchbaren Ressourcen"
        >
            {{ $address('Für deine', 'Für Ihre') }} Mitgliedschaft sind derzeit
            keine Ressourcen freigeschaltet.
        </StatusAlert>

        <form
            v-else
            class="space-y-5 rounded-xl border bg-card p-5"
            @submit.prevent="submit"
        >
            <div class="flex items-center gap-2">
                <CalendarRange class="size-5 text-muted-foreground" />
                <h2 class="text-xl font-medium">Neue Buchung</h2>
            </div>
            <div class="grid gap-5 sm:grid-cols-2">
                <div class="min-w-0 space-y-2 sm:col-span-2">
                    <Label for="portal-booking-resource">Ressource *</Label>
                    <SearchableDropdown
                        id="portal-booking-resource"
                        :model-value="form.resource_id"
                        :options="options"
                        aria-label="Ressource auswählen"
                        search-placeholder="Ressource oder Ort suchen"
                        empty-text="Keine Ressource gefunden"
                        trigger-class="h-9 w-full rounded-md border border-input bg-background px-3"
                        @update:model-value="form.resource_id = $event"
                    />
                    <InputError :message="form.errors.resource_id" />
                    <div
                        v-if="selected"
                        class="rounded-lg border bg-muted/20 p-3 text-sm"
                    >
                        <p v-if="selected.description">
                            {{ selected.description }}
                        </p>
                        <p class="text-muted-foreground">
                            {{ selected.location || 'Ohne Ortsangabe' }} ·
                            {{ price(selected) }} ·
                            {{
                                selected.automatic
                                    ? 'Wird automatisch bestätigt'
                                    : 'Bestätigung durch den Vorstand erforderlich'
                            }}
                        </p>
                    </div>
                </div>
                <div class="min-w-0 space-y-2 sm:col-span-2">
                    <Label for="portal-booking-title">Anlass *</Label>
                    <Input
                        id="portal-booking-title"
                        v-model="form.title"
                        required
                    />
                    <InputError :message="form.errors.title" />
                </div>
                <div class="min-w-0 space-y-2">
                    <Label for="portal-booking-start">Beginn *</Label>
                    <Input
                        id="portal-booking-start"
                        v-model="form.starts_at"
                        type="datetime-local"
                        :min="minimumDateTime"
                        required
                    />
                    <InputError :message="form.errors.starts_at" />
                </div>
                <div class="min-w-0 space-y-2">
                    <Label for="portal-booking-end">Ende *</Label>
                    <Input
                        id="portal-booking-end"
                        v-model="form.ends_at"
                        type="datetime-local"
                        :min="form.starts_at || minimumDateTime"
                        required
                    />
                    <InputError :message="form.errors.ends_at" />
                </div>
                <div class="min-w-0 space-y-2">
                    <Label for="portal-booking-recurrence">Wiederholung</Label>
                    <select
                        id="portal-booking-recurrence"
                        v-model="form.recurrence"
                        class="field"
                    >
                        <option value="none">Keine</option>
                        <option value="weekly">Wöchentlich</option>
                        <option value="monthly">Monatlich</option>
                    </select>
                </div>
                <div
                    v-if="form.recurrence !== 'none'"
                    class="min-w-0 space-y-2"
                >
                    <Label for="portal-booking-occurrences"
                        >Anzahl Termine</Label
                    >
                    <Input
                        id="portal-booking-occurrences"
                        v-model="form.occurrences"
                        type="number"
                        min="1"
                        max="52"
                        required
                    />
                    <InputError :message="form.errors.occurrences" />
                </div>
                <div class="min-w-0 space-y-2 sm:col-span-2">
                    <Label for="portal-booking-notes"
                        >Hinweise an den Vorstand</Label
                    >
                    <Textarea id="portal-booking-notes" v-model="form.notes" />
                    <InputError :message="form.errors.notes" />
                </div>
            </div>
            <StatusAlert
                v-if="form.hasErrors"
                type="error"
                title="Buchung nicht gespeichert"
                :messages="Object.values(form.errors)"
            />
            <Button :disabled="form.processing">Buchung absenden</Button>
        </form>

        <section class="space-y-4">
            <div>
                <h2 class="text-xl font-medium">Meine Termine</h2>
                <p class="mt-1 text-sm text-muted-foreground">
                    Serientermine können hier einzeln storniert werden.
                </p>
            </div>
            <StatusAlert
                v-if="!bookings.length"
                type="info"
                title="Noch keine Buchungen"
            >
                {{ $address('Du hast', 'Sie haben') }} bisher keine Ressourcen
                gebucht.
            </StatusAlert>
            <div class="grid gap-4 md:grid-cols-2">
                <article
                    v-for="booking in activeBookings"
                    :key="booking.id"
                    class="space-y-3 rounded-xl border bg-card p-5"
                >
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <h3 class="font-medium">
                                {{ booking.resource_name }}
                            </h3>
                            <p class="text-sm">{{ booking.title }}</p>
                        </div>
                        <Badge
                            :variant="
                                booking.status === 'confirmed'
                                    ? 'secondary'
                                    : 'outline'
                            "
                            >{{ labels[booking.status] }}</Badge
                        >
                    </div>
                    <p class="text-sm">
                        {{ dateTime(booking.starts_at) }} bis
                        {{ dateTime(booking.ends_at) }}
                    </p>
                    <p
                        v-if="booking.price_cents"
                        class="text-sm text-muted-foreground"
                    >
                        Preis für diesen Termin:
                        {{ money(booking.price_cents) }}
                    </p>
                    <Button
                        v-if="booking.can_cancel"
                        type="button"
                        size="sm"
                        variant="outline"
                        :disabled="cancelForm.processing"
                        @click="cancel(booking)"
                        ><X class="size-4" />Diesen Termin stornieren</Button
                    >
                </article>
            </div>
            <details
                v-if="pastBookings.length"
                class="rounded-xl border bg-card p-5"
            >
                <summary class="cursor-pointer font-medium">
                    <RotateCcw class="mr-2 inline size-4" />Stornierte und
                    abgelehnte Buchungen ({{ pastBookings.length }})
                </summary>
                <ul class="mt-4 space-y-3">
                    <li
                        v-for="booking in pastBookings"
                        :key="booking.id"
                        class="flex flex-wrap justify-between gap-2 border-t pt-3 text-sm"
                    >
                        <span
                            >{{ booking.resource_name }} · {{ booking.title }} ·
                            {{ dateTime(booking.starts_at) }}</span
                        ><Badge variant="outline">{{
                            labels[booking.status]
                        }}</Badge>
                    </li>
                </ul>
            </details>
        </section>
    </Frame>
</template>
