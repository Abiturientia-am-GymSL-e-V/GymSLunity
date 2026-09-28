<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { Download, Plus, RotateCcw, Search, X } from '@lucide/vue';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import SearchableDropdown from '@/components/SearchableDropdown.vue';
import StatusAlert from '@/components/StatusAlert.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import type { MemberField } from '@/types/members';
import { saveBlob, xsrfToken } from '@/lib/download';

type Member = {
    member_number: number;
    name: string;
    email: string | null;
    mobile_phone: string | null;
    status: 'active' | 'contacts' | 'former' | 'future' | 'other';
    filter_values: Record<string, string | number | boolean | null>;
};
type Column = { key: string; label: string };
type FilterField = Pick<MemberField, 'key' | 'label' | 'type' | 'options'>;
type AvailableFilterField = Omit<FilterField, 'type'> & {
    type: FilterField['type'] | 'status';
};
type ActiveFilter = {
    id: number;
    key: string;
    value: string;
    valueTo: string;
};
const props = defineProps<{
    members: Member[];
    columns: Column[];
    filterFields: FilterField[];
}>();
defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Formulare', href: '/formulare' },
            { title: 'Unterschriftslisten' },
        ],
    },
});

const title = ref('Unterschriftsliste');
const eventDate = ref('');
const search = ref('');
const filterFieldToAdd = ref('');
let nextFilterId = 2;
const activeFilters = ref<ActiveFilter[]>([
    { id: 1, key: '__status', value: 'active', valueTo: '' },
]);
const selected = ref<number[]>([]);
const selectedColumns = ref<string[]>(
    ['member_number', 'first_name', 'last_name', 'signature'].filter((key) =>
        props.columns.some((column) => column.key === key),
    ),
);
const columnToAdd = ref('');
const processing = ref(false);
const error = ref('');
const normalizedSearch = computed(() =>
    search.value.toLocaleLowerCase('de').trim(),
);
const statusField: AvailableFilterField = {
    key: '__status',
    label: 'Mitgliedsstatus',
    type: 'status',
    options: {
        active: 'Aktive Mitglieder',
        contacts: 'Kontakte',
        former: 'Ausgetreten / verstorben',
        future: 'Künftige Eintritte',
        all: 'Alle Datensätze',
    },
};
const availableFields = computed<AvailableFilterField[]>(() => [
    statusField,
    ...props.filterFields,
]);
const fieldsToAdd = computed(() =>
    availableFields.value.filter(
        (field) =>
            !activeFilters.value.some((filter) => filter.key === field.key),
    ),
);
const filterFieldOptions = computed(() => [
    { value: '', label: 'Feld auswählen' },
    ...fieldsToAdd.value.map((field) => ({
        value: field.key,
        label: field.label,
    })),
]);
const columnsToAdd = computed(() =>
    props.columns.filter(
        (column) => !selectedColumns.value.includes(column.key),
    ),
);
const columnOptions = computed(() => [
    { value: '', label: 'Spalte auswählen' },
    ...columnsToAdd.value.map((column) => ({
        value: column.key,
        label: column.label,
    })),
]);
const filteredMembers = computed(() =>
    props.members.filter(
        (member) =>
            (!normalizedSearch.value ||
                `${member.member_number} ${member.name} ${member.email ?? ''} ${member.mobile_phone ?? ''}`
                    .toLocaleLowerCase('de')
                    .includes(normalizedSearch.value)) &&
            activeFilters.value.every((filter) =>
                matchesFilter(member, filter),
            ),
    ),
);
function filterField(key: string) {
    return availableFields.value.find((field) => field.key === key);
}
function valueOptions(field: AvailableFilterField) {
    if (field.type === 'boolean') {
        return [
            { value: '1', label: 'Ja' },
            { value: '0', label: 'Nein' },
        ];
    }
    return Object.entries(field.options).map(([value, label]) => ({
        value,
        label,
    }));
}
function valueOptionsFor(key: string) {
    const field = filterField(key);
    return field ? valueOptions(field) : [];
}
function matchesFilter(member: Member, filter: ActiveFilter) {
    const field = filterField(filter.key);
    if (!field) return true;
    if (field.type === 'status') {
        return (
            !filter.value ||
            filter.value === 'all' ||
            member.status === filter.value
        );
    }

    const actual = member.filter_values[field.key];
    if (field.type === 'date') {
        if (actual === null || actual === undefined || actual === '') {
            return !filter.value && !filter.valueTo;
        }
        const normalizedActual = String(actual);
        return (
            (!filter.value || normalizedActual >= filter.value) &&
            (!filter.valueTo || normalizedActual <= filter.valueTo)
        );
    }
    if (['number', 'decimal'].includes(field.type)) {
        if (actual === null || actual === undefined || actual === '') {
            return !filter.value && !filter.valueTo;
        }
        const normalizedActual = Number(actual);
        return (
            (!filter.value || normalizedActual >= Number(filter.value)) &&
            (!filter.valueTo || normalizedActual <= Number(filter.valueTo))
        );
    }
    if (!filter.value) return true;
    if (field.type === 'boolean') {
        return (
            (actual === true ? '1' : actual === false ? '0' : '') ===
            filter.value
        );
    }
    if (field.type === 'select') return String(actual ?? '') === filter.value;
    return String(actual ?? '')
        .toLocaleLowerCase('de')
        .includes(filter.value.toLocaleLowerCase('de').trim());
}
const hasFilters = computed(
    () =>
        search.value !== '' ||
        activeFilters.value.length !== 1 ||
        activeFilters.value[0]?.key !== '__status' ||
        activeFilters.value[0]?.value !== 'active',
);
function addFilter() {
    const field = filterField(filterFieldToAdd.value);
    if (!field || !fieldsToAdd.value.some((item) => item.key === field.key)) {
        return;
    }
    activeFilters.value.push({
        id: nextFilterId++,
        key: field.key,
        value: field.type === 'status' ? 'active' : '',
        valueTo: '',
    });
    filterFieldToAdd.value = '';
}
function removeFilter(id: number) {
    activeFilters.value = activeFilters.value.filter(
        (filter) => filter.id !== id,
    );
}
function resetFilters() {
    search.value = '';
    activeFilters.value = [
        { id: nextFilterId++, key: '__status', value: 'active', valueTo: '' },
    ];
    filterFieldToAdd.value = '';
}
const allFilteredSelected = computed(
    () =>
        filteredMembers.value.length > 0 &&
        filteredMembers.value.every((member) =>
            selected.value.includes(member.member_number),
        ),
);

function toggleFiltered() {
    const numbers = filteredMembers.value.map((member) => member.member_number);
    selected.value = allFilteredSelected.value
        ? selected.value.filter((number) => !numbers.includes(number))
        : [...new Set([...selected.value, ...numbers])];
}
function toggleMember(number: number, checked: boolean) {
    selected.value = checked
        ? [...new Set([...selected.value, number])]
        : selected.value.filter((value) => value !== number);
}
function columnLabel(key: string) {
    return props.columns.find((column) => column.key === key)?.label ?? key;
}
function addColumn() {
    if (
        !columnToAdd.value ||
        selectedColumns.value.length >= 12 ||
        !columnsToAdd.value.some((column) => column.key === columnToAdd.value)
    ) {
        return;
    }
    selectedColumns.value.push(columnToAdd.value);
    columnToAdd.value = '';
}
function removeColumn(key: string) {
    selectedColumns.value = selectedColumns.value.filter(
        (value) => value !== key,
    );
}
async function generate() {
    error.value = '';
    if (
        !selected.value.length ||
        !selectedColumns.value.length ||
        !title.value.trim()
    ) {
        error.value =
            'Bitte Titel, mindestens ein Mitglied und mindestens eine Spalte auswählen.';
        return;
    }
    processing.value = true;
    try {
        const response = await fetch('/formulare/unterschriftslisten/pdf', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/pdf',
                'X-XSRF-TOKEN': xsrfToken(),
            },
            body: JSON.stringify({
                title: title.value,
                event_date: eventDate.value || null,
                member_numbers: selected.value,
                columns: selectedColumns.value,
            }),
        });
        if (!response.ok) {
            const payload = await response.json().catch(() => null);
            error.value =
                payload?.message ??
                'Die Unterschriftsliste konnte nicht erstellt werden.';
            return;
        }
        saveBlob(
            await response.blob(),
            `Unterschriftsliste_${new Date().toISOString().slice(0, 10)}.pdf`,
        );
    } finally {
        processing.value = false;
    }
}
</script>

<template>
    <Head title="Unterschriftslisten" />
    <div class="mx-auto w-full max-w-[1200px] space-y-6 p-4 sm:p-6">
        <header>
            <h1 class="text-2xl font-semibold tracking-tight">
                Unterschriftslisten
            </h1>
            <p class="mt-1 text-sm text-muted-foreground">
                Mitglieder und Spalten auswählen und eine druckfertige PDF-Liste
                erzeugen.
            </p>
        </header>
        <section class="rounded-xl border bg-card">
            <div class="border-b px-5 py-4">
                <h2 class="font-semibold">Listenkopf & Spalten</h2>
            </div>
            <div class="grid gap-5 p-5 md:grid-cols-2">
                <div class="space-y-2">
                    <Label for="list-title">Titel *</Label
                    ><Input id="list-title" v-model="title" maxlength="150" />
                </div>
                <div class="space-y-2">
                    <Label for="event-date">Datum (optional)</Label
                    ><Input id="event-date" v-model="eventDate" type="date" />
                </div>
                <fieldset class="space-y-3 md:col-span-2">
                    <legend class="text-sm font-medium">
                        Spalten der Liste *
                    </legend>
                    <p class="text-xs text-muted-foreground">
                        Spalten einzeln aus den verfügbaren Mitgliedsfeldern
                        hinzufügen. Die angezeigte Reihenfolge wird für das PDF
                        übernommen.
                    </p>
                    <div
                        v-if="selectedColumns.length"
                        class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3"
                    >
                        <div
                            v-for="columnKey in selectedColumns"
                            :key="columnKey"
                            class="flex min-w-0 items-center justify-between gap-2 rounded-lg border bg-background px-3 py-2"
                        >
                            <span class="min-w-0 truncate text-sm">
                                {{ columnLabel(columnKey) }}
                            </span>
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon-sm"
                                :aria-label="`${columnLabel(columnKey)} entfernen`"
                                @click="removeColumn(columnKey)"
                            >
                                <X class="size-4" />
                            </Button>
                        </div>
                    </div>
                    <p v-else class="text-sm text-muted-foreground">
                        Noch keine Listenspalte ausgewählt.
                    </p>
                    <div
                        v-if="
                            columnsToAdd.length && selectedColumns.length < 12
                        "
                        class="grid min-w-0 gap-3 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-end"
                    >
                        <div class="min-w-0 space-y-2">
                            <Label for="signature-column-to-add">
                                Listenspalte
                            </Label>
                            <SearchableDropdown
                                id="signature-column-to-add"
                                v-model="columnToAdd"
                                :options="columnOptions"
                                placeholder="Spalte auswählen"
                                search-placeholder="Mitgliedsfeld suchen"
                                empty-text="Keine weitere Spalte gefunden."
                                aria-label="Listenspalte auswählen"
                                trigger-class="h-9 w-full rounded-md border border-input bg-background px-3"
                            />
                        </div>
                        <Button
                            type="button"
                            variant="outline"
                            :disabled="!columnToAdd"
                            @click="addColumn"
                        >
                            <Plus class="size-4" />
                            Spalte hinzufügen
                        </Button>
                    </div>
                    <p class="text-xs text-muted-foreground">
                        {{ selectedColumns.length }} von maximal 12 Spalten
                        ausgewählt.
                    </p>
                </fieldset>
            </div>
        </section>
        <section class="rounded-xl border bg-card">
            <div
                class="flex flex-wrap items-end justify-between gap-4 border-b px-5 py-4"
            >
                <div>
                    <h2 class="font-semibold">Mitglieder</h2>
                    <p class="mt-1 text-sm text-muted-foreground">
                        {{ selected.length }} von
                        {{ members.length }} ausgewählt ·
                        {{ filteredMembers.length }} Treffer
                    </p>
                </div>
                <div class="relative w-full sm:w-80">
                    <Label for="signature-member-search" class="sr-only"
                        >Mitglieder suchen</Label
                    >
                    <Search
                        class="pointer-events-none absolute top-2.5 left-3 size-4 text-muted-foreground"
                    /><Input
                        id="signature-member-search"
                        v-model="search"
                        type="search"
                        class="pl-9"
                        placeholder="Name, Nummer, E-Mail oder Telefon suchen"
                    />
                </div>
            </div>
            <div class="space-y-4 border-b bg-muted/20 p-4">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h3 class="text-sm font-medium">Auswahl filtern</h3>
                        <p class="mt-1 text-xs text-muted-foreground">
                            Filter lassen sich einzeln aus den verfügbaren
                            Mitgliedsfeldern hinzufügen.
                        </p>
                    </div>
                    <Button
                        v-if="hasFilters"
                        type="button"
                        variant="ghost"
                        size="sm"
                        @click="resetFilters"
                    >
                        <RotateCcw class="size-4" />
                        Zurücksetzen
                    </Button>
                </div>
                <div
                    v-if="activeFilters.length"
                    class="grid min-w-0 gap-4 md:grid-cols-2 xl:grid-cols-3"
                >
                    <div
                        v-for="filterItem in activeFilters"
                        :key="filterItem.id"
                        class="min-w-0 space-y-3 rounded-lg border bg-background p-4"
                    >
                        <div
                            class="flex min-w-0 items-center justify-between gap-2"
                        >
                            <Label
                                v-if="
                                    !['date', 'number', 'decimal'].includes(
                                        filterField(filterItem.key)?.type || '',
                                    )
                                "
                                :for="`signature-filter-${filterItem.id}`"
                                class="min-w-0 truncate"
                            >
                                {{ filterField(filterItem.key)?.label }}
                            </Label>
                            <span
                                v-else
                                class="min-w-0 truncate text-sm font-medium"
                            >
                                {{ filterField(filterItem.key)?.label }}
                            </span>
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon-sm"
                                :aria-label="`${filterField(filterItem.key)?.label} entfernen`"
                                @click="removeFilter(filterItem.id)"
                            >
                                <X class="size-4" />
                            </Button>
                        </div>
                        <SearchableDropdown
                            v-if="
                                ['select', 'boolean', 'status'].includes(
                                    filterField(filterItem.key)?.type || '',
                                )
                            "
                            :id="`signature-filter-${filterItem.id}`"
                            v-model="filterItem.value"
                            :options="valueOptionsFor(filterItem.key)"
                            placeholder="Wert auswählen"
                            search-placeholder="Wert suchen"
                            empty-text="Kein Wert gefunden."
                            :aria-label="filterField(filterItem.key)?.label"
                            trigger-class="h-9 w-full rounded-md border border-input bg-background px-3"
                        />
                        <div
                            v-else-if="
                                ['date', 'number', 'decimal'].includes(
                                    filterField(filterItem.key)?.type || '',
                                )
                            "
                            class="grid min-w-0 gap-3 sm:grid-cols-2"
                        >
                            <div class="min-w-0 space-y-2">
                                <Label
                                    :for="`signature-filter-${filterItem.id}-from`"
                                    >Von</Label
                                >
                                <Input
                                    :id="`signature-filter-${filterItem.id}-from`"
                                    v-model="filterItem.value"
                                    :type="
                                        filterField(filterItem.key)?.type ===
                                        'date'
                                            ? 'date'
                                            : 'number'
                                    "
                                    :step="
                                        filterField(filterItem.key)?.type ===
                                        'decimal'
                                            ? '0.01'
                                            : undefined
                                    "
                                    :max="filterItem.valueTo || undefined"
                                />
                            </div>
                            <div class="min-w-0 space-y-2">
                                <Label
                                    :for="`signature-filter-${filterItem.id}-to`"
                                    >Bis</Label
                                >
                                <Input
                                    :id="`signature-filter-${filterItem.id}-to`"
                                    v-model="filterItem.valueTo"
                                    :type="
                                        filterField(filterItem.key)?.type ===
                                        'date'
                                            ? 'date'
                                            : 'number'
                                    "
                                    :step="
                                        filterField(filterItem.key)?.type ===
                                        'decimal'
                                            ? '0.01'
                                            : undefined
                                    "
                                    :min="filterItem.value || undefined"
                                />
                            </div>
                        </div>
                        <Input
                            v-else
                            :id="`signature-filter-${filterItem.id}`"
                            v-model="filterItem.value"
                            type="search"
                            placeholder="Suchwert eingeben"
                        />
                    </div>
                </div>
                <p v-else class="text-sm text-muted-foreground">
                    Es ist kein Filter aktiv. Alle Mitglieder werden angezeigt.
                </p>
                <div
                    v-if="fieldsToAdd.length"
                    class="grid min-w-0 gap-3 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-end"
                >
                    <div class="min-w-0 space-y-2">
                        <Label for="signature-filter-to-add">Filterfeld</Label>
                        <SearchableDropdown
                            id="signature-filter-to-add"
                            v-model="filterFieldToAdd"
                            :options="filterFieldOptions"
                            placeholder="Feld auswählen"
                            search-placeholder="Mitgliedsfeld suchen"
                            empty-text="Kein weiteres Feld gefunden."
                            aria-label="Filterfeld auswählen"
                            trigger-class="h-9 w-full rounded-md border border-input bg-background px-3"
                        />
                    </div>
                    <Button
                        type="button"
                        variant="outline"
                        :disabled="!filterFieldToAdd"
                        @click="addFilter"
                    >
                        <Plus class="size-4" />
                        Filter hinzufügen
                    </Button>
                </div>
                <p v-else class="text-xs text-muted-foreground">
                    Alle verfügbaren Filter wurden hinzugefügt.
                </p>
            </div>
            <div class="max-h-[32rem] overflow-auto">
                <table class="w-full text-sm">
                    <thead class="sticky top-0 bg-muted/95">
                        <tr class="border-b">
                            <th class="w-12 px-4 py-3 text-left">
                                <Checkbox
                                    :model-value="allFilteredSelected"
                                    aria-label="Alle Treffer auswählen"
                                    @update:model-value="toggleFiltered"
                                />
                            </th>
                            <th class="px-4 py-3 text-left font-medium">
                                Mitglied
                            </th>
                            <th
                                class="hidden px-4 py-3 text-left font-medium md:table-cell"
                            >
                                Kontakt
                            </th>
                            <th class="px-4 py-3 text-right font-medium">
                                Nr.
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="member in filteredMembers"
                            :key="member.member_number"
                            class="border-b last:border-0"
                        >
                            <td class="px-4 py-3">
                                <Checkbox
                                    :model-value="
                                        selected.includes(member.member_number)
                                    "
                                    :aria-label="`${member.name} auswählen`"
                                    @update:model-value="
                                        toggleMember(
                                            member.member_number,
                                            Boolean($event),
                                        )
                                    "
                                />
                            </td>
                            <td class="px-4 py-3 font-medium">
                                {{ member.name }}
                            </td>
                            <td
                                class="hidden px-4 py-3 text-muted-foreground md:table-cell"
                            >
                                {{ member.email || member.mobile_phone || '—' }}
                            </td>
                            <td class="px-4 py-3 text-right font-mono">
                                {{ member.member_number }}
                            </td>
                        </tr>
                    </tbody>
                </table>
                <p
                    v-if="!filteredMembers.length"
                    class="p-8 text-center text-sm text-muted-foreground"
                >
                    Keine Mitglieder gefunden.
                </p>
            </div>
        </section>
        <StatusAlert v-if="error" type="error" title="PDF nicht erstellt"
            ><InputError :message="error"
        /></StatusAlert>
        <div class="flex justify-end">
            <Button
                :disabled="
                    processing || !selected.length || !selectedColumns.length
                "
                @click="generate"
                ><Spinner v-if="processing" /><Download
                    v-else
                    class="size-4"
                />PDF erstellen ({{ selected.length }})</Button
            >
        </div>
    </div>
</template>
