<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Download, Search } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import SearchableDropdown from '@/components/SearchableDropdown.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatDateTime } from '@/lib/format';

type Entry = {
    id: string;
    occurred_at: string;
    area: string;
    action: string;
    outcome: string;
    actor: string;
    subject: string;
    details: string;
};

const props = defineProps<{
    entries: {
        data: Entry[];
        total: number;
        current_page: number;
        last_page: number;
        next_page_url: string | null;
        prev_page_url: string | null;
    };
    filters: { search: string; from: string; to: string; area: string };
    areas: Record<string, string>;
    retentionDays: number;
}>();
defineOptions({ layout: { breadcrumbs: [{ title: 'Auditlog' }] } });

const search = ref(props.filters.search);
const area = ref(props.filters.area);
const from = ref(props.filters.from);
const to = ref(props.filters.to);
const areaOptions = computed(() => [
    { value: 'all', label: 'Alle Bereiche' },
    ...Object.entries(props.areas).map(([value, label]) => ({ value, label })),
]);
const query = computed(() => {
    const params = new URLSearchParams();
    if (search.value.trim()) params.set('search', search.value.trim());
    if (area.value !== 'all') params.set('area', area.value);
    if (from.value) params.set('from', from.value);
    if (to.value) params.set('to', to.value);
    return params;
});
const exportUrl = (format: 'csv' | 'xlsx' | 'pdf') => {
    const params = new URLSearchParams(query.value);
    params.set('format', format);
    return `/auditlog/export?${params.toString()}`;
};
let timer: ReturnType<typeof setTimeout> | undefined;
watch([search, area, from, to], () => {
    clearTimeout(timer);
    timer = setTimeout(
        () =>
            router.get(
                `/auditlog?${query.value.toString()}`,
                {},
                { preserveState: true, replace: true },
            ),
        250,
    );
});
const outcomeClass = (outcome: string) =>
    outcome === 'Erfolgreich'
        ? 'border-success/30 bg-success/10 text-success'
        : 'border-destructive/30 bg-destructive/10 text-destructive';
</script>

<template>
    <Head title="Auditlog" />
    <div class="mx-auto w-full max-w-[1200px] space-y-6 p-4 sm:p-6">
        <header>
            <h1 class="text-2xl font-semibold tracking-tight">Auditlog</h1>
            <p class="mt-1 text-sm text-muted-foreground">
                Anmeldungen, Datenexporte, Dokumentabrufe, Ansichten von
                Mitgliedsdaten sowie Änderungen an Mitgliedern, Konfiguration
                und Spenden in einer Liste. Sicherheitsereignisse werden
                {{ retentionDays }} Tage aufbewahrt.
            </p>
        </header>

        <section class="overflow-hidden rounded-xl border bg-card">
            <div
                class="grid gap-4 border-b p-5 md:grid-cols-2 xl:grid-cols-[minmax(0,1.5fr)_minmax(0,1fr)_10rem_10rem]"
            >
                <div class="min-w-0 space-y-2">
                    <Label for="audit-search">Suche</Label>
                    <div class="relative">
                        <Search
                            class="pointer-events-none absolute top-2.5 left-3 size-4 text-muted-foreground"
                        />
                        <Input
                            id="audit-search"
                            v-model="search"
                            type="search"
                            class="pl-9"
                            maxlength="100"
                            placeholder="Person, Aktion oder Objekt"
                        />
                    </div>
                </div>
                <div class="min-w-0 space-y-2">
                    <Label for="audit-area">Bereich</Label>
                    <SearchableDropdown
                        id="audit-area"
                        v-model="area"
                        :options="areaOptions"
                        placeholder="Alle Bereiche"
                        search-placeholder="Bereich suchen"
                        empty-text="Kein Bereich gefunden"
                        aria-label="Bereich filtern"
                        trigger-class="h-9 w-full rounded-md border border-input bg-background px-3"
                    />
                </div>
                <div class="min-w-0 space-y-2">
                    <Label for="audit-from">Von</Label>
                    <Input
                        id="audit-from"
                        v-model="from"
                        type="date"
                        class="date-safe"
                    />
                </div>
                <div class="min-w-0 space-y-2">
                    <Label for="audit-to">Bis</Label>
                    <Input
                        id="audit-to"
                        v-model="to"
                        type="date"
                        class="date-safe"
                        :min="from || undefined"
                    />
                </div>
            </div>
            <div
                class="flex flex-wrap items-center justify-between gap-3 border-b px-5 py-3"
            >
                <p class="text-sm text-muted-foreground">
                    {{ entries.total }} Einträge
                </p>
                <div class="flex flex-wrap gap-2">
                    <Button
                        v-for="format in ['csv', 'xlsx', 'pdf'] as const"
                        :key="format"
                        as-child
                        variant="outline"
                        size="sm"
                        ><a :href="exportUrl(format)"
                            ><Download class="size-4" />{{
                                format === 'xlsx'
                                    ? 'Excel'
                                    : format.toUpperCase()
                            }}</a
                        ></Button
                    >
                </div>
            </div>
            <div v-if="!entries.data.length" class="p-10 text-center">
                <p class="font-medium">Keine Einträge gefunden</p>
                <p class="mt-1 text-sm text-muted-foreground">
                    Passe die Filter an.
                </p>
            </div>
            <div v-else class="overflow-x-auto">
                <table class="w-full min-w-[900px] text-sm">
                    <thead
                        class="bg-muted/50 text-left text-xs text-muted-foreground"
                    >
                        <tr>
                            <th class="px-5 py-3 font-medium">Zeitpunkt</th>
                            <th class="px-3 py-3 font-medium">Bereich</th>
                            <th class="px-3 py-3 font-medium">Aktion</th>
                            <th class="px-3 py-3 font-medium">Person</th>
                            <th class="px-3 py-3 font-medium">Objekt</th>
                            <th class="px-5 py-3 font-medium">Details</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr
                            v-for="entry in entries.data"
                            :key="entry.id"
                            class="align-top"
                        >
                            <td class="px-5 py-3 whitespace-nowrap">
                                {{ formatDateTime(entry.occurred_at) }}
                            </td>
                            <td class="px-3 py-3">{{ entry.area }}</td>
                            <td class="px-3 py-3">
                                <span class="font-medium">{{
                                    entry.action
                                }}</span>
                                <Badge
                                    v-if="entry.outcome !== 'Erfolgreich'"
                                    variant="outline"
                                    class="ml-2"
                                    :class="outcomeClass(entry.outcome)"
                                    >{{ entry.outcome }}</Badge
                                >
                            </td>
                            <td class="px-3 py-3">{{ entry.actor }}</td>
                            <td class="px-3 py-3">
                                {{ entry.subject || '–' }}
                            </td>
                            <td
                                class="max-w-sm px-5 py-3 break-words text-muted-foreground"
                            >
                                {{ entry.details || '–' }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div
                v-if="entries.last_page > 1"
                class="flex flex-wrap items-center justify-between gap-3 border-t px-5 py-3 text-sm"
            >
                <span class="text-muted-foreground"
                    >Seite {{ entries.current_page }} von
                    {{ entries.last_page }}</span
                >
                <div class="flex gap-2">
                    <Button
                        v-if="entries.prev_page_url"
                        as-child
                        variant="outline"
                        size="sm"
                        ><Link :href="entries.prev_page_url" preserve-scroll
                            >Zurück</Link
                        ></Button
                    >
                    <Button
                        v-if="entries.next_page_url"
                        as-child
                        variant="outline"
                        size="sm"
                        ><Link :href="entries.next_page_url" preserve-scroll
                            >Weiter</Link
                        ></Button
                    >
                </div>
            </div>
        </section>
    </div>
</template>
