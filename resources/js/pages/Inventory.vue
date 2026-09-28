<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ArchiveX, Eye, PackagePlus, PackageSearch, Search } from '@lucide/vue';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import StatusAlert from '@/components/StatusAlert.vue';
import { Badge } from '@/components/ui/badge';
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
import { Spinner } from '@/components/ui/spinner';
import { formatDate, formatMoney } from '@/lib/format';

type Status = 'active' | 'sold' | 'lost' | 'disposed';
type DepreciationMethod = 'linear' | 'immediate' | 'none';
type InventoryItem = {
    id: number;
    inventory_number: string;
    name: string;
    category: string;
    description: string | null;
    manufacturer: string | null;
    model: string | null;
    serial_number: string | null;
    location: string;
    responsible_person: string | null;
    acquisition_type: string;
    acquisition_date: string;
    acquisition_cost_cents: number;
    document_reference: string | null;
    depreciation_method: DepreciationMethod;
    useful_life_years: number | null;
    annual_depreciation_cents: number;
    book_value_cents: number;
    status: Status;
    disposed_at: string | null;
    disposal_proceeds_cents: number | null;
    disposal_note: string | null;
    created_by_name: string;
    disposed_by_name: string | null;
};
type Options = {
    categories: Record<string, string>;
    acquisitionTypes: Record<string, string>;
    depreciationMethods: Record<string, string>;
    statuses: Record<Status, string>;
};

const props = defineProps<{
    activeTab: 'overview' | 'create';
    items: InventoryItem[];
    options: Options;
    summary: {
        active_count: number;
        retired_count: number;
        acquisition_value_cents: number;
        book_value_cents: number;
    };
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Inventar', href: '/inventar' }] },
});

const tabs = [
    ['overview', 'Übersicht', PackageSearch, '/inventar'],
    ['create', 'Inventarisieren', PackagePlus, '/inventar/inventarisieren'],
] as const;

const today = new Intl.DateTimeFormat('sv-SE').format(new Date());
const query = ref('');
const statusFilter = ref<'all' | Status>('all');
const categoryFilter = ref('all');
const filteredItems = computed(() => {
    const needle = query.value.trim().toLocaleLowerCase('de');
    return props.items.filter((item) => {
        if (statusFilter.value !== 'all' && item.status !== statusFilter.value)
            return false;
        if (
            categoryFilter.value !== 'all' &&
            item.category !== categoryFilter.value
        )
            return false;
        if (!needle) return true;
        return [
            item.inventory_number,
            item.name,
            item.manufacturer,
            item.model,
            item.serial_number,
            item.location,
            item.responsible_person,
        ]
            .filter(Boolean)
            .join(' ')
            .toLocaleLowerCase('de')
            .includes(needle);
    });
});

const createForm = useForm({
    name: '',
    category: 'sports_equipment',
    description: '',
    manufacturer: '',
    model: '',
    serial_number: '',
    location: '',
    responsible_person: '',
    acquisition_type: 'purchase',
    acquisition_date: today,
    acquisition_cost: '',
    document_reference: '',
    depreciation_method: 'linear' as DepreciationMethod,
    useful_life_years: '' as string | number,
});
function store() {
    createForm.post('/inventar', {
        preserveScroll: true,
        onSuccess: () => {
            createForm.reset();
        },
    });
}

const detailsOpen = ref(false);
const selected = ref<InventoryItem | null>(null);
function showDetails(item: InventoryItem) {
    selected.value = item;
    detailsOpen.value = true;
}

const disposalOpen = ref(false);
const retiring = ref<InventoryItem | null>(null);
const disposalForm = useForm({
    status: 'sold' as Exclude<Status, 'active'>,
    disposed_at: today,
    disposal_proceeds: '',
    disposal_note: '',
});
function openDisposal(item: InventoryItem) {
    retiring.value = item;
    disposalForm.defaults({
        status: 'sold',
        disposed_at: today,
        disposal_proceeds: '',
        disposal_note: '',
    });
    disposalForm.reset();
    disposalForm.clearErrors();
    disposalOpen.value = true;
}
function dispose() {
    if (!retiring.value) return;
    disposalForm.patch(
        `/inventar/${encodeURIComponent(retiring.value.inventory_number)}/abgang`,
        {
            preserveScroll: true,
            onSuccess: () => {
                disposalOpen.value = false;
                retiring.value = null;
            },
        },
    );
}
function closeDisposal(value: boolean) {
    if (!value && disposalForm.processing) return;
    if (
        !value &&
        disposalForm.isDirty &&
        !window.confirm('Ungespeicherte Abgangsdaten verwerfen?')
    )
        return;
    disposalOpen.value = value;
}
</script>

<template>
    <Head title="Inventar" />

    <div class="mx-auto w-full max-w-[1200px] space-y-6 p-4 sm:p-6">
        <header>
            <h1 class="text-2xl font-semibold tracking-tight">Inventar</h1>
            <p class="mt-1 text-sm text-muted-foreground">
                Vereinsvermögen eindeutig erfassen, bewerten und Abgänge
                nachvollziehbar dokumentieren.
            </p>
        </header>

        <nav
            class="flex flex-wrap gap-2 border-b pb-4"
            aria-label="Inventarbereiche"
        >
            <Link
                v-for="tab in tabs"
                :key="tab[0]"
                :href="tab[3]"
                :aria-current="activeTab === tab[0] ? 'page' : undefined"
                prefetch
                class="flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-medium transition-colors hover:bg-muted"
                :class="
                    activeTab === tab[0]
                        ? 'bg-muted text-foreground'
                        : 'text-muted-foreground'
                "
            >
                <component :is="tab[2]" class="size-4" />{{ tab[1] }}
            </Link>
        </nav>

        <template v-if="activeTab === 'overview'">
            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <div class="rounded-xl border bg-card p-5">
                    <p class="text-sm text-muted-foreground">Im Bestand</p>
                    <p class="mt-2 text-3xl font-semibold">
                        {{ summary.active_count }}
                    </p>
                </div>
                <div class="rounded-xl border bg-card p-5">
                    <p class="text-sm text-muted-foreground">
                        Anschaffungswert (Bestand)
                    </p>
                    <p class="mt-2 text-2xl font-semibold">
                        {{ formatMoney(summary.acquisition_value_cents) }}
                    </p>
                </div>
                <div class="rounded-xl border bg-card p-5">
                    <p class="text-sm text-muted-foreground">
                        Aktueller Restwert
                    </p>
                    <p class="mt-2 text-2xl font-semibold">
                        {{ formatMoney(summary.book_value_cents) }}
                    </p>
                    <p class="mt-1 text-xs text-muted-foreground">
                        Monatsgenau zum heutigen Datum
                    </p>
                </div>
                <div class="rounded-xl border bg-card p-5">
                    <p class="text-sm text-muted-foreground">
                        Erfasste Abgänge
                    </p>
                    <p class="mt-2 text-3xl font-semibold">
                        {{ summary.retired_count }}
                    </p>
                </div>
            </div>

            <div
                class="grid gap-3 rounded-xl border bg-card p-4 md:grid-cols-[minmax(16rem,1fr)_14rem_14rem]"
            >
                <div class="relative">
                    <Search
                        class="pointer-events-none absolute top-2.5 left-3 size-4 text-muted-foreground"
                    />
                    <Input
                        v-model="query"
                        type="search"
                        class="pl-9"
                        aria-label="Inventar durchsuchen"
                        placeholder="Nummer, Gegenstand, Seriennummer, Standort …"
                    />
                </div>
                <select
                    v-model="categoryFilter"
                    aria-label="Kategorie filtern"
                    class="h-9 rounded-md border border-input bg-background px-3 text-sm shadow-xs"
                >
                    <option value="all">Alle Kategorien</option>
                    <option
                        v-for="(label, value) in options.categories"
                        :key="value"
                        :value="value"
                    >
                        {{ label }}
                    </option>
                </select>
                <select
                    v-model="statusFilter"
                    aria-label="Status filtern"
                    class="h-9 rounded-md border border-input bg-background px-3 text-sm shadow-xs"
                >
                    <option value="all">Alle Status</option>
                    <option
                        v-for="(label, value) in options.statuses"
                        :key="value"
                        :value="value"
                    >
                        {{ label }}
                    </option>
                </select>
            </div>

            <section
                class="overflow-hidden rounded-xl border bg-card"
                aria-label="Inventartabelle"
            >
                <div
                    class="flex items-center justify-between border-b px-5 py-4"
                >
                    <div>
                        <h2 class="font-semibold">Inventarübersicht</h2>
                        <p class="mt-1 text-sm text-muted-foreground">
                            {{ filteredItems.length }} von
                            {{ items.length }} Einträgen
                        </p>
                    </div>
                    <Button size="sm" as-child>
                        <Link href="/inventar/inventarisieren">
                            <PackagePlus class="size-4" />Inventarisieren
                        </Link>
                    </Button>
                </div>
                <p
                    v-if="!filteredItems.length"
                    class="p-8 text-center text-sm text-muted-foreground"
                >
                    Keine passenden Inventareinträge gefunden.
                </p>
                <div v-else class="overflow-x-auto">
                    <table class="w-full min-w-[1180px] text-sm">
                        <thead class="bg-muted/60 text-left">
                            <tr>
                                <th class="px-4 py-3 font-medium">
                                    Inventar-Nr.
                                </th>
                                <th class="px-4 py-3 font-medium">
                                    Gegenstand
                                </th>
                                <th class="px-4 py-3 font-medium">Zuordnung</th>
                                <th class="px-4 py-3 font-medium">
                                    Anschaffung
                                </th>
                                <th class="px-4 py-3 font-medium">
                                    Abschreibung
                                </th>
                                <th class="px-4 py-3 text-right font-medium">
                                    Restwert
                                </th>
                                <th class="px-4 py-3 font-medium">Status</th>
                                <th class="px-4 py-3 text-right font-medium">
                                    Aktionen
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr
                                v-for="item in filteredItems"
                                :key="item.id"
                                class="align-top"
                            >
                                <td
                                    class="px-4 py-4 font-mono text-xs font-medium"
                                >
                                    {{ item.inventory_number }}
                                </td>
                                <td class="px-4 py-4">
                                    <p class="font-medium">{{ item.name }}</p>
                                    <p
                                        class="mt-1 text-xs text-muted-foreground"
                                    >
                                        {{ options.categories[item.category] }}
                                        <template v-if="item.serial_number">
                                            · S/N
                                            {{ item.serial_number }}</template
                                        >
                                    </p>
                                </td>
                                <td class="px-4 py-4">
                                    <p>{{ item.location }}</p>
                                    <p
                                        v-if="item.responsible_person"
                                        class="mt-1 text-xs text-muted-foreground"
                                    >
                                        {{ item.responsible_person }}
                                    </p>
                                </td>
                                <td class="px-4 py-4 whitespace-nowrap">
                                    <p>
                                        {{ formatDate(item.acquisition_date) }}
                                    </p>
                                    <p
                                        class="mt-1 text-xs text-muted-foreground"
                                    >
                                        {{
                                            formatMoney(
                                                item.acquisition_cost_cents,
                                            )
                                        }}
                                        ·
                                        {{
                                            options.acquisitionTypes[
                                                item.acquisition_type
                                            ]
                                        }}
                                    </p>
                                </td>
                                <td class="px-4 py-4">
                                    <p>
                                        {{
                                            options.depreciationMethods[
                                                item.depreciation_method
                                            ]
                                        }}
                                    </p>
                                    <p
                                        v-if="
                                            item.depreciation_method ===
                                            'linear'
                                        "
                                        class="mt-1 text-xs text-muted-foreground"
                                    >
                                        {{ item.useful_life_years }} Jahre ·
                                        {{
                                            formatMoney(
                                                item.annual_depreciation_cents,
                                            )
                                        }}/Jahr
                                    </p>
                                </td>
                                <td
                                    class="px-4 py-4 text-right font-medium whitespace-nowrap"
                                >
                                    {{ formatMoney(item.book_value_cents) }}
                                    <p
                                        v-if="item.status !== 'active'"
                                        class="mt-1 text-xs font-normal text-muted-foreground"
                                    >
                                        bei Abgang
                                    </p>
                                </td>
                                <td class="px-4 py-4">
                                    <Badge
                                        :variant="
                                            item.status === 'active'
                                                ? 'outline'
                                                : 'secondary'
                                        "
                                    >
                                        {{ options.statuses[item.status] }}
                                    </Badge>
                                    <p
                                        v-if="item.disposed_at"
                                        class="mt-1 text-xs text-muted-foreground"
                                    >
                                        {{ formatDate(item.disposed_at) }}
                                    </p>
                                </td>
                                <td class="px-4 py-4">
                                    <div class="flex justify-end gap-2">
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            :aria-label="`${item.inventory_number} Details anzeigen`"
                                            @click="showDetails(item)"
                                        >
                                            <Eye class="size-3.5" />Details
                                        </Button>
                                        <Button
                                            v-if="item.status === 'active'"
                                            variant="outline"
                                            size="sm"
                                            :aria-label="`${item.inventory_number} als Abgang erfassen`"
                                            @click="openDisposal(item)"
                                        >
                                            <ArchiveX class="size-3.5" />Abgang
                                        </Button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </template>

        <template v-else>
            <form class="space-y-6" @submit.prevent="store">
                <section class="rounded-xl border bg-card">
                    <div class="border-b px-5 py-4">
                        <h2 class="font-semibold">Gegenstand</h2>
                        <p class="mt-1 text-sm text-muted-foreground">
                            Die eindeutige Inventarnummer wird beim Speichern
                            automatisch vergeben.
                        </p>
                    </div>
                    <div class="grid gap-5 p-5 md:grid-cols-2">
                        <div class="space-y-2">
                            <Label for="inventory-name">Bezeichnung *</Label>
                            <Input
                                id="inventory-name"
                                v-model="createForm.name"
                                required
                                maxlength="255"
                                placeholder="z. B. Wettkampf-Trampolin"
                            />
                            <InputError :message="createForm.errors.name" />
                        </div>
                        <div class="space-y-2">
                            <Label for="inventory-category">Kategorie *</Label>
                            <select
                                id="inventory-category"
                                v-model="createForm.category"
                                required
                                class="h-9 w-full rounded-md border border-input bg-background px-3 text-sm shadow-xs"
                            >
                                <option
                                    v-for="(label, value) in options.categories"
                                    :key="value"
                                    :value="value"
                                >
                                    {{ label }}
                                </option>
                            </select>
                            <InputError :message="createForm.errors.category" />
                        </div>
                        <div class="space-y-2">
                            <Label for="inventory-manufacturer"
                                >Hersteller</Label
                            >
                            <Input
                                id="inventory-manufacturer"
                                v-model="createForm.manufacturer"
                                maxlength="255"
                            />
                            <InputError
                                :message="createForm.errors.manufacturer"
                            />
                        </div>
                        <div class="space-y-2">
                            <Label for="inventory-model">Modell/Typ</Label>
                            <Input
                                id="inventory-model"
                                v-model="createForm.model"
                                maxlength="255"
                            />
                            <InputError :message="createForm.errors.model" />
                        </div>
                        <div class="space-y-2">
                            <Label for="inventory-serial">Seriennummer</Label>
                            <Input
                                id="inventory-serial"
                                v-model="createForm.serial_number"
                                maxlength="255"
                            />
                            <InputError
                                :message="createForm.errors.serial_number"
                            />
                        </div>
                        <div class="space-y-2">
                            <Label for="inventory-location">Standort *</Label>
                            <Input
                                id="inventory-location"
                                v-model="createForm.location"
                                required
                                maxlength="255"
                                placeholder="z. B. Turnhalle, Geräteraum 1"
                            />
                            <InputError :message="createForm.errors.location" />
                        </div>
                        <div class="space-y-2 md:col-span-2">
                            <Label for="inventory-responsible"
                                >Verantwortliche Person/Bereich</Label
                            >
                            <Input
                                id="inventory-responsible"
                                v-model="createForm.responsible_person"
                                maxlength="255"
                            />
                            <InputError
                                :message="createForm.errors.responsible_person"
                            />
                        </div>
                        <div class="space-y-2 md:col-span-2">
                            <Label for="inventory-description"
                                >Beschreibung/Zustand</Label
                            >
                            <textarea
                                id="inventory-description"
                                v-model="createForm.description"
                                rows="3"
                                maxlength="2000"
                                class="w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                                placeholder="Merkmale und Zustand bei Aufnahme"
                            ></textarea>
                            <InputError
                                :message="createForm.errors.description"
                            />
                        </div>
                    </div>
                </section>

                <section class="rounded-xl border bg-card">
                    <div class="border-b px-5 py-4">
                        <h2 class="font-semibold">Anschaffung & Bewertung</h2>
                        <p class="mt-1 text-sm text-muted-foreground">
                            Grundlage für Anschaffungswert, Abschreibung und
                            aktuellen Restwert.
                        </p>
                    </div>
                    <div class="grid gap-5 p-5 md:grid-cols-2">
                        <div class="space-y-2">
                            <Label for="inventory-acquisition-type"
                                >Zugangsart *</Label
                            >
                            <select
                                id="inventory-acquisition-type"
                                v-model="createForm.acquisition_type"
                                required
                                class="h-9 w-full rounded-md border border-input bg-background px-3 text-sm shadow-xs"
                            >
                                <option
                                    v-for="(
                                        label, value
                                    ) in options.acquisitionTypes"
                                    :key="value"
                                    :value="value"
                                >
                                    {{ label }}
                                </option>
                            </select>
                            <InputError
                                :message="createForm.errors.acquisition_type"
                            />
                        </div>
                        <div class="space-y-2">
                            <Label for="inventory-acquisition-date"
                                >Anschaffungs-/Herstellungsdatum *</Label
                            >
                            <Input
                                id="inventory-acquisition-date"
                                v-model="createForm.acquisition_date"
                                type="date"
                                :max="today"
                                required
                            />
                            <InputError
                                :message="createForm.errors.acquisition_date"
                            />
                        </div>
                        <div class="space-y-2">
                            <Label for="inventory-cost"
                                >Anschaffungs-/Herstellungskosten *</Label
                            >
                            <div class="relative">
                                <Input
                                    id="inventory-cost"
                                    v-model="createForm.acquisition_cost"
                                    required
                                    inputmode="decimal"
                                    maxlength="12"
                                    class="pr-10"
                                    placeholder="0,00"
                                />
                                <span
                                    class="absolute top-2 right-3 text-sm text-muted-foreground"
                                    >€</span
                                >
                            </div>
                            <InputError
                                :message="createForm.errors.acquisition_cost"
                            />
                        </div>
                        <div class="space-y-2">
                            <Label for="inventory-document"
                                >Beleg/Referenz</Label
                            >
                            <Input
                                id="inventory-document"
                                v-model="createForm.document_reference"
                                maxlength="255"
                                placeholder="z. B. Rechnung RE-2026-184"
                            />
                            <InputError
                                :message="createForm.errors.document_reference"
                            />
                        </div>
                        <div class="space-y-2">
                            <Label for="inventory-depreciation"
                                >Abschreibung *</Label
                            >
                            <select
                                id="inventory-depreciation"
                                v-model="createForm.depreciation_method"
                                required
                                class="h-9 w-full rounded-md border border-input bg-background px-3 text-sm shadow-xs"
                            >
                                <option
                                    v-for="(
                                        label, value
                                    ) in options.depreciationMethods"
                                    :key="value"
                                    :value="value"
                                >
                                    {{ label }}
                                </option>
                            </select>
                            <InputError
                                :message="createForm.errors.depreciation_method"
                            />
                        </div>
                        <div
                            v-if="createForm.depreciation_method === 'linear'"
                            class="space-y-2"
                        >
                            <Label for="inventory-life"
                                >Nutzungsdauer in Jahren *</Label
                            >
                            <Input
                                id="inventory-life"
                                v-model="createForm.useful_life_years"
                                type="number"
                                min="1"
                                max="100"
                                step="1"
                                required
                            />
                            <InputError
                                :message="createForm.errors.useful_life_years"
                            />
                        </div>
                        <StatusAlert
                            type="info"
                            title="Hinweis zur Bewertung"
                            class="md:col-span-2"
                        >
                            Die lineare Abschreibung wird ab dem
                            Anschaffungsmonat monatsgenau berechnet. Die
                            Nutzungsdauer ist nach den tatsächlichen
                            Verhältnissen festzulegen; die amtlichen
                            <a
                                class="underline underline-offset-2"
                                href="https://www.bundesfinanzministerium.de/Web/DE/Themen/Steuern/Steuerverwaltungu-Steuerrecht/Betriebspruefung/AfA_Tabellen/afa_tabellen.html"
                                target="_blank"
                                rel="noopener noreferrer"
                                >AfA-Tabellen</a
                            >
                            dienen als Schätzhilfe. Eine Sofortabschreibung
                            sollte nur verwendet werden, wenn die jeweiligen
                            steuerlichen Voraussetzungen erfüllt sind.
                        </StatusAlert>
                    </div>
                </section>

                <div class="flex justify-end gap-3">
                    <Button type="button" variant="outline" as-child
                        ><Link href="/inventar">Abbrechen</Link></Button
                    >
                    <Button type="submit" :disabled="createForm.processing">
                        <Spinner v-if="createForm.processing" />Inventarisieren
                    </Button>
                </div>
            </form>
        </template>
    </div>

    <Dialog v-model:open="detailsOpen">
        <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
            <template v-if="selected">
                <DialogHeader>
                    <DialogTitle>{{ selected.name }}</DialogTitle>
                    <DialogDescription class="font-mono">
                        {{ selected.inventory_number }}
                    </DialogDescription>
                </DialogHeader>
                <dl class="grid gap-x-6 gap-y-4 text-sm sm:grid-cols-2">
                    <div>
                        <dt class="text-muted-foreground">Kategorie</dt>
                        <dd class="font-medium">
                            {{ options.categories[selected.category] }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground">Status</dt>
                        <dd class="font-medium">
                            {{ options.statuses[selected.status] }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground">
                            Hersteller / Modell
                        </dt>
                        <dd class="font-medium">
                            {{
                                [selected.manufacturer, selected.model]
                                    .filter(Boolean)
                                    .join(' · ') || '–'
                            }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground">Seriennummer</dt>
                        <dd class="font-medium">
                            {{ selected.serial_number || '–' }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground">Standort</dt>
                        <dd class="font-medium">{{ selected.location }}</dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground">Verantwortlich</dt>
                        <dd class="font-medium">
                            {{ selected.responsible_person || '–' }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground">Anschaffung</dt>
                        <dd class="font-medium">
                            {{ formatDate(selected.acquisition_date) }} ·
                            {{ formatMoney(selected.acquisition_cost_cents) }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground">Beleg/Referenz</dt>
                        <dd class="font-medium">
                            {{ selected.document_reference || '–' }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground">Abschreibung</dt>
                        <dd class="font-medium">
                            {{
                                options.depreciationMethods[
                                    selected.depreciation_method
                                ]
                            }}
                            <template v-if="selected.useful_life_years">
                                ·
                                {{ selected.useful_life_years }} Jahre</template
                            >
                        </dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground">
                            {{
                                selected.status === 'active'
                                    ? 'Aktueller Restwert'
                                    : 'Restwert bei Abgang'
                            }}
                        </dt>
                        <dd class="font-medium">
                            {{ formatMoney(selected.book_value_cents) }}
                        </dd>
                    </div>
                    <div v-if="selected.description" class="sm:col-span-2">
                        <dt class="text-muted-foreground">
                            Beschreibung/Zustand
                        </dt>
                        <dd class="mt-1 whitespace-pre-wrap">
                            {{ selected.description }}
                        </dd>
                    </div>
                    <template v-if="selected.status !== 'active'">
                        <div>
                            <dt class="text-muted-foreground">Abgang am</dt>
                            <dd class="font-medium">
                                {{ formatDate(selected.disposed_at) }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground">Verkaufserlös</dt>
                            <dd class="font-medium">
                                {{
                                    selected.disposal_proceeds_cents === null
                                        ? '–'
                                        : formatMoney(
                                              selected.disposal_proceeds_cents,
                                          )
                                }}
                            </dd>
                        </div>
                        <div
                            v-if="selected.disposal_note"
                            class="sm:col-span-2"
                        >
                            <dt class="text-muted-foreground">
                                Abgangsvermerk
                            </dt>
                            <dd class="mt-1 whitespace-pre-wrap">
                                {{ selected.disposal_note }}
                            </dd>
                        </div>
                    </template>
                </dl>
            </template>
        </DialogContent>
    </Dialog>

    <Dialog :open="disposalOpen" @update:open="closeDisposal">
        <DialogContent class="sm:max-w-lg">
            <DialogHeader>
                <DialogTitle>Abgang erfassen</DialogTitle>
                <DialogDescription v-if="retiring">
                    {{ retiring.inventory_number }} · {{ retiring.name }} bleibt
                    mit seinen Abgangsdaten im Inventar erhalten.
                </DialogDescription>
            </DialogHeader>
            <form class="space-y-4" @submit.prevent="dispose">
                <InputError
                    :message="disposalForm.errors.status"
                    role="alert"
                />
                <div class="space-y-2">
                    <Label for="disposal-status">Art des Abgangs *</Label>
                    <select
                        id="disposal-status"
                        v-model="disposalForm.status"
                        required
                        class="h-9 w-full rounded-md border border-input bg-background px-3 text-sm shadow-xs"
                    >
                        <option value="sold">Verkauft</option>
                        <option value="lost">Verlust</option>
                        <option value="disposed">Entsorgt</option>
                    </select>
                </div>
                <div class="space-y-2">
                    <Label for="disposal-date">Abgangsdatum *</Label>
                    <Input
                        id="disposal-date"
                        v-model="disposalForm.disposed_at"
                        type="date"
                        :min="retiring?.acquisition_date"
                        :max="today"
                        required
                    />
                    <InputError :message="disposalForm.errors.disposed_at" />
                </div>
                <div v-if="disposalForm.status === 'sold'" class="space-y-2">
                    <Label for="disposal-proceeds">Verkaufserlös *</Label>
                    <div class="relative">
                        <Input
                            id="disposal-proceeds"
                            v-model="disposalForm.disposal_proceeds"
                            inputmode="decimal"
                            maxlength="12"
                            required
                            class="pr-10"
                            placeholder="0,00"
                        />
                        <span
                            class="absolute top-2 right-3 text-sm text-muted-foreground"
                            >€</span
                        >
                    </div>
                    <InputError
                        :message="disposalForm.errors.disposal_proceeds"
                    />
                </div>
                <div class="space-y-2">
                    <Label for="disposal-note">Vermerk</Label>
                    <textarea
                        id="disposal-note"
                        v-model="disposalForm.disposal_note"
                        rows="3"
                        maxlength="2000"
                        class="w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                        placeholder="z. B. Käufer, Schadenshergang oder Entsorgungsnachweis"
                    ></textarea>
                    <InputError :message="disposalForm.errors.disposal_note" />
                </div>
                <DialogFooter>
                    <Button
                        type="button"
                        variant="outline"
                        @click="closeDisposal(false)"
                        >Abbrechen</Button
                    >
                    <Button type="submit" :disabled="disposalForm.processing">
                        <Spinner v-if="disposalForm.processing" />Abgang
                        speichern
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
