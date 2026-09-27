<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { CalendarDays, Check, Pencil, Plus, X } from '@lucide/vue';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import SearchableDropdown from '@/components/SearchableDropdown.vue';
import StatusAlert from '@/components/StatusAlert.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';

type Resource = {
    id: number;
    parent_id: number | null;
    inventory_item_id: number | null;
    name: string;
    description: string | null;
    location: string | null;
    allowed_membership_types: string[];
    auto_approve_membership_types: string[];
    price_mode: 'free' | 'once' | 'hour' | 'day';
    price_cents: number;
    is_active: boolean;
};
type Booking = {
    id: number;
    resource_id: number;
    resource_name: string;
    member_id: number | null;
    member_number: number | null;
    requester_name: string;
    title: string;
    notes: string | null;
    starts_at: string;
    ends_at: string;
    series_id: string | null;
    occurrence: number;
    status: 'requested' | 'confirmed' | 'cancelled' | 'rejected';
    price_cents: number;
    created_by_name: string | null;
    decided_by_name: string | null;
};
type MemberOption = {
    id: number;
    label: string;
    membership_type: string;
};
type InventoryItem = {
    id: number;
    number: string;
    name: string;
    description: string | null;
    location: string;
};

const props = defineProps<{
    activeTab: 'calendar' | 'resources' | 'requests' | 'create';
    month: string;
    resources: Resource[];
    inventoryItems: InventoryItem[];
    membershipTypes: Record<string, string>;
    members: MemberOption[];
    bookings: Booking[];
    requests: Booking[];
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
    { key: 'requests', label: 'Buchungsanfragen', href: '/buchungen/anfragen' },
    { key: 'create', label: 'Buchung anlegen', href: '/buchungen/anlegen' },
];
const editingResource = ref<number | null>(null);
const resourceForm = useForm({
    name: '',
    description: '',
    location: '',
    parent_id: '' as string | number,
    inventory_item_id: '' as string | number,
    allowed_membership_types: [] as string[],
    auto_approve_membership_types: [] as string[],
    price_mode: 'free' as Resource['price_mode'],
    price: '',
    is_active: true,
});
const bookingForm = useForm({
    resource_id: '' as string,
    member_id: '' as string,
    requester_name: '',
    title: '',
    notes: '',
    starts_at: '',
    ends_at: '',
    recurrence: 'none' as 'none' | 'weekly' | 'monthly',
    occurrences: 1,
});
const decisionForm = useForm({ decision: 'approve' });
const cancelForm = useForm({});

const resourceOptions = computed(() =>
    props.resources
        .filter((resource) => resource.is_active)
        .map((resource) => ({
            value: String(resource.id),
            label: resourceLabel(resource),
            search: `${resourceLabel(resource)} ${resource.location ?? ''}`,
        })),
);
const memberOptions = computed(() => [
    { value: '', label: 'Ohne Mitgliedsbezug / Dritte' },
    ...props.members.map((member) => ({
        value: String(member.id),
        label: member.label,
        suffix: member.membership_type,
        search: `${member.label} ${member.membership_type}`,
    })),
]);
const currentMonth = computed({
    get: () => props.month,
    set: (value: string) =>
        router.get('/buchungen', { month: value }, { preserveState: true }),
});
const calendarDays = computed(() => {
    const [year, month] = props.month.split('-').map(Number);
    const first = new Date(year, month - 1, 1);
    const offset = (first.getDay() + 6) % 7;
    const days = new Date(year, month, 0).getDate();

    return Array.from({ length: offset + days }, (_, index) => {
        if (index < offset) return null;
        const day = index - offset + 1;
        const key = `${props.month}-${String(day).padStart(2, '0')}`;
        return {
            day,
            key,
            bookings: props.bookings.filter((item) => occursOn(item, key)),
        };
    });
});

function occursOn(booking: Booking, day: string) {
    const start = new Date(`${day}T00:00:00`);
    const end = new Date(start);
    end.setDate(end.getDate() + 1);
    return (
        new Date(booking.starts_at) < end && new Date(booking.ends_at) > start
    );
}

function resourceLabel(resource: Resource): string {
    const labels = [resource.name];
    let parent = props.resources.find((item) => item.id === resource.parent_id);
    while (parent) {
        labels.unshift(parent.name);
        parent = props.resources.find((item) => item.id === parent?.parent_id);
    }
    return labels.join(' › ');
}
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
function priceLabel(resource: Resource) {
    if (resource.price_mode === 'free') return 'Kostenlos';
    const suffix = { once: 'einmalig', hour: 'je Stunde', day: 'je Tag' }[
        resource.price_mode
    ];
    return `${money(resource.price_cents)} ${suffix}`;
}
function statusLabel(status: Booking['status']) {
    return {
        requested: 'Angefragt',
        confirmed: 'Bestätigt',
        cancelled: 'Storniert',
        rejected: 'Abgelehnt',
    }[status];
}
function saveResource() {
    const options = { preserveScroll: true, onSuccess: resetResourceForm };
    if (editingResource.value) {
        resourceForm.patch(
            `/buchungen/ressourcen/${editingResource.value}`,
            options,
        );
    } else {
        resourceForm.post('/buchungen/ressourcen', options);
    }
}
function editResource(resource: Resource) {
    editingResource.value = resource.id;
    resourceForm.name = resource.name;
    resourceForm.description = resource.description ?? '';
    resourceForm.location = resource.location ?? '';
    resourceForm.parent_id = resource.parent_id ?? '';
    resourceForm.inventory_item_id = resource.inventory_item_id ?? '';
    resourceForm.allowed_membership_types = [
        ...resource.allowed_membership_types,
    ];
    resourceForm.auto_approve_membership_types = [
        ...resource.auto_approve_membership_types,
    ];
    resourceForm.price_mode = resource.price_mode;
    resourceForm.price = (resource.price_cents / 100).toFixed(2);
    resourceForm.is_active = resource.is_active;
    resourceForm.clearErrors();
    window.scrollTo({ top: 0, behavior: 'smooth' });
}
function useInventoryItem() {
    const item = props.inventoryItems.find(
        (entry) => entry.id === Number(resourceForm.inventory_item_id),
    );
    if (!item) return;
    resourceForm.name = item.name;
    resourceForm.description = item.description ?? '';
    resourceForm.location = item.location;
}
function resetResourceForm() {
    editingResource.value = null;
    resourceForm.reset();
    resourceForm.clearErrors();
}
function createBooking() {
    bookingForm.post('/buchungen', {
        preserveScroll: true,
        onSuccess: () => bookingForm.reset(),
    });
}
function decide(booking: Booking, decision: 'approve' | 'reject') {
    decisionForm.decision = decision;
    decisionForm.patch(`/buchungen/${booking.id}/entscheidung`, {
        preserveScroll: true,
    });
}
function cancel(booking: Booking) {
    if (!window.confirm('Diesen einzelnen Buchungstermin wirklich stornieren?'))
        return;
    cancelForm.patch(`/buchungen/${booking.id}/stornieren`, {
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

        <section v-if="activeTab === 'calendar'" class="space-y-4">
            <div class="flex flex-wrap items-end justify-between gap-4">
                <div>
                    <h2 class="text-lg font-semibold">Belegungskalender</h2>
                    <p class="mt-1 text-sm text-muted-foreground">
                        Bestätigte und angefragte Termine aller Ressourcen.
                    </p>
                </div>
                <div class="min-w-0 space-y-2">
                    <Label for="booking-month">Monat</Label>
                    <Input
                        id="booking-month"
                        v-model="currentMonth"
                        type="month"
                    />
                </div>
            </div>
            <StatusAlert
                v-if="!resources.length"
                type="info"
                title="Noch keine Ressourcen"
            >
                {{ $address('Lege', 'Legen Sie') }} zuerst im Reiter
                „Ressourcen“ einen Raum, ein Gerät oder eine andere Ressource
                an.
            </StatusAlert>
            <div v-else class="overflow-x-auto rounded-xl border bg-card">
                <div
                    class="grid min-w-[760px] grid-cols-7 border-b bg-muted/30 text-sm font-medium"
                >
                    <div
                        v-for="day in [
                            'Mo',
                            'Di',
                            'Mi',
                            'Do',
                            'Fr',
                            'Sa',
                            'So',
                        ]"
                        :key="day"
                        class="p-3"
                    >
                        {{ day }}
                    </div>
                </div>
                <div class="grid min-w-[760px] grid-cols-7">
                    <div
                        v-for="(day, index) in calendarDays"
                        :key="index"
                        class="min-h-32 border-r border-b p-2 last:border-r-0"
                        :class="{ 'bg-muted/20': !day }"
                    >
                        <template v-if="day">
                            <span class="text-sm font-medium">{{
                                day.day
                            }}</span>
                            <div class="mt-2 space-y-1">
                                <Link
                                    v-for="booking in day.bookings"
                                    :key="booking.id"
                                    :href="`/buchungen/ressourcen/${booking.resource_id}?month=${month}`"
                                    class="block rounded border bg-background p-2 text-xs hover:bg-muted"
                                >
                                    <span class="font-medium"
                                        >{{ booking.starts_at.slice(11) }} ·
                                        {{ booking.resource_name }}</span
                                    >
                                    <span class="block truncate">{{
                                        booking.title
                                    }}</span>
                                    <Badge class="mt-1" variant="outline">{{
                                        statusLabel(booking.status)
                                    }}</Badge>
                                </Link>
                            </div>
                        </template>
                    </div>
                </div>
            </div>
        </section>

        <section v-else-if="activeTab === 'resources'" class="space-y-6">
            <form
                class="space-y-5 rounded-xl border bg-card p-5"
                @submit.prevent="saveResource"
            >
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <h2 class="text-lg font-semibold">
                            {{
                                editingResource
                                    ? 'Ressource bearbeiten'
                                    : 'Ressource anlegen'
                            }}
                        </h2>
                        <p class="mt-1 text-sm text-muted-foreground">
                            Teilressourcen übernehmen die Belegungssperre ihrer
                            übergeordneten Ressource.
                        </p>
                    </div>
                    <Button
                        v-if="editingResource"
                        type="button"
                        variant="ghost"
                        @click="resetResourceForm"
                        ><X class="size-4" />Abbrechen</Button
                    >
                </div>
                <div class="grid gap-5 sm:grid-cols-2">
                    <div
                        v-if="inventoryItems.length"
                        class="min-w-0 space-y-2 sm:col-span-2"
                    >
                        <Label for="resource-inventory"
                            >Daten aus dem Inventar übernehmen</Label
                        >
                        <div class="flex flex-col gap-2 sm:flex-row">
                            <select
                                id="resource-inventory"
                                v-model="resourceForm.inventory_item_id"
                                class="field min-w-0 flex-1"
                            >
                                <option value="">
                                    Kein Inventargegenstand
                                </option>
                                <option
                                    v-for="item in inventoryItems"
                                    :key="item.id"
                                    :value="item.id"
                                >
                                    {{ item.number }} · {{ item.name }}
                                </option>
                            </select>
                            <Button
                                type="button"
                                variant="outline"
                                :disabled="!resourceForm.inventory_item_id"
                                @click="useInventoryItem"
                            >
                                Angaben übernehmen
                            </Button>
                        </div>
                        <p class="text-sm text-muted-foreground">
                            Name, Beschreibung und Ort werden übernommen und
                            können anschließend angepasst werden.
                        </p>
                        <InputError
                            :message="resourceForm.errors.inventory_item_id"
                        />
                    </div>
                    <div class="min-w-0 space-y-2">
                        <Label for="resource-name">Name *</Label
                        ><Input
                            id="resource-name"
                            v-model="resourceForm.name"
                            required
                        /><InputError :message="resourceForm.errors.name" />
                    </div>
                    <div class="min-w-0 space-y-2">
                        <Label for="resource-location">Ort</Label
                        ><Input
                            id="resource-location"
                            v-model="resourceForm.location"
                        /><InputError :message="resourceForm.errors.location" />
                    </div>
                    <div class="min-w-0 space-y-2 sm:col-span-2">
                        <Label for="resource-description">Beschreibung</Label
                        ><Textarea
                            id="resource-description"
                            v-model="resourceForm.description"
                        /><InputError
                            :message="resourceForm.errors.description"
                        />
                    </div>
                    <div class="min-w-0 space-y-2">
                        <Label for="resource-parent"
                            >Übergeordnete Ressource</Label
                        >
                        <select
                            id="resource-parent"
                            v-model="resourceForm.parent_id"
                            class="field"
                        >
                            <option value="">Keine</option>
                            <option
                                v-for="resource in resources.filter(
                                    (item) => item.id !== editingResource,
                                )"
                                :key="resource.id"
                                :value="resource.id"
                            >
                                {{ resourceLabel(resource) }}
                            </option>
                        </select>
                        <InputError :message="resourceForm.errors.parent_id" />
                    </div>
                    <div class="min-w-0 space-y-2">
                        <Label for="resource-price-mode">Preisberechnung</Label>
                        <select
                            id="resource-price-mode"
                            v-model="resourceForm.price_mode"
                            class="field"
                        >
                            <option value="free">Kostenlos</option>
                            <option value="once">Einmalig</option>
                            <option value="hour">Je angefangene Stunde</option>
                            <option value="day">Je angefangenen Tag</option>
                        </select>
                    </div>
                    <div
                        v-if="resourceForm.price_mode !== 'free'"
                        class="min-w-0 space-y-2"
                    >
                        <Label for="resource-price">Preis in Euro</Label
                        ><Input
                            id="resource-price"
                            v-model="resourceForm.price"
                            type="number"
                            min="0"
                            step="0.01"
                            required
                        /><InputError :message="resourceForm.errors.price" />
                    </div>
                    <label
                        class="flex items-center gap-3 self-end rounded-lg border p-3 text-sm"
                        ><input
                            v-model="resourceForm.is_active"
                            type="checkbox"
                            class="size-4 accent-primary"
                        />
                        Ressource ist buchbar</label
                    >
                </div>
                <div class="grid gap-5 lg:grid-cols-2">
                    <fieldset class="space-y-3 rounded-lg border p-4">
                        <legend class="px-1 text-sm font-medium">
                            Buchungsberechtigung
                        </legend>
                        <p class="text-sm text-muted-foreground">
                            Keine Auswahl bedeutet: alle Mitgliedsarten.
                        </p>
                        <label
                            v-for="(label, value) in membershipTypes"
                            :key="value"
                            class="flex items-center gap-2 text-sm"
                            ><input
                                v-model="resourceForm.allowed_membership_types"
                                type="checkbox"
                                :value="value"
                                class="size-4 accent-primary"
                            />{{ label }}</label
                        >
                        <InputError
                            :message="
                                resourceForm.errors.allowed_membership_types
                            "
                        />
                    </fieldset>
                    <fieldset class="space-y-3 rounded-lg border p-4">
                        <legend class="px-1 text-sm font-medium">
                            Automatische Bestätigung
                        </legend>
                        <p class="text-sm text-muted-foreground">
                            Andere zulässige Mitgliedsarten erzeugen eine
                            Buchungsanfrage.
                        </p>
                        <label
                            v-for="(label, value) in membershipTypes"
                            :key="value"
                            class="flex items-center gap-2 text-sm"
                            ><input
                                v-model="
                                    resourceForm.auto_approve_membership_types
                                "
                                type="checkbox"
                                :value="value"
                                class="size-4 accent-primary"
                            />{{ label }}</label
                        >
                        <InputError
                            :message="
                                resourceForm.errors
                                    .auto_approve_membership_types
                            "
                        />
                    </fieldset>
                </div>
                <Button :disabled="resourceForm.processing"
                    ><Plus v-if="!editingResource" class="size-4" /><Check
                        v-else
                        class="size-4"
                    />{{
                        editingResource
                            ? 'Änderungen speichern'
                            : 'Ressource anlegen'
                    }}</Button
                >
            </form>

            <div class="grid gap-4 md:grid-cols-2">
                <article
                    v-for="resource in resources"
                    :key="resource.id"
                    class="space-y-3 rounded-xl border bg-card p-5"
                >
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <h3 class="font-semibold">
                                {{ resourceLabel(resource) }}
                            </h3>
                            <p
                                v-if="resource.location"
                                class="text-sm text-muted-foreground"
                            >
                                {{ resource.location }}
                            </p>
                        </div>
                        <Badge
                            :variant="
                                resource.is_active ? 'secondary' : 'outline'
                            "
                            >{{
                                resource.is_active ? 'Aktiv' : 'Inaktiv'
                            }}</Badge
                        >
                    </div>
                    <p v-if="resource.description" class="text-sm">
                        {{ resource.description }}
                    </p>
                    <p class="text-sm">
                        <span class="font-medium">Preis:</span>
                        {{ priceLabel(resource) }}
                    </p>
                    <p class="text-sm text-muted-foreground">
                        Berechtigt:
                        {{
                            resource.allowed_membership_types.length
                                ? resource.allowed_membership_types.join(', ')
                                : 'Alle Mitgliedsarten'
                        }}
                    </p>
                    <div class="flex flex-wrap gap-2">
                        <Button as-child size="sm" variant="outline"
                            ><Link
                                :href="`/buchungen/ressourcen/${resource.id}`"
                                ><CalendarDays class="size-4" />Kalender</Link
                            ></Button
                        ><Button
                            type="button"
                            size="sm"
                            variant="ghost"
                            @click="editResource(resource)"
                            ><Pencil class="size-4" />Bearbeiten</Button
                        >
                    </div>
                </article>
            </div>
        </section>

        <section v-else-if="activeTab === 'requests'" class="space-y-4">
            <div>
                <h2 class="text-lg font-semibold">
                    Offene Buchungsanfragen ({{ requests.length }})
                </h2>
                <p class="mt-1 text-sm text-muted-foreground">
                    Bestätigte kostenpflichtige Termine werden dem Beitragskonto
                    belastet.
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
                        {{ booking.resource_name }} · {{ booking.title }}
                    </h3>
                    <p class="text-sm">
                        {{ dateTime(booking.starts_at) }} bis
                        {{ dateTime(booking.ends_at) }}
                    </p>
                    <p class="text-sm text-muted-foreground">
                        {{ booking.requester_name
                        }}<template v-if="booking.member_number">
                            · Nr. {{ booking.member_number }}</template
                        ><template v-if="booking.price_cents">
                            · {{ money(booking.price_cents) }}</template
                        ><template v-if="booking.series_id">
                            · Serientermin {{ booking.occurrence }}</template
                        >
                    </p>
                    <p v-if="booking.notes" class="text-sm">
                        {{ booking.notes }}
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

        <section v-else class="space-y-4">
            <div>
                <h2 class="text-lg font-semibold">Manuelle Buchung</h2>
                <p class="mt-1 text-sm text-muted-foreground">
                    Für Mitglieder, Abteilungen oder externe Dritte. Manuelle
                    Buchungen werden direkt bestätigt.
                </p>
            </div>
            <StatusAlert
                v-if="!resources.some((resource) => resource.is_active)"
                type="warning"
                title="Keine aktive Ressource"
                >{{ $address('Lege', 'Legen Sie') }} zuerst eine aktive
                Ressource an.</StatusAlert
            >
            <form
                v-else
                class="space-y-5 rounded-xl border bg-card p-5"
                @submit.prevent="createBooking"
            >
                <div class="grid gap-5 sm:grid-cols-2">
                    <div class="min-w-0 space-y-2">
                        <Label for="booking-resource">Ressource *</Label
                        ><SearchableDropdown
                            id="booking-resource"
                            :model-value="bookingForm.resource_id"
                            :options="resourceOptions"
                            aria-label="Ressource auswählen"
                            search-placeholder="Ressource suchen"
                            empty-text="Keine Ressource gefunden"
                            trigger-class="h-9 w-full rounded-md border border-input bg-background px-3"
                            @update:model-value="
                                bookingForm.resource_id = $event
                            "
                        /><InputError
                            :message="bookingForm.errors.resource_id"
                        />
                    </div>
                    <div class="min-w-0 space-y-2">
                        <Label for="booking-member">Mitglied oder Dritte</Label
                        ><SearchableDropdown
                            id="booking-member"
                            :model-value="bookingForm.member_id"
                            :options="memberOptions"
                            aria-label="Mitglied auswählen"
                            search-placeholder="Name oder Mitgliedsnummer suchen"
                            empty-text="Kein Mitglied gefunden"
                            trigger-class="h-9 w-full rounded-md border border-input bg-background px-3"
                            @update:model-value="bookingForm.member_id = $event"
                        /><InputError :message="bookingForm.errors.member_id" />
                    </div>
                    <div
                        v-if="!bookingForm.member_id"
                        class="min-w-0 space-y-2 sm:col-span-2"
                    >
                        <Label for="booking-requester"
                            >Bezeichnung der Abteilung oder des Dritten *</Label
                        ><Input
                            id="booking-requester"
                            v-model="bookingForm.requester_name"
                            required
                        /><InputError
                            :message="bookingForm.errors.requester_name"
                        />
                    </div>
                    <div class="min-w-0 space-y-2 sm:col-span-2">
                        <Label for="booking-title">Anlass *</Label
                        ><Input
                            id="booking-title"
                            v-model="bookingForm.title"
                            required
                        /><InputError :message="bookingForm.errors.title" />
                    </div>
                    <div class="min-w-0 space-y-2">
                        <Label for="booking-start">Beginn *</Label
                        ><Input
                            id="booking-start"
                            v-model="bookingForm.starts_at"
                            type="datetime-local"
                            required
                        /><InputError :message="bookingForm.errors.starts_at" />
                    </div>
                    <div class="min-w-0 space-y-2">
                        <Label for="booking-end">Ende *</Label
                        ><Input
                            id="booking-end"
                            v-model="bookingForm.ends_at"
                            type="datetime-local"
                            required
                        /><InputError :message="bookingForm.errors.ends_at" />
                    </div>
                    <div class="min-w-0 space-y-2">
                        <Label for="booking-recurrence">Wiederholung</Label
                        ><select
                            id="booking-recurrence"
                            v-model="bookingForm.recurrence"
                            class="field"
                        >
                            <option value="none">Keine</option>
                            <option value="weekly">Wöchentlich</option>
                            <option value="monthly">Monatlich</option>
                        </select>
                    </div>
                    <div
                        v-if="bookingForm.recurrence !== 'none'"
                        class="min-w-0 space-y-2"
                    >
                        <Label for="booking-occurrences">Anzahl Termine</Label
                        ><Input
                            id="booking-occurrences"
                            v-model="bookingForm.occurrences"
                            type="number"
                            min="1"
                            max="52"
                            required
                        /><InputError
                            :message="bookingForm.errors.occurrences"
                        />
                    </div>
                    <div class="min-w-0 space-y-2 sm:col-span-2">
                        <Label for="booking-notes">Notizen</Label
                        ><Textarea
                            id="booking-notes"
                            v-model="bookingForm.notes"
                        /><InputError :message="bookingForm.errors.notes" />
                    </div>
                </div>
                <Button :disabled="bookingForm.processing"
                    ><Plus class="size-4" />Buchung anlegen</Button
                >
            </form>
        </section>
    </div>
</template>
