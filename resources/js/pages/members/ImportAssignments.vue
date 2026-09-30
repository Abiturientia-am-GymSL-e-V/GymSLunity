<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import {
    AlertCircle,
    AlertTriangle,
    CheckCircle2,
    Download,
    FileSpreadsheet,
    Upload,
} from '@lucide/vue';
import { computed } from 'vue';
import InputError from '@/components/InputError.vue';
import MemberPageHeader from '@/components/members/MemberPageHeader.vue';
import MembersNav from '@/components/members/MembersNav.vue';
import StatusAlert from '@/components/StatusAlert.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { assignmentPeriod } from '@/lib/memberFormatting';
import { index as members } from '@/routes/members';
import {
    index,
    preview as previewRoute,
    store,
    template,
} from '@/routes/members/assignment-import';
import type { MemberField } from '@/types/members';

type ImportRow = {
    line: number;
    member_number: number | null;
    name: string | null;
    field_label: string;
    type: 'department' | 'office' | 'honor' | null;
    option_label: string;
    starts_on: string | null;
    ends_on: string | null;
    note: string | null;
    errors: string[];
    warnings: string[];
};

const props = defineProps<{
    totalMembers: number;
    fields: MemberField[];
    columns: string[];
    preview: { token: string; rows: ImportRow[]; errorCount: number } | null;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Mitglieder', href: members() },
            { title: 'Zuordnungen importieren', href: index() },
        ],
    },
});

const uploadForm = useForm<{ csv: File | null }>({ csv: null });
const importForm = useForm<{ token: string }>({
    token: props.preview?.token ?? '',
});
const importError = computed(
    () => (importForm.errors as Record<string, string>).form,
);
const validCount = computed(
    () => (props.preview?.rows.length ?? 0) - (props.preview?.errorCount ?? 0),
);
const typeLabels: Record<string, string> = {
    department: 'Abteilung',
    office: 'Funktion / Amt',
    honor: 'Ereignis / Ehrung',
};

function chooseFile(event: Event) {
    uploadForm.csv = (event.target as HTMLInputElement).files?.[0] ?? null;
}
function checkFile() {
    uploadForm.post(previewRoute.url());
}
function runImport() {
    if (!props.preview || props.preview.errorCount > 0) return;
    importForm.token = props.preview.token;
    importForm.post(store.url());
}
</script>

<template>
    <Head title="Zuordnungen importieren" />
    <div class="mx-auto w-full max-w-[1200px] space-y-6 p-4 sm:p-6">
        <MemberPageHeader
            :total-members="totalMembers"
            description="Übernimm Abteilungen, Ämter und Ehrungen mit Zeiträumen aus einer CSV-Datei, etwa frühere Vorstände."
        />

        <MembersNav />

        <StatusAlert
            v-if="importError"
            type="error"
            title="Import fehlgeschlagen"
        >
            {{ importError }}
        </StatusAlert>

        <StatusAlert
            v-if="!fields.length"
            type="info"
            title="Noch keine Felder für Zuordnungen"
        >
            Lege unter Konfiguration → Mitgliedsfelder ein Feld vom Typ
            Abteilung, Funktion / Amt oder Ereignis / Ehrung an.
        </StatusAlert>

        <section
            class="rounded-xl border bg-card"
            aria-labelledby="assignment-template-title"
        >
            <div
                class="flex flex-wrap items-center justify-between gap-3 border-b px-5 py-4"
            >
                <h2
                    id="assignment-template-title"
                    class="text-sm font-semibold"
                >
                    1. Aufbau der CSV-Datei
                </h2>
                <Button as-child variant="outline" size="sm">
                    <a :href="template.url()" download
                        ><Download class="size-4" />Leere CSV herunterladen</a
                    >
                </Button>
            </div>
            <div class="space-y-3 p-5 text-sm">
                <p>
                    Eine Zeile ist eine Zuordnung eines vorhandenen Mitglieds.
                    Spalten:
                    <span class="font-mono text-xs">{{
                        columns.join(' ; ')
                    }}</span>
                </p>
                <ul class="list-disc space-y-1 pl-5 text-muted-foreground">
                    <li>
                        „Feld“ und „Auswahl“ als Bezeichnung oder technischer
                        Wert, Groß- und Kleinschreibung egal.
                    </li>
                    <li>
                        „Von“ und „Bis“ als TT.MM.JJJJ oder JJJJ-MM-TT. Leeres
                        „Von“ bedeutet Beginn unbekannt, leeres „Bis“ eine
                        laufende Zuordnung. Bei Ehrungen ist „Von“ das Datum,
                        „Bis“ bleibt leer.
                    </li>
                    <li>
                        Es gelten dieselben Regeln wie in der Mitgliederakte:
                        Dubletten und unzulässige Überschneidungen werden
                        abgelehnt, auch innerhalb der Datei.
                    </li>
                </ul>
            </div>
            <div v-if="fields.length" class="overflow-x-auto border-t">
                <table class="w-full min-w-[640px] text-left text-sm">
                    <thead
                        class="border-b bg-muted/30 text-xs text-muted-foreground"
                    >
                        <tr>
                            <th class="px-5 py-3">Feld</th>
                            <th class="px-5 py-3">Art</th>
                            <th class="px-5 py-3">Aktive Auswahl</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="field in fields" :key="field.key">
                            <td class="px-5 py-3">{{ field.label }}</td>
                            <td class="px-5 py-3 text-muted-foreground">
                                {{ typeLabels[field.type] ?? field.type }}
                            </td>
                            <td class="px-5 py-3 text-muted-foreground">
                                {{
                                    Object.values(field.activeOptions).join(
                                        ', ',
                                    ) || '–'
                                }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section
            class="rounded-xl border bg-card"
            aria-labelledby="assignment-upload-title"
        >
            <div class="border-b px-5 py-4">
                <h2 id="assignment-upload-title" class="text-sm font-semibold">
                    2. CSV-Datei prüfen
                </h2>
            </div>
            <form class="space-y-3 p-5" @submit.prevent="checkFile">
                <Label for="assignment-import-csv">CSV-Datei</Label>
                <div class="flex flex-col gap-3 sm:flex-row">
                    <Input
                        id="assignment-import-csv"
                        type="file"
                        accept=".csv,text/csv"
                        :disabled="uploadForm.processing"
                        :aria-invalid="!!uploadForm.errors.csv"
                        @change="chooseFile"
                    />
                    <Button
                        type="submit"
                        :disabled="!uploadForm.csv || uploadForm.processing"
                    >
                        <Spinner v-if="uploadForm.processing" /><Upload
                            v-else
                            class="size-4"
                        />Vorschau erstellen
                    </Button>
                </div>
                <p class="text-xs text-muted-foreground">
                    CSV bis 2 MB und maximal 2.000 Zeilen. Semikolon, Komma und
                    Tabulator werden automatisch erkannt.
                </p>
                <InputError :message="uploadForm.errors.csv" />
            </form>
        </section>

        <section
            v-if="preview"
            class="rounded-xl border bg-card"
            aria-labelledby="assignment-preview-title"
        >
            <div
                class="flex flex-wrap items-center justify-between gap-3 border-b px-5 py-4"
            >
                <div>
                    <h2
                        id="assignment-preview-title"
                        class="text-sm font-semibold"
                    >
                        3. Vorschau prüfen und importieren
                    </h2>
                    <p class="mt-1 text-xs text-muted-foreground">
                        {{ preview.rows.length }} Zeilen geprüft ·
                        {{ validCount }} gültig ·
                        {{ preview.errorCount }} fehlerhaft
                    </p>
                </div>
                <Badge
                    :variant="preview.errorCount ? 'destructive' : 'secondary'"
                >
                    {{
                        preview.errorCount
                            ? 'Korrektur erforderlich'
                            : 'Bereit zum Import'
                    }}
                </Badge>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[820px] text-left text-sm">
                    <thead
                        class="border-b bg-muted/30 text-xs text-muted-foreground"
                    >
                        <tr>
                            <th class="px-5 py-3">CSV-Zeile</th>
                            <th class="px-5 py-3">Mitglied</th>
                            <th class="px-5 py-3">Zuordnung</th>
                            <th class="px-5 py-3">Zeitraum</th>
                            <th class="px-5 py-3">Prüfung</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr
                            v-for="row in preview.rows"
                            :key="row.line"
                            :class="row.errors.length ? 'bg-destructive/5' : ''"
                        >
                            <td class="px-5 py-3 tabular-nums">
                                {{ row.line }}
                            </td>
                            <td class="px-5 py-3">
                                <template v-if="row.member_number"
                                    >{{ row.name }}
                                    <span
                                        class="text-xs text-muted-foreground"
                                        >{{ row.member_number }}</span
                                    ></template
                                ><template v-else>—</template>
                            </td>
                            <td class="px-5 py-3">
                                <p>{{ row.option_label || '—' }}</p>
                                <p class="text-xs text-muted-foreground">
                                    {{ row.field_label }}
                                </p>
                            </td>
                            <td class="px-5 py-3 whitespace-nowrap">
                                {{
                                    assignmentPeriod(row, row.type ?? undefined)
                                }}
                            </td>
                            <td class="px-5 py-3">
                                <ul
                                    v-if="row.errors.length"
                                    class="space-y-1 text-destructive"
                                >
                                    <li
                                        v-for="error in row.errors"
                                        :key="error"
                                        class="flex items-start gap-1"
                                    >
                                        <AlertCircle
                                            class="mt-0.5 size-4 shrink-0"
                                        />{{ error }}
                                    </li>
                                </ul>
                                <template v-else>
                                    <span
                                        class="inline-flex items-center gap-1 text-emerald-700 dark:text-emerald-300"
                                        ><CheckCircle2
                                            class="size-4"
                                        />Gültig</span
                                    >
                                    <ul
                                        v-if="row.warnings.length"
                                        class="mt-1 space-y-1 text-xs text-amber-700 dark:text-amber-300"
                                    >
                                        <li
                                            v-for="warning in row.warnings"
                                            :key="warning"
                                            class="flex items-start gap-1"
                                        >
                                            <AlertTriangle
                                                class="mt-0.5 size-3.5 shrink-0"
                                            />{{ warning }}
                                        </li>
                                    </ul>
                                </template>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div
                class="flex flex-wrap items-center justify-between gap-3 border-t p-5"
            >
                <Button as-child variant="outline"
                    ><Link :href="index.url()"
                        ><FileSpreadsheet class="size-4" />Andere Datei
                        wählen</Link
                    ></Button
                >
                <div class="text-right">
                    <p
                        v-if="preview.errorCount"
                        class="mb-2 text-xs text-destructive"
                    >
                        Behebe alle Fehler in der CSV und erstelle anschließend
                        eine neue Vorschau. Importiert wird nur vollständig.
                    </p>
                    <Button
                        :disabled="
                            preview.errorCount > 0 || importForm.processing
                        "
                        @click="runImport"
                    >
                        <Spinner v-if="importForm.processing" /><CheckCircle2
                            v-else
                            class="size-4"
                        />{{ validCount }} Zuordnungen importieren
                    </Button>
                </div>
            </div>
        </section>
    </div>
</template>
