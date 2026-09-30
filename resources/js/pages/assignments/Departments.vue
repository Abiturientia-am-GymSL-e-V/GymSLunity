<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { X } from '@lucide/vue';
import { computed, reactive, watch } from 'vue';
import AssignmentMember from '@/components/assignments/AssignmentMember.vue';
import ExportLinks from '@/components/assignments/ExportLinks.vue';
import InputError from '@/components/InputError.vue';
import SearchableDropdown from '@/components/SearchableDropdown.vue';
import StatusAlert from '@/components/StatusAlert.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { applyOverviewFilters, withQuery } from '@/lib/assignmentOverview';
import { formatDate } from '@/lib/format';
import { departments as departmentsRoute } from '@/routes/assignments';
import type {
    AssignmentFilters,
    AssignmentRow,
    DepartmentGroup,
} from '@/types/assignments';
import type { AssignmentField } from '@/types/members';

const props = defineProps<{
    filters: AssignmentFilters & { from: string; to: string };
    fields: AssignmentField[];
    groups: DepartmentGroup[];
    detail: AssignmentRow[] | null;
    errors?: Record<string, string>;
}>();
defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Abteilungen', href: departmentsRoute() }],
    },
});

const form = reactive({
    field: props.filters.field,
    from: props.filters.from,
    to: props.filters.to,
    members: props.filters.members,
});
watch(
    () => props.filters,
    (filters) => Object.assign(form, filters),
);
const query = computed(() => ({
    field: form.field,
    from: form.from,
    to: form.to,
    members: form.members === 'current' ? 'current' : '',
}));
const apply = () => applyOverviewFilters('/abteilungen', query.value);
const fieldOptions = computed(() => [
    { value: '', label: 'Alle Abteilungsfelder' },
    ...props.fields.map((field) => ({
        value: field.key,
        label: field.readOnly ? `${field.label} (archiviert)` : field.label,
    })),
]);
const selectedLabel = computed(() => {
    const group = props.groups.find((item) => item.key === props.filters.field);
    return (
        group?.options.find((option) => option.value === props.filters.option)
            ?.label ?? props.filters.option
    );
});
const period = computed(
    () => `${formatDate(props.filters.from)} – ${formatDate(props.filters.to)}`,
);
const number = (value: number) => value.toLocaleString('de-DE');
</script>

<template>
    <Head title="Abteilungen" />
    <div class="mx-auto w-full max-w-[1200px] space-y-6 p-4 sm:p-6">
        <header>
            <h1 class="text-2xl font-semibold tracking-tight">Abteilungen</h1>
            <p class="mt-1 text-sm text-muted-foreground">
                Mitglieder je Abteilung am Ende des Zeitraums sowie Eintritte
                und Austritte im Zeitraum. Für einen Stichtag Beginn und Ende
                gleich wählen.
            </p>
        </header>

        <StatusAlert
            v-if="!fields.length"
            type="info"
            title="Noch keine Abteilungen eingerichtet"
        >
            Lege unter Konfiguration → Mitgliedsfelder ein Feld vom Typ
            „Abteilung (mit Zeitraum)“ an.
        </StatusAlert>

        <template v-else>
            <form
                class="grid gap-4 rounded-xl border bg-card p-4 sm:grid-cols-2 xl:grid-cols-4"
                @submit.prevent="apply"
            >
                <div v-if="fields.length > 1" class="min-w-0 space-y-2">
                    <Label for="department-field">Abteilungsfeld</Label>
                    <SearchableDropdown
                        id="department-field"
                        :model-value="form.field"
                        :options="fieldOptions"
                        placeholder="Alle Abteilungsfelder"
                        search-placeholder="Feld suchen"
                        empty-text="Kein Feld gefunden."
                        aria-label="Abteilungsfeld"
                        trigger-class="h-9 w-full rounded-md border border-input bg-background px-3"
                        @update:model-value="
                            form.field = $event;
                            apply();
                        "
                    />
                </div>
                <div class="min-w-0 space-y-2">
                    <Label for="department-from">Von</Label>
                    <Input
                        id="department-from"
                        v-model="form.from"
                        type="date"
                        required
                        :max="form.to || undefined"
                        @change="apply"
                    />
                    <InputError :message="errors?.from" />
                </div>
                <div class="min-w-0 space-y-2">
                    <Label for="department-to">Bis</Label>
                    <Input
                        id="department-to"
                        v-model="form.to"
                        type="date"
                        required
                        :min="form.from || undefined"
                        @change="apply"
                    />
                    <InputError :message="errors?.to" />
                </div>
                <div class="min-w-0 space-y-2">
                    <Label for="department-members">Mitglieder</Label>
                    <select
                        id="department-members"
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
                        Export der Übersicht für {{ period }}
                    </p>
                    <ExportLinks path="/abteilungen/export" :query="query" />
                </div>
            </form>

            <section
                v-if="detail"
                class="rounded-xl border bg-card"
                aria-labelledby="department-detail"
            >
                <div
                    class="flex flex-wrap items-center justify-between gap-2 border-b px-5 py-4"
                >
                    <div class="min-w-0">
                        <h2 id="department-detail" class="font-semibold">
                            {{ selectedLabel }}
                        </h2>
                        <p class="text-sm text-muted-foreground">
                            Mitglieder im Zeitraum {{ period }}
                        </p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <ExportLinks
                            path="/abteilungen/export"
                            :query="{ ...query, option: filters.option }"
                        />
                        <Button variant="ghost" as-child>
                            <Link
                                :href="withQuery('/abteilungen', query)"
                                preserve-scroll
                                ><X class="size-4" />Schließen</Link
                            >
                        </Button>
                    </div>
                </div>
                <p
                    v-if="!detail.length"
                    class="p-5 text-sm text-muted-foreground"
                >
                    Im Zeitraum war niemand dieser Abteilung zugeordnet.
                </p>
                <ul v-else class="divide-y">
                    <li v-for="row in detail" :key="row.id" class="px-5 py-3">
                        <AssignmentMember :row="row" type="department" />
                    </li>
                </ul>
            </section>

            <section
                v-for="group in groups"
                :key="group.key"
                class="overflow-hidden rounded-xl border bg-card"
                :aria-labelledby="`department-${group.key}`"
            >
                <div
                    class="flex flex-wrap items-center gap-2 border-b px-5 py-4"
                >
                    <h2 :id="`department-${group.key}`" class="font-semibold">
                        {{ group.label }}
                    </h2>
                    <Badge v-if="group.archived" variant="outline"
                        >archiviert</Badge
                    >
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-muted/50 text-left">
                            <tr>
                                <th class="px-5 py-3 font-semibold">
                                    Abteilung
                                </th>
                                <th class="px-5 py-3 text-right font-semibold">
                                    Mitglieder am {{ formatDate(filters.to) }}
                                </th>
                                <th class="px-5 py-3 text-right font-semibold">
                                    Eintritte
                                </th>
                                <th class="px-5 py-3 text-right font-semibold">
                                    Austritte
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr v-if="!group.options.length">
                                <td
                                    colspan="4"
                                    class="px-5 py-4 text-muted-foreground"
                                >
                                    Keine Abteilungen angelegt.
                                </td>
                            </tr>
                            <tr
                                v-for="option in group.options"
                                :key="option.value"
                                :class="
                                    filters.field === group.key &&
                                    filters.option === option.value
                                        ? 'bg-muted/50'
                                        : ''
                                "
                            >
                                <td class="px-5 py-3">
                                    <Link
                                        :href="
                                            withQuery('/abteilungen', {
                                                ...query,
                                                field: group.key,
                                                option: option.value,
                                            })
                                        "
                                        preserve-scroll
                                        class="font-medium break-words underline-offset-4 hover:underline"
                                        >{{ option.label }}</Link
                                    >
                                    <span
                                        v-if="!option.active"
                                        class="ml-2 text-xs text-muted-foreground"
                                        >deaktiviert</span
                                    >
                                </td>
                                <td class="px-5 py-3 text-right tabular-nums">
                                    {{ number(option.members) }}
                                </td>
                                <td class="px-5 py-3 text-right tabular-nums">
                                    {{ number(option.joined) }}
                                </td>
                                <td class="px-5 py-3 text-right tabular-nums">
                                    {{ number(option.left) }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </template>
    </div>
</template>
