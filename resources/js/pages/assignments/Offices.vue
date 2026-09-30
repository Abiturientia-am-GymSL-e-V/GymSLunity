<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { CalendarSearch, Crown, History } from '@lucide/vue';
import { computed, reactive, watch } from 'vue';
import AssignmentMember from '@/components/assignments/AssignmentMember.vue';
import ExportLinks from '@/components/assignments/ExportLinks.vue';
import InputError from '@/components/InputError.vue';
import SearchableDropdown from '@/components/SearchableDropdown.vue';
import StatusAlert from '@/components/StatusAlert.vue';
import { Badge } from '@/components/ui/badge';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { applyOverviewFilters, withQuery } from '@/lib/assignmentOverview';
import { offices as officesRoute } from '@/routes/assignments';
import type { AssignmentFilters, OfficeGroup } from '@/types/assignments';
import type { AssignmentField } from '@/types/members';

type Tab = 'current' | 'history' | 'date';
const props = defineProps<{
    tab: Tab;
    filters: AssignmentFilters;
    fields: AssignmentField[];
    offices: OfficeGroup[];
    errors?: Record<string, string>;
}>();
defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Vorstand / Ämter', href: officesRoute() }],
    },
});

const tabs = [
    { key: 'current', label: 'Aktuell', icon: Crown, path: '/aemter' },
    {
        key: 'history',
        label: 'Verlauf',
        icon: History,
        path: '/aemter/verlauf',
    },
    {
        key: 'date',
        label: 'Stichtag',
        icon: CalendarSearch,
        path: '/aemter/stichtag',
    },
] as const;
const form = reactive({
    field: props.filters.field,
    board: props.filters.board,
    members: props.filters.members,
    date: props.filters.date,
    from: props.filters.from ?? '',
    to: props.filters.to ?? '',
});
watch(
    () => props.filters,
    (filters) =>
        Object.assign(form, {
            ...filters,
            from: filters.from ?? '',
            to: filters.to ?? '',
        }),
);
const shared = computed(() => ({
    field: form.field,
    board: form.board,
    members: form.members === 'current' ? 'current' : '',
}));
const query = computed(() => ({
    ...shared.value,
    ...(props.tab === 'date' ? { date: form.date } : {}),
    ...(props.tab === 'history' ? { from: form.from, to: form.to } : {}),
}));
const activePath = computed(
    () => tabs.find((tab) => tab.key === props.tab)?.path ?? '/aemter',
);
const apply = () => applyOverviewFilters(activePath.value, query.value);
const fieldOptions = computed(() => [
    { value: '', label: 'Alle Ämterfelder' },
    ...props.fields.map((field) => ({
        value: field.key,
        label: field.readOnly ? `${field.label} (archiviert)` : field.label,
    })),
]);
const hasHolders = computed(() =>
    props.offices.some((group) =>
        group.options.some((option) => option.holders.length > 0),
    ),
);
const vacancies = computed(() =>
    props.offices.flatMap((group) =>
        group.options.filter((option) => option.vacant),
    ),
);
const description: Record<Tab, string> = {
    current:
        'Wer heute welches Amt innehat, sortiert nach Rang. Unbesetzte Pflichtämter sind markiert.',
    history: 'Alle Amtsinhaber je Amt mit ihren Amtszeiten.',
    date: 'Wer an einem bestimmten Tag welches Amt innehatte.',
};
</script>

<template>
    <Head title="Vorstand / Ämter" />
    <div class="mx-auto w-full max-w-[1200px] space-y-6 p-4 sm:p-6">
        <header>
            <h1 class="text-2xl font-semibold tracking-tight">
                Vorstand / Ämter
            </h1>
            <p class="mt-1 text-sm text-muted-foreground">
                {{ description[tab] }}
            </p>
        </header>

        <nav aria-label="Ansichten" class="flex flex-wrap gap-2 border-b pb-4">
            <Link
                v-for="item in tabs"
                :key="item.key"
                :href="withQuery(item.path, shared)"
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
            title="Noch keine Ämter eingerichtet"
        >
            Lege unter Konfiguration → Mitgliedsfelder ein Feld vom Typ
            „Funktion / Amt (mit Zeitraum)“ an.
        </StatusAlert>

        <template v-else>
            <form
                class="grid gap-4 rounded-xl border bg-card p-4 sm:grid-cols-2 xl:grid-cols-4"
                @submit.prevent="apply"
            >
                <div v-if="fields.length > 1" class="min-w-0 space-y-2">
                    <Label for="office-field">Ämterfeld</Label>
                    <SearchableDropdown
                        id="office-field"
                        :model-value="form.field"
                        :options="fieldOptions"
                        placeholder="Alle Ämterfelder"
                        search-placeholder="Feld suchen"
                        empty-text="Kein Feld gefunden."
                        aria-label="Ämterfeld"
                        trigger-class="h-9 w-full rounded-md border border-input bg-background px-3"
                        @update:model-value="
                            form.field = $event;
                            apply();
                        "
                    />
                </div>
                <div v-if="tab === 'date'" class="min-w-0 space-y-2">
                    <Label for="office-date">Stichtag</Label>
                    <Input
                        id="office-date"
                        v-model="form.date"
                        type="date"
                        required
                        @change="apply"
                    />
                    <InputError :message="errors?.date" />
                </div>
                <template v-if="tab === 'history'">
                    <div class="min-w-0 space-y-2">
                        <Label for="office-from">Von</Label>
                        <Input
                            id="office-from"
                            v-model="form.from"
                            type="date"
                            :max="form.to || undefined"
                            @change="apply"
                        />
                        <InputError :message="errors?.from" />
                    </div>
                    <div class="min-w-0 space-y-2">
                        <Label for="office-to">Bis</Label>
                        <Input
                            id="office-to"
                            v-model="form.to"
                            type="date"
                            :min="form.from || undefined"
                            @change="apply"
                        />
                        <InputError :message="errors?.to" />
                    </div>
                </template>
                <div class="min-w-0 space-y-2">
                    <Label for="office-members">Mitglieder</Label>
                    <select
                        id="office-members"
                        v-model="form.members"
                        class="h-9 w-full rounded-md border border-input bg-background px-3 text-base md:text-sm"
                        @change="apply"
                    >
                        <option value="all">Alle, auch ehemalige</option>
                        <option value="current">Nur aktuelle Mitglieder</option>
                    </select>
                </div>
                <div class="min-w-0 space-y-2">
                    <!-- Keeps the checkbox level with the labelled fields. -->
                    <span
                        class="hidden text-sm leading-none sm:block"
                        aria-hidden="true"
                        >&nbsp;</span
                    >
                    <div class="flex h-9 items-center gap-2">
                        <Checkbox
                            id="office-board"
                            :model-value="form.board"
                            @update:model-value="
                                form.board = $event === true;
                                apply();
                            "
                        />
                        <Label for="office-board">Nur Vorstandsämter</Label>
                    </div>
                </div>
                <div
                    class="flex flex-wrap items-center justify-between gap-2 sm:col-span-2 xl:col-span-4"
                >
                    <p class="text-sm text-muted-foreground">
                        Export mit den gewählten Filtern
                    </p>
                    <ExportLinks
                        path="/aemter/export"
                        :query="{ ...query, tab }"
                    />
                </div>
            </form>

            <StatusAlert
                v-if="tab !== 'history' && vacancies.length"
                type="warning"
                title="Unbesetzte Pflichtämter"
            >
                {{ vacancies.map((option) => option.label).join(', ') }}
            </StatusAlert>
            <StatusAlert
                v-if="!hasHolders"
                type="info"
                title="Keine Amtsinhaber gefunden"
            >
                Für diese Auswahl ist kein Amt besetzt.
            </StatusAlert>

            <section
                v-for="group in offices"
                :key="group.key"
                class="rounded-xl border bg-card"
                :aria-labelledby="`office-${group.key}`"
            >
                <div
                    class="flex flex-wrap items-center gap-2 border-b px-5 py-4"
                >
                    <h2 :id="`office-${group.key}`" class="font-semibold">
                        {{ group.label }}
                    </h2>
                    <Badge v-if="group.archived" variant="outline"
                        >archiviert</Badge
                    >
                </div>
                <p
                    v-if="!group.options.length"
                    class="p-5 text-sm text-muted-foreground"
                >
                    Keine Ämter für diese Auswahl.
                </p>
                <ul v-else class="divide-y">
                    <li
                        v-for="option in group.options"
                        :key="option.value"
                        class="flex flex-wrap items-start gap-x-6 gap-y-2 px-5 py-4"
                    >
                        <div class="min-w-0 flex-1 basis-48">
                            <p class="font-medium break-words">
                                {{ option.label }}
                            </p>
                            <div class="mt-1 flex flex-wrap gap-1">
                                <Badge v-if="option.board" variant="secondary"
                                    >Vorstand</Badge
                                >
                                <Badge v-if="option.mandatory" variant="outline"
                                    >Pflichtamt</Badge
                                >
                                <Badge v-if="!option.active" variant="outline"
                                    >deaktiviert</Badge
                                >
                            </div>
                        </div>
                        <div class="min-w-0 flex-[2] basis-64 space-y-3">
                            <p
                                v-if="option.vacant"
                                class="text-sm font-medium text-destructive"
                            >
                                Unbesetzt
                            </p>
                            <p
                                v-else-if="!option.holders.length"
                                class="text-sm text-muted-foreground"
                            >
                                Nicht besetzt
                            </p>
                            <AssignmentMember
                                v-for="holder in option.holders"
                                :key="holder.id"
                                :row="holder"
                                type="office"
                            />
                            <p
                                v-if="option.exceeded"
                                class="text-sm text-muted-foreground"
                            >
                                Mehr Inhaber als die vorgesehenen
                                {{ option.maxHolders }}.
                            </p>
                        </div>
                    </li>
                </ul>
            </section>
        </template>
    </div>
</template>
