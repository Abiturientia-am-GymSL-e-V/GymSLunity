<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ShieldCheck } from '@lucide/vue';
import { reactive } from 'vue';
import ConfigurationNav from '@/components/configuration/ConfigurationNav.vue';
import { Button } from '@/components/ui/button';

type Entry = {
    id: number;
    event: string;
    outcome: string;
    actor_name: string | null;
    subject_type: string | null;
    subject_id: string | null;
    context: Record<string, unknown> | null;
    created_at: string;
};

const props = defineProps<{
    entries: {
        data: Entry[];
        current_page: number;
        last_page: number;
        next_page_url: string | null;
        prev_page_url: string | null;
    };
    events: string[];
    filters: { event: string; outcome: string };
    retentionDays: number;
    identifierRetentionDays: number;
}>();
const filters = reactive({ ...props.filters });
const apply = () =>
    router.get('/konfiguration/sicherheitsprotokoll', filters, {
        preserveState: true,
        replace: true,
    });

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Konfiguration', href: '/konfiguration/verein' },
            { title: 'Sicherheitsprotokoll' },
        ],
    },
});
</script>

<template>
    <Head title="Sicherheitsprotokoll" />
    <div class="mx-auto w-full max-w-[1200px] space-y-6 p-4 sm:p-6">
        <header>
            <h1 class="text-2xl font-semibold tracking-tight">Konfiguration</h1>
            <p class="mt-1 text-sm text-muted-foreground">
                Nachvollziehbare sicherheitsrelevante Vorgänge ohne Passwörter,
                Tokens oder Dokumentinhalte.
            </p>
        </header>
        <ConfigurationNav />

        <div class="flex gap-3 rounded-xl border bg-card p-4 text-sm">
            <ShieldCheck class="mt-0.5 size-5 shrink-0 text-emerald-600" />
            <p class="text-muted-foreground">
                Ereignisse werden {{ retentionDays }} Tage aufbewahrt;
                pseudonyme IP- und Gerätekennungen werden nach
                {{ identifierRetentionDays }} Tagen entfernt.
            </p>
        </div>

        <form class="flex flex-wrap items-end gap-3" @submit.prevent="apply">
            <label class="grid gap-1 text-sm">
                <span class="font-medium">Ereignis</span>
                <select
                    v-model="filters.event"
                    class="h-9 rounded-md border bg-background px-3"
                >
                    <option value="">Alle</option>
                    <option v-for="event in events" :key="event" :value="event">
                        {{ event }}
                    </option>
                </select>
            </label>
            <label class="grid gap-1 text-sm">
                <span class="font-medium">Ergebnis</span>
                <select
                    v-model="filters.outcome"
                    class="h-9 rounded-md border bg-background px-3"
                >
                    <option value="">Alle</option>
                    <option value="success">Erfolgreich</option>
                    <option value="failed">Fehlgeschlagen</option>
                    <option value="rate_limited">Begrenzt</option>
                </select>
            </label>
            <Button type="submit" variant="outline">Filtern</Button>
        </form>

        <div class="overflow-x-auto rounded-xl border">
            <table class="w-full text-left text-sm">
                <thead
                    class="border-b bg-muted/40 text-xs text-muted-foreground"
                >
                    <tr>
                        <th class="p-3">Zeitpunkt</th>
                        <th class="p-3">Ereignis</th>
                        <th class="p-3">Ergebnis</th>
                        <th class="p-3">Akteur</th>
                        <th class="p-3">Kontext</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <tr v-for="entry in entries.data" :key="entry.id">
                        <td class="p-3 whitespace-nowrap">
                            {{
                                new Date(entry.created_at).toLocaleString(
                                    'de-DE',
                                )
                            }}
                        </td>
                        <td class="p-3 font-medium">{{ entry.event }}</td>
                        <td class="p-3">{{ entry.outcome }}</td>
                        <td class="p-3">
                            {{ entry.actor_name ?? 'Unbekannt / extern' }}
                        </td>
                        <td class="max-w-md p-3 text-xs text-muted-foreground">
                            {{
                                entry.context
                                    ? JSON.stringify(entry.context)
                                    : '–'
                            }}
                        </td>
                    </tr>
                    <tr v-if="!entries.data.length">
                        <td
                            colspan="5"
                            class="p-8 text-center text-muted-foreground"
                        >
                            Keine Ereignisse gefunden.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <div class="flex justify-between">
            <Button
                variant="outline"
                :disabled="!entries.prev_page_url"
                @click="
                    entries.prev_page_url && router.get(entries.prev_page_url)
                "
                >Zurück</Button
            >
            <span class="text-sm text-muted-foreground"
                >Seite {{ entries.current_page }} von
                {{ entries.last_page }}</span
            >
            <Button
                variant="outline"
                :disabled="!entries.next_page_url"
                @click="
                    entries.next_page_url && router.get(entries.next_page_url)
                "
                >Weiter</Button
            >
        </div>
    </div>
</template>
