<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { ListChecks, Plus, Trash2 } from '@lucide/vue';
import InputError from '@/components/InputError.vue';
import MemberFieldControl from '@/components/members/MemberFieldControl.vue';
import StatusAlert from '@/components/StatusAlert.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { isTemporal } from '@/lib/memberFormatting';
import { bulkUpdate } from '@/routes/members';
import type { MemberField, MemberValue } from '@/types/members';

const props = defineProps<{
    selected: number[];
    fields: MemberField[];
    configurationVersion: number;
}>();
const emit = defineEmits<{ updated: [] }>();

const open = ref(false);
const nextField = ref('');
const selectedFields = ref<string[]>([]);
const form = useForm<{
    members: number[];
    configuration_version: number;
    values: Record<string, MemberValue>;
}>({
    members: [],
    configuration_version: props.configurationVersion,
    values: {},
});
const availableFields = computed(() =>
    props.fields.filter(
        // Assignments change only in the member record.
        (field) =>
            !isTemporal(field) && !selectedFields.value.includes(field.key),
    ),
);
const editingFields = computed(() =>
    selectedFields.value
        .map((key) => props.fields.find((field) => field.key === key))
        .filter((field): field is MemberField => !!field),
);
const topError = computed(() => {
    const errors = form.errors as Record<string, string>;
    return errors.form || errors.members;
});

function initialValue(field: MemberField): MemberValue {
    if (field.type === 'boolean') return false;
    if (field.required && field.type === 'select')
        return Object.keys(field.activeOptions)[0] ?? null;
    return null;
}
function show() {
    form.reset();
    form.clearErrors();
    form.members = [...props.selected];
    form.configuration_version = props.configurationVersion;
    form.values = {};
    selectedFields.value = [];
    nextField.value = '';
    open.value = true;
}
function addField() {
    const field = props.fields.find((item) => item.key === nextField.value);
    if (!field || selectedFields.value.includes(field.key)) return;
    selectedFields.value.push(field.key);
    form.values[field.key] = initialValue(field);
    nextField.value = '';
}
function removeField(key: string) {
    selectedFields.value = selectedFields.value.filter(
        (field) => field !== key,
    );
    delete form.values[key];
    form.clearErrors(`values.${key}` as never);
}
function fieldError(key: string): string | undefined {
    return (form.errors as Record<string, string>)[`values.${key}`];
}
function close(value: boolean) {
    if (!value && !form.processing) open.value = false;
}
function submit() {
    form.members = [...props.selected];
    form.configuration_version = props.configurationVersion;
    form.patch(bulkUpdate.url(), {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
            emit('updated');
        },
    });
}
</script>

<template>
    <Button
        v-if="selected.length"
        type="button"
        size="sm"
        variant="outline"
        :title="
            selected.length > 250
                ? 'Es können höchstens 250 Mitglieder gleichzeitig bearbeitet werden.'
                : undefined
        "
        data-test="bulk-edit-members"
        @click="show"
    >
        <ListChecks class="size-4" />Gemeinsam bearbeiten
    </Button>
    <Dialog :open="open" @update:open="close">
        <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
            <DialogHeader>
                <DialogTitle
                    >{{ selected.length }} Mitglieder gemeinsam
                    bearbeiten</DialogTitle
                >
                <DialogDescription>
                    Nur hinzugefügte Felder werden bei allen ausgewählten
                    Mitgliedern überschrieben. Ein leerer Wert entfernt die
                    bisherige Angabe.
                </DialogDescription>
            </DialogHeader>

            <StatusAlert
                v-if="topError"
                type="error"
                title="Änderungen nicht gespeichert"
            >
                {{ topError }}
            </StatusAlert>
            <StatusAlert
                v-else-if="selected.length > 250"
                type="error"
                title="Zu viele Mitglieder ausgewählt"
            >
                Es können höchstens 250 Mitglieder gleichzeitig bearbeitet
                werden. Bitte verkleinere die Auswahl.
            </StatusAlert>

            <div class="flex items-end gap-2">
                <div class="min-w-0 flex-1 space-y-2">
                    <Label for="bulk-member-field">Feld hinzufügen</Label>
                    <select
                        id="bulk-member-field"
                        v-model="nextField"
                        class="h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
                        :disabled="form.processing || !availableFields.length"
                        @keydown.enter.prevent="addField"
                    >
                        <option value="" disabled>Feld auswählen …</option>
                        <option
                            v-for="field in availableFields"
                            :key="field.key"
                            :value="field.key"
                        >
                            {{ field.label }}
                        </option>
                    </select>
                </div>
                <Button
                    type="button"
                    variant="outline"
                    :disabled="!nextField || form.processing"
                    @click="addField"
                    ><Plus class="size-4" />Hinzufügen</Button
                >
            </div>

            <div v-if="editingFields.length" class="space-y-3">
                <div
                    v-for="field in editingFields"
                    :key="field.key"
                    class="rounded-lg border p-4"
                >
                    <div class="mb-2 flex items-center justify-between gap-3">
                        <Label :for="`member-${field.key}`">
                            {{ field.label
                            }}<span v-if="field.required" aria-hidden="true">
                                *</span
                            >
                        </Label>
                        <Button
                            type="button"
                            size="icon-sm"
                            variant="ghost"
                            :disabled="form.processing"
                            :aria-label="`${field.label} nicht bearbeiten`"
                            @click="removeField(field.key)"
                            ><Trash2 class="size-4"
                        /></Button>
                    </div>
                    <MemberFieldControl
                        :field="field"
                        :value="form.values[field.key]"
                        :values="form.values"
                        :disabled="form.processing"
                        :error="fieldError(field.key)"
                        @change="form.values[field.key] = $event"
                    />
                    <InputError
                        :id="`error-${field.key}`"
                        class="mt-2"
                        :message="fieldError(field.key)"
                    />
                </div>
            </div>
            <p
                v-else
                class="rounded-lg border border-dashed p-4 text-sm text-muted-foreground"
            >
                Füge mindestens ein Feld hinzu, das gemeinsam geändert werden
                soll.
            </p>

            <DialogFooter>
                <Button
                    type="button"
                    variant="outline"
                    :disabled="form.processing"
                    @click="open = false"
                    >Abbrechen</Button
                >
                <Button
                    type="button"
                    :disabled="
                        form.processing ||
                        !editingFields.length ||
                        selected.length > 250
                    "
                    data-test="submit-bulk-edit"
                    @click="submit"
                >
                    <Spinner v-if="form.processing" />
                    {{ selected.length }} Mitglieder aktualisieren
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
