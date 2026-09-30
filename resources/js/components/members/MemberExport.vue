<script setup lang="ts">
import { computed, ref } from 'vue';
import { Download, Printer } from '@lucide/vue';
import StatusAlert from '@/components/StatusAlert.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { saveBlob, xsrfToken } from '@/lib/download';
import { address } from '@/lib/formOfAddress';
import type { MemberColumn } from './columns';
import type { MemberField, MemberFilters } from '@/types/members';
import { localDateString } from '@/lib/format';

const props = defineProps<{
    filters: MemberFilters;
    selected: number[];
    columns: MemberColumn[];
    allColumns: MemberColumn[];
    fields: MemberField[];
    total: number;
    disabled: boolean;
}>();
const format = ref('csv');
const scope = ref('selected');
const columnScope = ref<'visible' | 'all'>('visible');
const busy = ref(false);
const error = ref('');
const exportScope = computed(() =>
    props.selected.length ? scope.value : 'filtered',
);
const exportColumns = computed(() =>
    columnScope.value === 'all' ? props.allColumns : props.columns,
);
const columnKeys = computed(() => {
    const groups: Record<string, string[]> = {
        number: ['member_number'],
        name: ['last_name', 'first_name', 'middle_name'],
        contact: ['email', 'mobile_phone'],
        location: ['postal_code', 'city'],
        membership: ['membership_type'],
        address: ['street', 'country'],
        honorary: ['is_honorary'],
    };
    const active = new Set([
        'member_number',
        ...props.fields.map((field) => field.key),
    ]);
    return exportColumns.value
        .flatMap((column) => groups[column.key] || [column.key])
        .filter((key) => active.has(key));
});

async function download(requestedFormat = format.value) {
    busy.value = true;
    error.value = '';
    const preview =
        requestedFormat === 'print' ? window.open('', '_blank') : null;
    if (preview) {
        preview.opener = null;
        preview.document.body.textContent = 'Druckansicht wird geladen …';
    }
    try {
        const response = await fetch('/mitglieder/export', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-XSRF-TOKEN': xsrfToken(),
            },
            body: JSON.stringify({
                ...props.filters,
                format: requestedFormat,
                scope: exportScope.value,
                selected: props.selected,
                columns: columnKeys.value,
            }),
        });
        if (
            !response.ok ||
            response.redirected ||
            (requestedFormat !== 'print' &&
                !response.headers
                    .get('Content-Disposition')
                    ?.includes('attachment'))
        ) {
            const failure =
                response.status === 422
                    ? ((await response.json()) as {
                          errors?: Record<string, string[]>;
                      })
                    : null;
            throw new Error(
                response.status === 419 || response.redirected
                    ? address(
                          'Bitte melde dich erneut an und starte den Export noch einmal.',
                          'Bitte melden Sie sich erneut an und starten Sie den Export noch einmal.',
                      )
                    : failure?.errors?.scope?.[0] ||
                          address(
                              'Der Export ist fehlgeschlagen. Bitte lade die Liste neu und versuche es erneut.',
                              'Der Export ist fehlgeschlagen. Bitte laden Sie die Liste neu und versuchen Sie es erneut.',
                          ),
            );
        }
        const blob = await response.blob();
        if (requestedFormat === 'print') {
            if (!preview)
                throw new Error(
                    'Der Browser hat das Druckfenster blockiert. Bitte Pop-ups für diese Seite erlauben.',
                );
            const url = URL.createObjectURL(blob);
            preview.location.href = url;
            setTimeout(() => URL.revokeObjectURL(url), 60000);
            return;
        }
        saveBlob(blob, `mitglieder-${localDateString()}.${requestedFormat}`);
    } catch (cause) {
        if (preview && !preview.closed) preview.close();
        error.value =
            cause instanceof Error
                ? cause.message
                : 'Der Export konnte nicht geladen werden.';
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <div class="flex flex-col gap-2">
        <div class="flex flex-wrap items-center gap-2">
            <Label class="sr-only" for="export-scope">Exportumfang</Label>
            <select
                id="export-scope"
                :value="exportScope"
                :disabled="disabled || busy"
                class="h-9 rounded-md border bg-background px-2 text-sm"
                @change="scope = ($event.target as HTMLSelectElement).value"
            >
                <option v-if="selected.length" value="selected">
                    Auswahl ({{ selected.length }})
                </option>
                <option value="filtered">Gefilterte Liste ({{ total }})</option>
            </select>
            <Label class="sr-only" for="export-columns">Spaltenumfang</Label>
            <select
                id="export-columns"
                v-model="columnScope"
                :disabled="disabled || busy"
                class="h-9 rounded-md border bg-background px-2 text-sm"
                data-test="export-columns"
            >
                <option value="visible">Nur sichtbare Spalten</option>
                <option value="all">Alle Spalten</option>
            </select>
            <Label class="sr-only" for="export-format">Dateiformat</Label>
            <select
                id="export-format"
                v-model="format"
                :disabled="disabled || busy"
                class="h-9 rounded-md border bg-background px-2 text-sm"
            >
                <option value="csv">CSV</option>
                <option value="json">JSON</option>
                <option value="xlsx">Excel (.xlsx)</option>
                <option value="pdf">PDF</option>
                <option value="docx">Word (.docx)</option>
            </select>
            <Button
                size="sm"
                variant="outline"
                :disabled="disabled || busy || !total"
                @click="download()"
                data-test="export-members"
                ><Download class="size-4" />{{
                    busy ? 'Wird exportiert …' : 'Exportieren'
                }}</Button
            >
            <Button
                size="sm"
                variant="outline"
                :disabled="disabled || busy || !total"
                @click="download('print')"
                data-test="print-members"
                ><Printer class="size-4" />Drucken</Button
            >
        </div>
        <p class="text-xs text-muted-foreground">
            {{
                columnScope === 'all'
                    ? 'Exportiert alle verfügbaren Spalten, auch über mehrere Seiten.'
                    : 'Exportiert nur die sichtbaren Spalten, auch über mehrere Seiten.'
            }}
        </p>
        <StatusAlert v-if="error" type="error" title="Export fehlgeschlagen">
            {{ error }}
        </StatusAlert>
    </div>
</template>
