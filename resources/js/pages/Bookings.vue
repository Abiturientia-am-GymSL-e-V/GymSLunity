<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { CalendarDays, Check, Pencil, Plus, X } from '@lucide/vue';
import BookingCalendar from '@/components/bookings/BookingCalendar.vue';
import ManualBookingForm from '@/components/bookings/ManualBookingForm.vue';
import BookingResourceForm from '@/components/bookings/BookingResourceForm.vue';
import StatusAlert from '@/components/StatusAlert.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { formatDateTime, formatMoney } from '@/lib/format';
import {
    bookingPriceLabel,
    resourceLabel as resourcePath,
} from '@/lib/bookings';
import type {
    BookableInventoryItem,
    BookingMemberField,
    BookingMemberOption,
    BookingResource,
    ResourceBooking,
} from '@/types/bookings';

const props = defineProps<{
    activeTab:
        | 'calendar'
        | 'resources'
        | 'resource-create'
        | 'requests'
        | 'create';
    month: string;
    resources: BookingResource[];
    inventoryItems: BookableInventoryItem[];
    membershipTypes: Record<string, string>;
    memberFields: BookingMemberField[];
    editingResourceId: number | null;
    members: BookingMemberOption[];
    bookings: ResourceBooking[];
    requests: ResourceBooking[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Buchungen', href: '/buchungen' },
            { title: 'Verwaltung' },
        ],
    },
});

const tabs = [
    { key: 'calendar', label: 'Belegung', href: '/buchungen' },
    { key: 'resources', label: 'Ressourcen', href: '/buchungen/ressourcen' },
    {
        key: 'resource-create',
        label: 'Ressource anlegen',
        href: '/buchungen/ressourcen/anlegen',
    },
    { key: 'requests', label: 'Buchungsanfragen', href: '/buchungen/anfragen' },
    { key: 'create', label: 'Buchung anlegen', href: '/buchungen/anlegen' },
];
const decisionForm = useForm({ decision: 'approve' });
const resourceLabel = (resource: BookingResource) =>
    resourcePath(resource, props.resources);
function decide(booking: ResourceBooking, decision: 'approve' | 'reject') {
    decisionForm.decision = decision;
    decisionForm.patch('/buchungen/' + booking.id + '/entscheidung', {
        preserveScroll: true,
    });
}
</script>

<template>
    <Head title="Buchungen" />
    <div class="mx-auto w-full max-w-[1200px] space-y-6 p-4 sm:p-6">
        <header>
            <h1 class="text-2xl font-semibold tracking-tight">Buchungen</h1>
            <p class="mt-1 text-sm text-muted-foreground">
                Verwalte buchbare Ressourcen, Belegungen und offene Anfragen.
            </p>
        </header>

        <nav aria-label="Buchungen" class="flex flex-wrap gap-2 border-b pb-4">
            <Link
                v-for="tab in tabs"
                :key="tab.key"
                :href="tab.href"
                class="rounded-lg px-3 py-2 text-sm font-medium transition-colors hover:bg-muted"
                :class="
                    activeTab === tab.key
                        ? 'bg-muted text-foreground'
                        : 'text-muted-foreground'
                "
                :aria-current="activeTab === tab.key ? 'page' : undefined"
                >{{ tab.label }}</Link
            >
        </nav>

        <BookingCalendar
            v-if="activeTab === 'calendar'"
            :month="month"
            :bookings="bookings"
            :resources="resources"
        />

        <section v-else-if="activeTab === 'resources'" class="space-y-4">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 class="text-lg font-semibold">Ressourcen</h2>
                    <p class="mt-1 text-sm text-muted-foreground">
                        Alle Räume, Geräte und hierarchisch verbundenen
                        Teilressourcen.
                    </p>
                </div>
                <Button as-child>
                    <Link href="/buchungen/ressourcen/anlegen"
                        ><Plus class="size-4" />Ressource anlegen</Link
                    >
                </Button>
            </div>
            <StatusAlert
                v-if="!resources.length"
                type="info"
                title="Noch keine Ressourcen"
            >
                Lege die erste buchbare Ressource an.
            </StatusAlert>
            <div v-else class="overflow-hidden rounded-xl border bg-card">
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[850px] text-sm">
                        <thead class="bg-muted/60 text-left">
                            <tr>
                                <th class="px-4 py-3 font-semibold">
                                    Ressource
                                </th>
                                <th class="px-4 py-3 font-semibold">Ort</th>
                                <th class="px-4 py-3 font-semibold">Preis</th>
                                <th class="px-4 py-3 font-semibold">
                                    Berechtigungen
                                </th>
                                <th class="px-4 py-3 font-semibold">Status</th>
                                <th class="px-4 py-3 font-semibold">
                                    Aktionen
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr
                                v-for="resource in resources"
                                :key="resource.id"
                            >
                                <td class="px-4 py-3">
                                    <p class="font-medium">
                                        {{ resourceLabel(resource) }}
                                    </p>
                                    <p
                                        v-if="resource.description"
                                        class="mt-1 max-w-md text-xs text-muted-foreground"
                                    >
                                        {{ resource.description }}
                                    </p>
                                </td>
                                <td class="px-4 py-3">
                                    {{ resource.location || '–' }}
                                </td>
                                <td class="px-4 py-3">
                                    {{ bookingPriceLabel(resource) }}
                                </td>
                                <td class="px-4 py-3">
                                    {{
                                        resource.access_rules.length
                                            ? resource.access_rules.length +
                                              ' Regel(n)'
                                            : 'Alle Mitglieder'
                                    }}
                                </td>
                                <td class="px-4 py-3">
                                    <Badge
                                        :variant="
                                            resource.is_active
                                                ? 'secondary'
                                                : 'outline'
                                        "
                                        >{{
                                            resource.is_active
                                                ? 'Aktiv'
                                                : 'Inaktiv'
                                        }}</Badge
                                    >
                                </td>
                                <td class="px-4 py-3">
                                    <div class="flex flex-wrap gap-2">
                                        <Button
                                            as-child
                                            size="sm"
                                            variant="outline"
                                        >
                                            <Link
                                                :href="
                                                    '/buchungen/ressourcen/' +
                                                    resource.id
                                                "
                                                ><CalendarDays
                                                    class="size-4"
                                                />Belegung</Link
                                            >
                                        </Button>
                                        <Button
                                            as-child
                                            size="sm"
                                            variant="ghost"
                                        >
                                            <Link
                                                :href="
                                                    '/buchungen/ressourcen/anlegen?edit=' +
                                                    resource.id
                                                "
                                                ><Pencil
                                                    class="size-4"
                                                />Bearbeiten</Link
                                            >
                                        </Button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        <BookingResourceForm
            v-else-if="activeTab === 'resource-create'"
            :resources="resources"
            :inventory-items="inventoryItems"
            :member-fields="memberFields"
            :editing-resource-id="editingResourceId"
        />

        <section v-else-if="activeTab === 'requests'" class="space-y-4">
            <div>
                <h2 class="text-lg font-semibold">
                    Offene Buchungsanfragen ({{ requests.length }})
                </h2>
                <p class="mt-1 text-sm text-muted-foreground">
                    Bestätigte kostenpflichtige Termine werden nach Ablauf der
                    Stornierungsfrist dem Beitragskonto belastet.
                </p>
            </div>
            <StatusAlert
                v-if="decisionForm.hasErrors"
                type="error"
                title="Anfrage nicht bearbeitet"
                :messages="Object.values(decisionForm.errors)"
            />
            <StatusAlert
                v-if="!requests.length"
                type="success"
                title="Keine offenen Buchungsanfragen"
                >Derzeit warten keine Buchungen auf eine
                Entscheidung.</StatusAlert
            >
            <article
                v-for="booking in requests"
                :key="booking.id"
                class="grid gap-4 rounded-xl border bg-card p-5 lg:grid-cols-[minmax(0,1fr)_auto] lg:items-center"
            >
                <div class="min-w-0 space-y-1">
                    <h3 class="font-semibold">
                        <Link
                            :href="'/buchungen/' + booking.id"
                            class="underline-offset-4 hover:underline"
                        >
                            {{ booking.resource_name }} · {{ booking.title }}
                        </Link>
                    </h3>
                    <p class="text-sm">
                        {{ formatDateTime(booking.starts_at) }} bis
                        {{ formatDateTime(booking.ends_at) }}
                    </p>
                    <p class="text-sm text-muted-foreground">
                        {{ booking.requester_name
                        }}<template v-if="booking.member_number">
                            · Nr. {{ booking.member_number }}</template
                        ><template v-if="booking.price_cents">
                            · {{ formatMoney(booking.price_cents) }}</template
                        ><template v-if="booking.series_id">
                            · Serientermin {{ booking.occurrence }}</template
                        >
                    </p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <Button
                        variant="outline"
                        :disabled="decisionForm.processing"
                        @click="decide(booking, 'reject')"
                        ><X class="size-4" />Ablehnen</Button
                    ><Button
                        :disabled="decisionForm.processing"
                        @click="decide(booking, 'approve')"
                        ><Check class="size-4" />Bestätigen</Button
                    >
                </div>
            </article>
        </section>

        <ManualBookingForm v-else :resources="resources" :members="members" />
    </div>
</template>
