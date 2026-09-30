<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { CalendarRange } from '@lucide/vue';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import SearchableDropdown from '@/components/SearchableDropdown.vue';
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
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { localDateString } from '@/lib/format';
import { isTemporal } from '@/lib/memberFormatting';
import { bulkAssign } from '@/routes/members';
import type { MemberField } from '@/types/members';

/**
 * Adds or ends one department, office or honor assignment for several
 * members. With a preset, field and option are fixed, e.g. to award an
 * honor to all selected jubilarians.
 */
const props = defineProps<{
    selected: number[];
    fields: MemberField[];
    configurationVersion: number;
    preset?: { field: string; option: string; note?: string };
    buttonLabel?: string;
}>();
const emit = defineEmits<{ done: [] }>();

const today = localDateString();
const open = ref(false);
const form = useForm({
    members: [] as number[],
    configuration_version: props.configurationVersion,
    action: 'add' as 'add' | 'end',
    field: '',
    option: '',
    starts_on: '',
    ends_on: '',
    note: '',
});
const temporalFields = computed(() => props.fields.filter(isTemporal));
const field = computed(() =>
    temporalFields.value.find((item) => item.key === form.field),
);
const isHonor = computed(() => field.value?.type === 'honor');
const fieldChoices = computed(() =>
    temporalFields.value
        .filter((item) => form.action === 'add' || item.type !== 'honor')
        .map((item) => ({ value: item.key, label: item.label })),
);
const optionChoices = computed(() => [
    ...(form.action === 'end' ? [{ value: '', label: 'Alle laufenden' }] : []),
    ...Object.entries(field.value?.activeOptions ?? {}).map(
        ([value, label]) => ({ value, label }),
    ),
]);
const topError = computed(() => {
    const errors = form.errors as Record<string, string>;
    return errors.form || errors.members;
});
const title = computed(() =>
    props.preset
        ? `${field.value?.options[props.preset.option] ?? props.preset.option} vergeben`
        : `Zuordnung für ${props.selected.length} Mitglieder`,
);

function show() {
    form.reset();
    form.clearErrors();
    form.configuration_version = props.configurationVersion;
    if (props.preset) {
        form.field = props.preset.field;
        form.option = props.preset.option;
        form.note = props.preset.note ?? '';
    }
    form.starts_on = today;
    form.ends_on = '';
    open.value = true;
}
function changeAction(action: 'add' | 'end') {
    form.action = action;
    form.option = '';
    form.ends_on = action === 'end' ? today : '';
    if (action === 'end' && isHonor.value) form.field = '';
    form.clearErrors();
}
function close(value: boolean) {
    if (!value && !form.processing) open.value = false;
}
function submit() {
    form.members = [...props.selected];
    form.transform((data) =>
        data.action === 'end'
            ? {
                  members: data.members,
                  configuration_version: data.configuration_version,
                  action: data.action,
                  field: data.field,
                  option: data.option,
                  ends_on: data.ends_on,
              }
            : { ...data, ends_on: isHonor.value ? '' : data.ends_on },
    ).post(bulkAssign.url(), {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
            emit('done');
        },
    });
}
</script>

<template>
    <Button
        v-if="selected.length && (preset || temporalFields.length)"
        type="button"
        size="sm"
        variant="outline"
        :title="
            selected.length > 250
                ? 'Es können höchstens 250 Mitglieder gleichzeitig bearbeitet werden.'
                : undefined
        "
        data-test="bulk-assign-members"
        @click="show"
    >
        <CalendarRange class="size-4" />{{ buttonLabel || 'Zuordnung' }}
    </Button>
    <Dialog :open="open" @update:open="close">
        <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-xl">
            <DialogHeader>
                <DialogTitle>{{ title }}</DialogTitle>
                <DialogDescription>
                    {{
                        preset
                            ? `Für ${selected.length === 1 ? '1 ausgewähltes Mitglied' : `${selected.length} ausgewählte Mitglieder`}.`
                            : 'Eine Abteilung, ein Amt oder eine Ehrung für alle ausgewählten Mitglieder hinzufügen oder laufende Zuordnungen beenden.'
                    }}
                    Jede Änderung erscheint in der Historie des Mitglieds.
                </DialogDescription>
            </DialogHeader>

            <StatusAlert
                v-if="topError"
                type="error"
                title="Nichts gespeichert"
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

            <form
                id="bulk-assign-form"
                class="grid gap-4 sm:grid-cols-2"
                @submit.prevent="submit"
            >
                <fieldset
                    v-if="!preset"
                    class="flex flex-wrap gap-2 sm:col-span-2"
                >
                    <legend class="sr-only">Aktion</legend>
                    <Button
                        v-for="item in [
                            { value: 'add', label: 'Hinzufügen' },
                            { value: 'end', label: 'Beenden' },
                        ] as const"
                        :key="item.value"
                        type="button"
                        size="sm"
                        :variant="
                            form.action === item.value ? 'default' : 'outline'
                        "
                        :aria-pressed="form.action === item.value"
                        @click="changeAction(item.value)"
                        >{{ item.label }}</Button
                    >
                </fieldset>
                <template v-if="!preset">
                    <div class="min-w-0 space-y-2">
                        <Label for="bulk-assign-field">Feld</Label>
                        <SearchableDropdown
                            id="bulk-assign-field"
                            :model-value="form.field || null"
                            :options="fieldChoices"
                            placeholder="Bitte auswählen"
                            search-placeholder="Feld suchen"
                            empty-text="Kein Feld gefunden."
                            aria-label="Feld"
                            trigger-class="h-9 w-full rounded-md border border-input bg-background px-3"
                            @update:model-value="
                                form.field = $event;
                                form.option = '';
                            "
                        />
                        <InputError :message="form.errors.field" />
                    </div>
                    <div class="min-w-0 space-y-2">
                        <Label for="bulk-assign-option">Auswahl</Label>
                        <SearchableDropdown
                            id="bulk-assign-option"
                            :model-value="form.option"
                            :options="optionChoices"
                            :disabled="!field"
                            placeholder="Bitte auswählen"
                            search-placeholder="Auswahl durchsuchen"
                            empty-text="Keine passende Auswahl"
                            aria-label="Auswahl"
                            trigger-class="h-9 w-full rounded-md border border-input bg-background px-3"
                            @update:model-value="form.option = $event"
                        />
                        <InputError :message="form.errors.option" />
                    </div>
                </template>
                <template v-if="form.action === 'add'">
                    <div
                        class="min-w-0 space-y-2"
                        :class="{ 'sm:col-span-2': isHonor }"
                    >
                        <Label for="bulk-assign-start">{{
                            isHonor ? 'Datum' : 'Beginn'
                        }}</Label>
                        <Input
                            id="bulk-assign-start"
                            v-model="form.starts_on"
                            type="date"
                            :max="isHonor ? today : undefined"
                        />
                        <InputError :message="form.errors.starts_on" />
                    </div>
                    <div v-if="!isHonor" class="min-w-0 space-y-2">
                        <Label for="bulk-assign-end">Ende (optional)</Label>
                        <Input
                            id="bulk-assign-end"
                            v-model="form.ends_on"
                            type="date"
                        />
                        <InputError :message="form.errors.ends_on" />
                    </div>
                    <div class="min-w-0 space-y-2 sm:col-span-2">
                        <Label for="bulk-assign-note">Notiz</Label>
                        <Input
                            id="bulk-assign-note"
                            v-model="form.note"
                            maxlength="255"
                            placeholder="z. B. Ehrung in der Mitgliederversammlung"
                        />
                        <InputError :message="form.errors.note" />
                    </div>
                </template>
                <div v-else class="min-w-0 space-y-2">
                    <Label for="bulk-assign-ends">Endet am</Label>
                    <Input
                        id="bulk-assign-ends"
                        v-model="form.ends_on"
                        type="date"
                        required
                    />
                    <InputError :message="form.errors.ends_on" />
                </div>
            </form>

            <DialogFooter>
                <Button
                    type="button"
                    variant="outline"
                    :disabled="form.processing"
                    @click="open = false"
                    >Abbrechen</Button
                >
                <Button
                    type="submit"
                    form="bulk-assign-form"
                    :disabled="
                        form.processing ||
                        !form.field ||
                        (form.action === 'add' && !form.option) ||
                        selected.length > 250
                    "
                    data-test="submit-bulk-assign"
                >
                    <Spinner v-if="form.processing" />
                    {{
                        form.action === 'end'
                            ? 'Zuordnungen beenden'
                            : `Für ${selected.length} ${selected.length === 1 ? 'Mitglied' : 'Mitglieder'} speichern`
                    }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
