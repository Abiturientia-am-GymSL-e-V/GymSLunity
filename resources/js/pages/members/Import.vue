<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import {
    AlertCircle,
    ArrowRight,
    CheckCircle2,
    Columns3,
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
import { firstError } from '@/lib/formErrors';
import { index as members } from '@/routes/members';
import {
    index,
    map as mapRoute,
    preview as previewRoute,
    store,
    template,
} from '@/routes/members/import';
import type { MemberField, MemberValue } from '@/types/members';

type ImportRow = {
    line: number;
    member_number: number | string | null;
    name: string;
    values: Record<string, MemberValue>;
    errors: string[];
};
type MappingEntry = { source: string; target: string | null };
type MappingState = {
    token: string;
    headers: string[];
    suggested: MappingEntry[];
    required: string[];
    delimiter: string;
    samples: {
        line: number;
        values: Record<string, string>;
        error: string | null;
    }[];
};

const props = defineProps<{
    totalMembers: number;
    fields: MemberField[];
    mapping: MappingState | null;
    preview: {
        token: string;
        rows: ImportRow[];
        errorCount: number;
        mapped: boolean;
    } | null;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Mitglieder', href: members() },
            { title: 'Mitglieder importieren', href: index() },
        ],
    },
});

const uploadForm = useForm<{ csv: File | null }>({ csv: null });
const importForm = useForm<{ token: string }>({
    token: props.preview?.token ?? '',
});
const mappingForm = useForm<{ token: string; mapping: MappingEntry[] }>({
    token: props.mapping?.token ?? '',
    mapping: props.mapping?.suggested.map((entry) => ({ ...entry })) ?? [],
});
const validCount = computed(
    () => (props.preview?.rows.length ?? 0) - (props.preview?.errorCount ?? 0),
);
const importError = computed(
    () => (importForm.errors as Record<string, string>).form,
);
const mappingError = computed(() => firstError(mappingForm.errors, 'mapping'));
const targetFields = computed(() => [
    { key: 'member_number', label: 'Mitgliedsnummer', required: true },
    ...props.fields.map((field) => ({
        key: field.key,
        label: field.label,
        required: required(field),
    })),
]);

function chooseFile(event: Event) {
    uploadForm.csv = (event.target as HTMLInputElement).files?.[0] ?? null;
}
function checkFile() {
    uploadForm.post(previewRoute.url());
}
function applyMapping() {
    if (!props.mapping) return;
    mappingForm.token = props.mapping.token;
    mappingForm.post(mapRoute.url());
}
function runImport() {
    if (!props.preview || props.preview.errorCount > 0) return;
    importForm.token = props.preview.token;
    importForm.post(store.url());
}
function syntax(field: MemberField): string {
    if (field.type === 'date') return 'JJJJ-MM-TT, z. B. 2026-09-22';
    if (field.type === 'boolean') return 'ja/nein, true/false oder 1/0';
    if (field.type === 'decimal') return 'Zahl mit Komma oder Punkt';
    if (field.type === 'number') return 'Ganze Zahl';
    if (field.type === 'select')
        return `Einer dieser Werte: ${Object.keys(field.activeOptions).join(', ')}`;
    if (field.type === 'email') return 'Gültige E-Mail-Adresse';
    return field.required ? 'Text, darf nicht leer sein' : 'Text oder leer';
}
function required(field: MemberField): boolean {
    return field.required || (field.type === 'boolean' && !field.custom);
}
function targetUsedByOther(target: string, index: number): boolean {
    return mappingForm.mapping.some(
        (entry, entryIndex) => entryIndex !== index && entry.target === target,
    );
}
function examples(header: string): string {
    const values = (props.mapping?.samples ?? [])
        .map((row) => row.values[header])
        .filter((value) => value !== undefined && value !== '')
        .slice(0, 3);
    return values.join(' · ') || 'Keine Beispielwerte';
}
</script>

<template>
    <Head title="Mitglieder importieren" />
    <div class="mx-auto w-full max-w-[1200px] space-y-6 p-4 sm:p-6">
        <MemberPageHeader
            :total-members="totalMembers"
            description="Übernimm mehrere Mitglieder kontrolliert aus einer CSV-Datei."
        />

        <MembersNav />

        <StatusAlert
            v-if="importError"
            type="error"
            title="Import fehlgeschlagen"
        >
            {{ importError }}
        </StatusAlert>

        <section
            class="rounded-xl border bg-card"
            aria-labelledby="template-title"
        >
            <div class="border-b px-5 py-4">
                <h2 id="template-title" class="text-sm font-semibold">
                    1. CSV-Vorlage herunterladen
                </h2>
            </div>
            <div class="flex flex-wrap items-center justify-between gap-4 p-5">
                <div>
                    <p class="text-sm">
                        Die Vorlage enthält die aktuell verwendeten
                        Mitgliedsfelder als Spalten.
                    </p>
                    <p class="mt-1 text-xs text-muted-foreground">
                        Die Vorlage kann direkt importiert werden. Bei Dateien
                        aus anderen Programmen
                        {{
                            $address(
                                'ordnest du die Spalten im nächsten Schritt manuell zu.',
                                'ordnen Sie die Spalten im nächsten Schritt manuell zu.',
                            )
                        }}
                    </p>
                </div>
                <Button as-child variant="outline">
                    <a :href="template.url()" download
                        ><Download class="size-4" />Leere CSV herunterladen</a
                    >
                </Button>
            </div>
        </section>

        <section
            class="rounded-xl border bg-card"
            aria-labelledby="syntax-title"
        >
            <div class="border-b px-5 py-4">
                <h2 id="syntax-title" class="text-sm font-semibold">
                    Hinweise zur Syntax
                </h2>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[700px] text-left text-sm">
                    <thead
                        class="border-b bg-muted/30 text-xs text-muted-foreground"
                    >
                        <tr>
                            <th class="px-5 py-3">Spalte</th>
                            <th class="px-5 py-3">Bezeichnung</th>
                            <th class="px-5 py-3">Erwarteter Wert</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr>
                            <td class="px-5 py-3 font-mono text-xs">
                                member_number
                            </td>
                            <td class="px-5 py-3">Mitgliedsnummer *</td>
                            <td class="px-5 py-3 text-muted-foreground">
                                Eindeutige positive ganze Zahl
                            </td>
                        </tr>
                        <tr v-for="field in fields" :key="field.key">
                            <td class="px-5 py-3 font-mono text-xs">
                                {{ field.key }}
                            </td>
                            <td class="px-5 py-3">
                                {{ field.label
                                }}{{ required(field) ? ' *' : '' }}
                            </td>
                            <td class="px-5 py-3 text-muted-foreground">
                                {{ syntax(field) }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p class="border-t px-5 py-3 text-xs text-muted-foreground">
                Leere Werte bleiben ungesetzt. Pflichtfelder sind mit *
                gekennzeichnet. Die Datei muss UTF-8-kodiert sein.
            </p>
        </section>

        <section
            class="rounded-xl border bg-card"
            aria-labelledby="upload-title"
        >
            <div class="border-b px-5 py-4">
                <h2 id="upload-title" class="text-sm font-semibold">
                    2. CSV-Datei auswählen
                </h2>
            </div>
            <form class="space-y-3 p-5" @submit.prevent="checkFile">
                <Label for="member-import-csv">CSV-Datei</Label>
                <div class="flex flex-col gap-3 sm:flex-row">
                    <Input
                        id="member-import-csv"
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
                    CSV bis 2 MB und maximal 1.000 Datenzeilen. Semikolon, Komma
                    und Tabulator werden automatisch erkannt.
                </p>
                <InputError :message="uploadForm.errors.csv" />
            </form>
        </section>

        <section
            v-if="mapping"
            class="rounded-xl border bg-card"
            aria-labelledby="mapping-title"
        >
            <div
                class="flex flex-wrap items-center justify-between gap-3 border-b px-5 py-4"
            >
                <div>
                    <h2 id="mapping-title" class="text-sm font-semibold">
                        3. CSV-Spalten zuordnen
                    </h2>
                    <p class="mt-1 text-xs text-muted-foreground">
                        {{ mapping.headers.length }} Spalten erkannt ·
                        Trennzeichen: {{ mapping.delimiter }} · Vorschläge
                        wurden vorausgewählt
                    </p>
                </div>
                <Badge variant="secondary"
                    ><Columns3 class="mr-1 size-3" />Manuelle Zuordnung</Badge
                >
            </div>
            <form @submit.prevent="applyMapping">
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[760px] text-left text-sm">
                        <thead
                            class="border-b bg-muted/30 text-xs text-muted-foreground"
                        >
                            <tr>
                                <th class="px-5 py-3">CSV-Spalte</th>
                                <th class="px-5 py-3">Beispielwerte</th>
                                <th class="px-5 py-3">GymSLunity-Feld</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr
                                v-for="(
                                    entry, entryIndex
                                ) in mappingForm.mapping"
                                :key="`${entry.source}-${entryIndex}`"
                            >
                                <td class="px-5 py-3 font-medium">
                                    {{ entry.source }}
                                </td>
                                <td
                                    class="max-w-sm truncate px-5 py-3 text-muted-foreground"
                                    :title="examples(entry.source)"
                                >
                                    {{ examples(entry.source) }}
                                </td>
                                <td class="px-5 py-3">
                                    <div class="flex items-center gap-2">
                                        <ArrowRight
                                            class="size-4 shrink-0 text-muted-foreground"
                                        />
                                        <select
                                            v-model="entry.target"
                                            class="h-9 min-w-64 rounded-md border border-input bg-background px-3 text-sm shadow-xs outline-none focus:border-ring focus:ring-2 focus:ring-ring/30"
                                        >
                                            <option value="">
                                                Nicht importieren
                                            </option>
                                            <option
                                                v-for="target in targetFields"
                                                :key="target.key"
                                                :value="target.key"
                                                :disabled="
                                                    targetUsedByOther(
                                                        target.key,
                                                        entryIndex,
                                                    )
                                                "
                                            >
                                                {{ target.label
                                                }}{{
                                                    target.required ? ' *' : ''
                                                }}
                                            </option>
                                        </select>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div
                    class="flex flex-wrap items-center justify-between gap-3 border-t p-5"
                >
                    <div>
                        <InputError :message="mappingError" />
                        <p class="text-xs text-muted-foreground">
                            * Pflichtfeld. Nicht benötigte CSV-Spalten können
                            ignoriert werden.
                        </p>
                    </div>
                    <div class="flex gap-2">
                        <Button as-child variant="outline"
                            ><Link :href="index.url()"
                                >Andere Datei</Link
                            ></Button
                        >
                        <Button
                            type="submit"
                            :disabled="mappingForm.processing"
                        >
                            <Spinner v-if="mappingForm.processing" /><ArrowRight
                                v-else
                                class="size-4"
                            />Zuordnung prüfen
                        </Button>
                    </div>
                </div>
            </form>
        </section>

        <section
            v-if="preview"
            class="rounded-xl border bg-card"
            aria-labelledby="preview-title"
        >
            <div
                class="flex flex-wrap items-center justify-between gap-3 border-b px-5 py-4"
            >
                <div>
                    <h2 id="preview-title" class="text-sm font-semibold">
                        {{ preview.mapped ? '4' : '3' }}. Vorschau prüfen und
                        importieren
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
                <table class="w-full min-w-[700px] text-left text-sm">
                    <thead
                        class="border-b bg-muted/30 text-xs text-muted-foreground"
                    >
                        <tr>
                            <th class="px-5 py-3">CSV-Zeile</th>
                            <th class="px-5 py-3">Mitgliedsnummer</th>
                            <th class="px-5 py-3">Name</th>
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
                            <td class="px-5 py-3 tabular-nums">
                                {{ row.member_number ?? '—' }}
                            </td>
                            <td class="px-5 py-3">{{ row.name || '—' }}</td>
                            <td class="px-5 py-3">
                                <span
                                    v-if="!row.errors.length"
                                    class="inline-flex items-center gap-1 text-emerald-700 dark:text-emerald-300"
                                    ><CheckCircle2 class="size-4" />Gültig</span
                                >
                                <ul v-else class="space-y-1 text-destructive">
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
                        eine neue Vorschau.
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
                        />{{ validCount }} Mitglieder importieren
                    </Button>
                </div>
            </div>
        </section>
    </div>
</template>
