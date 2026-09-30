<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Award, CalendarClock, ChevronLeft, ChevronRight } from '@lucide/vue';
import { computed, reactive, watch } from 'vue';
import AssignmentMember from '@/components/assignments/AssignmentMember.vue';
import ExportLinks from '@/components/assignments/ExportLinks.vue';
import HonorJubilees from '@/components/assignments/HonorJubilees.vue';
import SearchableDropdown from '@/components/SearchableDropdown.vue';
import StatusAlert from '@/components/StatusAlert.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { applyOverviewFilters, withQuery } from '@/lib/assignmentOverview';
import { assignmentPeriod } from '@/lib/memberFormatting';
import { honors as honorsRoute } from '@/routes/assignments';
import type {
    AssignmentFilters,
    HonorRow,
    JubileeGroup,
} from '@/types/assignments';
import type { AssignmentField } from '@/types/members';

type Tab = 'list' | 'jubilees';
const props = defineProps<{
    tab: Tab;
    filters: AssignmentFilters;
    fields: AssignmentField[];
    years: number[];
    jubilees: JubileeGroup[];
    canAssign: boolean;
    configurationVersion: number;
    honors: null | {
        data: HonorRow[];
        total: number;
        current_page: number;
        last_page: number;
        prev_page_url: string | null;
        next_page_url: string | null;
    };
}>();
defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Ereignisse / Ehrungen', href: honorsRoute() }],
    },
});

const tabs = [
    { key: 'list', label: 'Chronik', icon: Award, path: '/ehrungen' },
    {
        key: 'jubilees',
        label: 'Fällige Jubiläen',
        icon: CalendarClock,
        path: '/ehrungen/jubilaeen',
    },
] as const;
const activePath = computed(
    () => tabs.find((item) => item.key === props.tab)?.path ?? '/ehrungen',
);
const form = reactive({
    field: props.filters.field,
    option: props.filters.option,
    year: props.filters.year ? String(props.filters.year) : '',
    members: props.filters.members,
    date: props.filters.date,
});
watch(
    () => props.filters,
    (filters) =>
        Object.assign(form, {
            ...filters,
            year: filters.year ? String(filters.year) : '',
        }),
);
const query = computed(() =>
    props.tab === 'jubilees'
        ? { field: form.field, option: form.option, date: form.date }
        : {
              field: form.field,
              option: form.option,
              year: form.year,
              members: form.members === 'current' ? 'current' : '',
          },
);
const apply = () => applyOverviewFilters(activePath.value, query.value);
// Options only make sense within one field.
const selectedField = computed(() =>
    props.fields.length === 1
        ? props.fields[0]
        : props.fields.find((field) => field.key === form.field),
);
const fieldOptions = computed(() => [
    { value: '', label: 'Alle Felder' },
    ...props.fields.map((field) => ({
        value: field.key,
        label: field.readOnly ? `${field.label} (archiviert)` : field.label,
    })),
]);
const optionOptions = computed(() => [
    { value: '', label: 'Alle Ehrungen' },
    ...(props.tab === 'jubilees'
        ? (selectedField.value?.optionDetails ?? [])
              .filter((option) => option.active && option.jubileeYears)
              .map(({ value, label }) => ({ value, label }))
        : Object.entries(selectedField.value?.options ?? {}).map(
              ([value, label]) => ({ value, label }),
          )),
]);
const jubileeCount = computed(() =>
    props.jubilees.reduce((sum, group) => sum + group.members.length, 0),
);
const yearOptions = computed(() => [
    { value: '', label: 'Alle Jahre' },
    ...props.years.map((year) => ({
        value: String(year),
        label: String(year),
    })),
]);
</script>

<template>
    <Head title="Ereignisse / Ehrungen" />
    <div class="mx-auto w-full max-w-[1200px] space-y-6 p-4 sm:p-6">
        <header>
            <h1 class="text-2xl font-semibold tracking-tight">
                Ereignisse / Ehrungen
            </h1>
            <p class="mt-1 text-sm text-muted-foreground">
                {{
                    tab === 'jubilees'
                        ? 'Aktuelle Mitglieder, die eine Ehrung mit Jubiläumsregel noch nicht erhalten haben.'
                        : 'Alle Ehrungen und Ereignisse, die neuesten zuerst.'
                }}
            </p>
        </header>

        <nav aria-label="Ansichten" class="flex flex-wrap gap-2 border-b pb-4">
            <Link
                v-for="item in tabs"
                :key="item.key"
                :href="withQuery(item.path, { field: form.field })"
                :aria-current="tab === item.key ? 'page' : undefined"
                class="flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-medium transition-colors hover:bg-muted"
                :class="
                    tab === item.key
                        ? 'bg-muted text-foreground'
                        : 'text-muted-foreground'
                "
                ><component :is="item.icon" class="size-4" />{{
                    item.label
                }}</Link
            >
        </nav>

        <StatusAlert
            v-if="!fields.length"
            type="info"
            title="Noch keine Ehrungen eingerichtet"
        >
            Lege unter Konfiguration → Mitgliedsfelder ein Feld vom Typ
            „Ereignis / Ehrung (mit Datum)“ an.
        </StatusAlert>

        <template v-else>
            <form
                class="grid gap-4 rounded-xl border bg-card p-4 sm:grid-cols-2 xl:grid-cols-4"
                @submit.prevent="apply"
            >
                <div v-if="fields.length > 1" class="min-w-0 space-y-2">
                    <Label for="honor-field">Feld</Label>
                    <SearchableDropdown
                        id="honor-field"
                        :model-value="form.field"
                        :options="fieldOptions"
                        placeholder="Alle Felder"
                        search-placeholder="Feld suchen"
                        empty-text="Kein Feld gefunden."
                        aria-label="Feld"
                        trigger-class="h-9 w-full rounded-md border border-input bg-background px-3"
                        @update:model-value="
                            form.field = $event;
                            form.option = '';
                            apply();
                        "
                    />
                </div>
                <div class="min-w-0 space-y-2">
                    <Label for="honor-option">Ehrung</Label>
                    <SearchableDropdown
                        id="honor-option"
                        :model-value="form.option"
                        :options="optionOptions"
                        :disabled="!selectedField"
                        placeholder="Alle Ehrungen"
                        search-placeholder="Ehrung suchen"
                        empty-text="Keine Ehrung gefunden."
                        aria-label="Ehrung"
                        trigger-class="h-9 w-full rounded-md border border-input bg-background px-3"
                        @update:model-value="
                            form.option = $event;
                            apply();
                        "
                    />
                </div>
                <div v-if="tab === 'jubilees'" class="min-w-0 space-y-2">
                    <Label for="honor-until">Fällig bis</Label>
                    <Input
                        id="honor-until"
                        v-model="form.date"
                        type="date"
                        @change="apply"
                    />
                </div>
                <div v-if="tab === 'list'" class="min-w-0 space-y-2">
                    <Label for="honor-year">Jahr</Label>
                    <SearchableDropdown
                        id="honor-year"
                        :model-value="form.year"
                        :options="yearOptions"
                        placeholder="Alle Jahre"
                        search-placeholder="Jahr suchen"
                        empty-text="Kein Jahr gefunden."
                        aria-label="Jahr"
                        trigger-class="h-9 w-full rounded-md border border-input bg-background px-3"
                        @update:model-value="
                            form.year = $event;
                            apply();
                        "
                    />
                </div>
                <div v-if="tab === 'list'" class="min-w-0 space-y-2">
                    <Label for="honor-members">Mitglieder</Label>
                    <select
                        id="honor-members"
                        v-model="form.members"
                        class="h-9 w-full rounded-md border border-input bg-background px-3 text-base md:text-sm"
                        @change="apply"
                    >
                        <option value="all">Alle, auch ehemalige</option>
                        <option value="current">Nur aktuelle Mitglieder</option>
                    </select>
                </div>
                <div
                    class="flex flex-wrap items-center justify-between gap-2 sm:col-span-2 xl:col-span-4"
                >
                    <p class="text-sm text-muted-foreground">
                        <template v-if="honors"
                            >{{ honors.total.toLocaleString('de-DE') }}
                            {{
                                honors.total === 1 ? 'Eintrag' : 'Einträge'
                            }}</template
                        ><template v-else
                            >{{ jubileeCount.toLocaleString('de-DE') }}
                            {{
                                jubileeCount === 1 ? 'Jubiläum' : 'Jubiläen'
                            }}</template
                        >
                    </p>
                    <ExportLinks
                        path="/ehrungen/export"
                        :query="
                            tab === 'jubilees'
                                ? { ...query, tab: 'jubilees' }
                                : query
                        "
                    />
                </div>
            </form>

            <template v-if="tab === 'jubilees'">
                <StatusAlert
                    v-if="!jubilees.length"
                    type="info"
                    title="Keine Jubiläumsregel eingerichtet"
                >
                    Trage unter Konfiguration → Mitgliedsfelder bei einer Ehrung
                    „Fällig nach … Mitgliedsjahren“ ein, zum Beispiel 25 für
                    eine Ehrennadel in Silber.
                </StatusAlert>
                <HonorJubilees
                    v-else
                    :groups="jubilees"
                    :fields="fields"
                    :can-assign="canAssign"
                    :configuration-version="configurationVersion"
                />
            </template>

            <section
                v-else-if="honors"
                class="overflow-hidden rounded-xl border bg-card"
            >
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-muted/50 text-left">
                            <tr>
                                <th class="px-5 py-3 font-semibold">Datum</th>
                                <th class="px-5 py-3 font-semibold">Ehrung</th>
                                <th class="px-5 py-3 font-semibold">
                                    Mitglied
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr v-if="!honors.data.length">
                                <td
                                    colspan="3"
                                    class="px-5 py-4 text-muted-foreground"
                                >
                                    Keine Ehrungen für diese Auswahl.
                                </td>
                            </tr>
                            <tr v-for="row in honors.data" :key="row.id">
                                <td class="px-5 py-3 whitespace-nowrap">
                                    {{
                                        assignmentPeriod(row, 'honor').replace(
                                            /^am /,
                                            '',
                                        )
                                    }}
                                </td>
                                <td class="px-5 py-3">
                                    <p class="font-medium">{{ row.label }}</p>
                                    <p
                                        v-if="fields.length > 1"
                                        class="text-xs text-muted-foreground"
                                    >
                                        {{ row.field }}
                                    </p>
                                </td>
                                <td class="min-w-56 px-5 py-3">
                                    <AssignmentMember
                                        :row="row"
                                        type="honor"
                                        hide-period
                                    />
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div
                    v-if="honors.last_page > 1"
                    class="flex items-center justify-end gap-2 border-t px-5 py-3"
                >
                    <Button
                        v-if="honors.prev_page_url"
                        variant="outline"
                        size="icon-sm"
                        as-child
                        ><Link
                            :href="honors.prev_page_url"
                            preserve-scroll
                            aria-label="Vorherige Seite"
                            ><ChevronLeft class="size-4" /></Link
                    ></Button>
                    <span class="text-xs text-muted-foreground">{{
                        `Seite ${honors.current_page} von ${honors.last_page}`
                    }}</span>
                    <Button
                        v-if="honors.next_page_url"
                        variant="outline"
                        size="icon-sm"
                        as-child
                        ><Link
                            :href="honors.next_page_url"
                            preserve-scroll
                            aria-label="Nächste Seite"
                            ><ChevronRight class="size-4" /></Link
                    ></Button>
                </div>
            </section>
        </template>
    </div>
</template>
