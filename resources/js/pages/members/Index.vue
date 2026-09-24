<script setup lang="ts">
import { Head, router, useRemember } from '@inertiajs/vue3';
import {
    ChevronLeft,
    ChevronRight,
    Columns3,
    RotateCcw,
    Search,
    SlidersHorizontal,
    UsersRound,
    X,
} from '@lucide/vue';
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    reactive,
    ref,
    unref,
    watch,
} from 'vue';
import { columnsFor } from '@/components/members/columns';
import type { MemberColumnKey } from '@/components/members/columns';
import BulkEditMembers from '@/components/members/BulkEditMembers.vue';
import MemberExport from '@/components/members/MemberExport.vue';
import MemberFilter from '@/components/members/MemberFilter.vue';
import MembersNav from '@/components/members/MembersNav.vue';
import MemberTable from '@/components/members/MemberTable.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuCheckboxItem,
    DropdownMenuContent,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { index } from '@/routes/members';
import { restoreMemberList } from '@/lib/memberNavigation';
import type {
    MemberFilterKey,
    MemberField,
    MemberFilterOptions,
    MemberFilters,
    MemberPage,
    MemberSort,
} from '@/types/members';

const props = defineProps<{
    members: MemberPage;
    filters: MemberFilters;
    filterOptions: MemberFilterOptions;
    totalMembers: number;
    fieldDefinitions: MemberField[];
    configurationVersion: number;
    canBulkEdit: boolean;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Mitglieder', href: index() },
            { title: 'Verzeichnis' },
        ],
    },
});

const memberColumns = computed(() => columnsFor(props.fieldDefinitions));
const customFilters = computed(() =>
    props.fieldDefinitions.filter((field) => field.custom && field.filterable),
);
const selection = reactive(
    unref(
        useRemember(
            { numbers: [] as number[], filterKey: '' },
            'members.selection',
        ),
    ),
);
const selectionFilterKey = computed(() =>
    JSON.stringify([
        props.filters.q,
        props.filters.membership,
        props.filters.department_role,
        props.filters.club_role,
        props.filters.custom,
    ]),
);
watch(
    selectionFilterKey,
    (key) => {
        if (selection.filterKey !== key) {
            selection.numbers = [];
            selection.filterKey = key;
        }
    },
    { immediate: true },
);
function selectMember(number: number, checked: boolean) {
    selection.numbers = checked
        ? [...new Set([...selection.numbers, number])]
        : selection.numbers.filter((value) => value !== number);
}
function selectPage(checked: boolean) {
    for (const member of props.members.data)
        selectMember(member.member_number, checked);
}
function fieldOptions(key: string) {
    return (
        props.fieldDefinitions.find((field) => field.key === key)?.options || {}
    );
}

const draft = reactive<MemberFilters>({
    ...props.filters,
    custom: { ...props.filters.custom },
});
const loading = ref(false);
const error = ref('');
let timer: ReturnType<typeof setTimeout> | undefined;
let cancelVisit: (() => void) | undefined;
let visitSequence = 0;
const number = new Intl.NumberFormat('de-DE');
const filterLabels: Record<MemberFilterKey, string> = {
    q: 'Suche',
    membership: 'Mitgliedschaft',
    department_role: 'Abteilung',
    club_role: 'Hauptverein',
};
const activeFilters = computed(() => [
    ...(Object.keys(filterLabels) as MemberFilterKey[])
        .filter((key) => draft[key] !== '')
        .map((key) => ({
            key,
            label: filterLabels[key],
            value:
                draft[key] === '__none__'
                    ? 'Ohne Funktion'
                    : draft[key] === '__any__'
                      ? 'Mit Funktion'
                      : fieldOptions(
                            key === 'membership' ? 'membership_type' : key,
                        )[draft[key]] || draft[key],
        })),
    ...Object.entries(draft.custom)
        .filter(([, value]) => value !== '')
        .map(([key, value]) => {
            const field = props.fieldDefinitions.find(
                (field) => field.key === key,
            );
            return {
                key: `custom:${key}`,
                label: field?.label || key,
                value:
                    field?.type === 'boolean'
                        ? value === '1'
                            ? 'Ja'
                            : 'Nein'
                        : field?.options[value] || value,
            };
        }),
]);
const hasFilters = computed(() => activeFilters.value.length > 0);

function cancelPending() {
    clearTimeout(timer);
    cancelVisit?.();
    cancelVisit = undefined;
}

function visit(page = 1, replace = false) {
    cancelPending();
    const sequence = ++visitSequence;
    const query = Object.fromEntries(
        Object.entries({ ...draft, page }).filter(([, value]) => value !== ''),
    );
    error.value = '';
    loading.value = true;
    router.get(index.url(), query, {
        preserveState: true,
        preserveScroll: true,
        replace,
        only: [
            'members',
            'filters',
            'totalMembers',
            'filterOptions',
            'fieldDefinitions',
        ],
        onCancelToken: (token) => {
            cancelVisit = () => token.cancel();
        },
        onError: () => {
            error.value =
                'Die Filter konnten nicht angewendet werden. Bitte prüfe deine Eingaben.';
        },
        onFinish: () => {
            if (sequence === visitSequence) {
                loading.value = false;
                cancelVisit = undefined;
            }
        },
    });
}

function search(value: string | number) {
    cancelPending();
    draft.q = String(value);
    timer = setTimeout(() => visit(1, true), 300);
}

function filter(key: string, value: string) {
    if (key.startsWith('custom:')) {
        const field = key.slice(7);
        if (value) draft.custom[field] = value;
        else delete draft.custom[field];
    } else draft[key as MemberFilterKey] = value;
    visit();
}

function reset() {
    for (const key of Object.keys(filterLabels) as MemberFilterKey[])
        draft[key] = '';
    draft.custom = {};
    visit();
}

function sort(key: MemberSort) {
    draft.direction =
        draft.sort === key && draft.direction === 'asc' ? 'desc' : 'asc';
    draft.sort = key;
    visit();
}

watch(
    () => props.filters,
    (filters) => {
        clearTimeout(timer);
        Object.assign(draft, filters, { custom: { ...filters.custom } });
    },
);
onBeforeUnmount(cancelPending);

const selectedColumns = ref<MemberColumnKey[]>(
    memberColumns.value
        .filter((column) => column.required || column.defaultVisible)
        .map((column) => column.key),
);
const visibleColumns = computed(() =>
    memberColumns.value.filter((column) =>
        selectedColumns.value.includes(column.key),
    ),
);
const storageKey = 'gymslunity.members.columns.v1';
onMounted(() => {
    try {
        const stored: unknown = JSON.parse(
            localStorage.getItem(storageKey) || 'null',
        );
        if (Array.isArray(stored))
            selectedColumns.value = memberColumns.value
                .filter(
                    (column) => column.required || stored.includes(column.key),
                )
                .map((column) => column.key);
    } catch {
        /* Browser storage may be unavailable. Keep the default columns. */
    }
    void nextTick(restoreMemberList);
});
function toggleColumn(key: MemberColumnKey, visible: boolean) {
    selectedColumns.value = memberColumns.value
        .filter(
            (column) =>
                column.required ||
                (column.key === key
                    ? visible
                    : selectedColumns.value.includes(column.key)),
        )
        .map((column) => column.key);
    try {
        localStorage.setItem(storageKey, JSON.stringify(selectedColumns.value));
    } catch {
        /* Preferences are optional. */
    }
}

const pages = computed(() => {
    const last = props.members.last_page;
    const current = props.members.current_page;
    const numbers = [...new Set([1, last, current - 1, current, current + 1])]
        .filter((page) => page >= 1 && page <= last)
        .sort((a, b) => a - b);
    const result: (number | string)[] = [];
    for (const page of numbers) {
        const previous = result.at(-1);
        if (typeof previous === 'number' && page - previous > 1)
            result.push(`gap-${page}`);
        result.push(page);
    }
    return result;
});
</script>

<template>
    <Head title="Mitglieder" />
    <div class="mx-auto w-full max-w-[1200px] space-y-6 p-4 sm:p-6">
        <header class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <div class="flex items-center gap-3">
                    <h1 class="text-2xl font-semibold tracking-tight">
                        Mitglieder
                    </h1>
                    <Badge variant="secondary" class="tabular-nums">{{
                        number.format(totalMembers)
                    }}</Badge>
                </div>
                <p class="mt-1 text-sm text-muted-foreground">
                    Kontaktdaten, Mitgliedschaften und Vereinsfunktionen im
                    Überblick.
                </p>
            </div>
            <div
                class="flex items-center gap-2 rounded-lg bg-muted/50 px-3 py-2 text-xs text-muted-foreground"
            >
                <UsersRound class="size-4" aria-hidden="true" />
                Mitgliederverzeichnis
            </div>
        </header>

        <MembersNav />

        <section
            class="rounded-xl border bg-card p-4 sm:p-5"
            aria-label="Mitglieder suchen und filtern"
        >
            <div class="flex flex-wrap items-center gap-3">
                <div class="relative min-w-0 flex-1 basis-72 sm:max-w-md">
                    <Label for="member-search" class="sr-only"
                        >Mitglieder suchen</Label
                    >
                    <Search
                        class="pointer-events-none absolute top-2.5 left-3 size-4 text-muted-foreground"
                        aria-hidden="true"
                    />
                    <Input
                        id="member-search"
                        :model-value="draft.q"
                        type="search"
                        maxlength="120"
                        autocomplete="off"
                        placeholder="Name, Nr., E-Mail oder Ort suchen …"
                        class="pr-9 pl-9 [&::-webkit-search-cancel-button]:hidden"
                        @update:model-value="search"
                        @keydown.enter.prevent="visit(1, true)"
                    />
                    <button
                        v-if="draft.q"
                        type="button"
                        class="absolute top-2 right-2 rounded p-0.5 text-muted-foreground hover:text-foreground"
                        aria-label="Suche löschen"
                        @click="filter('q', '')"
                    >
                        <X class="size-4" />
                    </button>
                </div>
                <div class="ml-auto flex items-center gap-2">
                    <Button
                        v-if="hasFilters"
                        variant="ghost"
                        size="sm"
                        data-test="reset-filters"
                        @click="reset"
                        ><RotateCcw class="size-3.5" aria-hidden="true" />
                        Zurücksetzen</Button
                    >
                    <DropdownMenu>
                        <DropdownMenuTrigger as-child
                            ><Button
                                variant="outline"
                                size="sm"
                                data-test="member-columns"
                                ><Columns3 class="size-4" aria-hidden="true" />
                                Spalten</Button
                            ></DropdownMenuTrigger
                        >
                        <DropdownMenuContent
                            align="end"
                            class="max-h-[70vh] w-60 overflow-auto"
                        >
                            <DropdownMenuLabel
                                >Sichtbare Spalten</DropdownMenuLabel
                            >
                            <DropdownMenuSeparator />
                            <DropdownMenuCheckboxItem
                                v-for="column in memberColumns"
                                :key="column.key"
                                :model-value="
                                    selectedColumns.includes(column.key)
                                "
                                :disabled="column.required"
                                @update:model-value="
                                    toggleColumn(column.key, $event === true)
                                "
                                @select.prevent
                                >{{ column.label }}</DropdownMenuCheckboxItem
                            >
                        </DropdownMenuContent>
                    </DropdownMenu>
                </div>
            </div>
            <div
                class="mt-5 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4"
            >
                <MemberFilter
                    id="filter-membership"
                    label="Mitgliedschaft"
                    :value="draft.membership"
                    :options="filterOptions.memberships"
                    :labels="fieldOptions('membership_type')"
                    @change="filter('membership', $event)"
                />
                <MemberFilter
                    v-if="
                        fieldDefinitions.some(
                            (field) => field.key === 'department_role',
                        )
                    "
                    id="filter-department"
                    label="Funktion in der Abteilung"
                    :value="draft.department_role"
                    :options="filterOptions.departmentRoles"
                    :labels="fieldOptions('department_role')"
                    with-presence
                    @change="filter('department_role', $event)"
                />
                <MemberFilter
                    v-if="
                        fieldDefinitions.some(
                            (field) => field.key === 'club_role',
                        )
                    "
                    id="filter-club"
                    label="Funktion im Hauptverein"
                    :value="draft.club_role"
                    :options="filterOptions.clubRoles"
                    :labels="fieldOptions('club_role')"
                    with-presence
                    @change="filter('club_role', $event)"
                />
                <div
                    v-for="field in customFilters"
                    :key="field.key"
                    class="grid min-w-0 gap-2"
                >
                    <Label
                        :for="`filter-${field.key}`"
                        class="text-xs text-muted-foreground"
                        >{{ field.label }}</Label
                    >
                    <select
                        v-if="
                            field.type === 'select' || field.type === 'boolean'
                        "
                        :id="`filter-${field.key}`"
                        class="h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
                        :value="draft.custom[field.key] || ''"
                        @change="
                            filter(
                                `custom:${field.key}`,
                                ($event.target as HTMLSelectElement).value,
                            )
                        "
                    >
                        <option value="">Alle</option>
                        <template v-if="field.type === 'boolean'"
                            ><option value="1">Ja</option>
                            <option value="0">Nein</option></template
                        ><template v-else
                            ><option
                                v-for="(label, value) in field.options"
                                :key="value"
                                :value="value"
                            >
                                {{ label }}
                            </option></template
                        >
                    </select>
                    <Input
                        v-else
                        :id="`filter-${field.key}`"
                        :type="
                            field.type === 'date'
                                ? 'date'
                                : ['number', 'decimal'].includes(field.type)
                                  ? 'number'
                                  : 'text'
                        "
                        :step="field.type === 'decimal' ? '0.01' : undefined"
                        :model-value="draft.custom[field.key] || ''"
                        placeholder="Alle"
                        @change="
                            filter(
                                `custom:${field.key}`,
                                ($event.target as HTMLInputElement).value,
                            )
                        "
                        @keydown.enter.prevent="
                            filter(
                                `custom:${field.key}`,
                                ($event.target as HTMLInputElement).value,
                            )
                        "
                    />
                </div>
            </div>
            <div
                v-if="hasFilters"
                class="mt-4 flex flex-wrap items-center gap-2 border-t pt-4"
                aria-label="Aktive Filter"
            >
                <SlidersHorizontal
                    class="mr-1 size-3.5 text-muted-foreground"
                    aria-hidden="true"
                />
                <button
                    v-for="active in activeFilters"
                    :key="active.key"
                    type="button"
                    class="inline-flex max-w-full items-center gap-2 rounded-md bg-muted px-2.5 py-1.5 text-xs transition-colors hover:bg-accent"
                    :aria-label="`${active.label}: ${active.value} entfernen`"
                    @click="filter(active.key, '')"
                >
                    <span class="max-w-64 truncate"
                        >{{ active.label }}: {{ active.value }}</span
                    ><X class="size-3 shrink-0" aria-hidden="true" />
                </button>
            </div>
        </section>

        <p v-if="error" role="alert" class="text-sm text-destructive">
            {{ error }}
        </p>

        <section
            class="min-w-0 overflow-hidden rounded-xl border bg-card"
            aria-label="Suchergebnis"
        >
            <div
                class="flex flex-wrap items-center justify-between gap-2 border-b px-4 py-3 text-sm"
            >
                <p
                    role="status"
                    aria-live="polite"
                    data-test="member-results"
                    class="text-muted-foreground"
                >
                    <template v-if="loading"
                        >Mitglieder werden geladen …</template
                    >
                    <template v-else>
                        <span class="font-medium text-foreground">{{
                            `${number.format(members.total)} ${members.total === 1 ? 'Mitglied' : 'Mitglieder'}`
                        }}</span
                        ><span v-if="hasFilters">{{
                            ` von ${number.format(totalMembers)}`
                        }}</span>
                    </template>
                </p>
                <Spinner v-if="loading" class="size-4" aria-hidden="true" />
                <span v-else class="text-xs text-muted-foreground">{{
                    `Seite ${number.format(members.current_page)} von ${number.format(members.last_page)}`
                }}</span>
            </div>
            <div
                class="flex flex-wrap items-center justify-between gap-3 border-t px-4 py-3"
            >
                <div class="flex items-center gap-3 text-sm">
                    <span aria-live="polite"
                        >{{ selection.numbers.length }} ausgewählt</span
                    ><Button
                        v-if="selection.numbers.length"
                        variant="ghost"
                        size="sm"
                        @click="selection.numbers = []"
                        >Auswahl aufheben</Button
                    >
                </div>
                <div class="flex flex-wrap items-start justify-end gap-2">
                    <BulkEditMembers
                        v-if="canBulkEdit"
                        :selected="selection.numbers"
                        :fields="fieldDefinitions"
                        :configuration-version="configurationVersion"
                        @updated="selection.numbers = []"
                    />
                    <MemberExport
                        :filters="filters"
                        :selected="selection.numbers"
                        :columns="visibleColumns"
                        :all-columns="memberColumns"
                        :fields="fieldDefinitions"
                        :total="members.total"
                        :disabled="loading"
                    />
                </div>
            </div>
            <MemberTable
                :members="members.data"
                :definitions="fieldDefinitions"
                :columns="visibleColumns"
                :filters="filters"
                :loading="loading"
                :filtered="hasFilters"
                :selected="selection.numbers"
                @select="selectMember"
                @select-page="selectPage"
                @sort="sort"
                @reset="reset"
            />
            <p
                v-if="visibleColumns.length > 2"
                class="border-t px-4 py-2 text-xs text-muted-foreground sm:hidden"
            >
                Weitere Spalten durch seitliches Wischen.
            </p>
            <footer
                class="flex flex-wrap items-center justify-between gap-4 border-t px-4 py-3"
            >
                <div
                    class="flex flex-wrap items-center gap-3 text-xs text-muted-foreground"
                >
                    <Label for="members-per-page" class="text-xs font-normal"
                        >Zeilen pro Seite</Label
                    >
                    <Select
                        :model-value="String(draft.per_page)"
                        @update:model-value="
                            draft.per_page = Number($event);
                            visit();
                        "
                    >
                        <SelectTrigger
                            id="members-per-page"
                            class="w-20"
                            size="sm"
                            ><SelectValue
                        /></SelectTrigger>
                        <SelectContent
                            ><SelectItem
                                v-for="size in [10, 25, 50, 100]"
                                :key="size"
                                :value="String(size)"
                                >{{ size }}</SelectItem
                            ></SelectContent
                        >
                    </Select>
                    <span class="tabular-nums"
                        >{{ number.format(members.from || 0) }}–{{
                            number.format(members.to || 0)
                        }}
                        von {{ number.format(members.total) }}</span
                    >
                </div>
                <nav
                    class="flex items-center gap-1"
                    aria-label="Tabellenseiten"
                >
                    <Button
                        variant="outline"
                        size="icon-sm"
                        aria-label="Vorherige Seite"
                        :disabled="loading || members.current_page <= 1"
                        @click="visit(members.current_page - 1)"
                        ><ChevronLeft class="size-4" aria-hidden="true"
                    /></Button>
                    <template v-for="page in pages" :key="page">
                        <Button
                            v-if="typeof page === 'number'"
                            :variant="
                                page === members.current_page
                                    ? 'secondary'
                                    : 'ghost'
                            "
                            size="icon-sm"
                            :aria-label="`Seite ${page}`"
                            :aria-current="
                                page === members.current_page
                                    ? 'page'
                                    : undefined
                            "
                            :disabled="loading"
                            @click="visit(page)"
                            >{{ page }}</Button
                        >
                        <span
                            v-else
                            class="px-1 text-xs text-muted-foreground"
                            aria-hidden="true"
                            >…</span
                        >
                    </template>
                    <Button
                        variant="outline"
                        size="icon-sm"
                        aria-label="Nächste Seite"
                        :disabled="
                            loading || members.current_page >= members.last_page
                        "
                        @click="visit(members.current_page + 1)"
                        ><ChevronRight class="size-4" aria-hidden="true"
                    /></Button>
                </nav>
            </footer>
        </section>
    </div>
</template>
