<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import {
    ArrowRightLeft,
    CalendarX2,
    Pencil,
    Plus,
    Save,
    Trash2,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import SearchableDropdown from '@/components/SearchableDropdown.vue';
import { Badge } from '@/components/ui/badge';
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
import { assignmentPeriod } from '@/lib/memberFormatting';
import {
    destroy,
    end,
    store,
    switchMethod,
    update,
} from '@/routes/members/assignments';
import type { AssignmentField, MemberAssignment } from '@/types/members';

const props = defineProps<{
    fields: AssignmentField[];
    assignments: Record<string, MemberAssignment[]>;
    memberNumber: number;
    lockVersion: number;
    returnTo: string;
    canEdit: boolean;
    /** While the master data form is open, assignments stay read-only. */
    disabled: boolean;
}>();

type Mode = 'add' | 'end' | 'switch' | 'correct' | 'delete';
const TYPE_LABELS: Record<string, string> = {
    department: 'Abteilung',
    office: 'Funktion / Amt',
    honor: 'Ereignis / Ehrung',
};
const today = localDateString();
const open = ref(false);
const mode = ref<Mode>('add');
const field = ref<AssignmentField | null>(null);
const current = ref<MemberAssignment | null>(null);
const form = useForm({
    field_key: '',
    option: '',
    starts_on: '',
    ends_on: '',
    note: '',
    lock_version: 0,
    return_to: '',
});

function listFor(key: string): MemberAssignment[] {
    return props.assignments[key] ?? [];
}
/** Open and upcoming assignments; honors are all shown, newest first. */
function currentFor(item: AssignmentField): MemberAssignment[] {
    const list = listFor(item.key);
    if (item.type === 'honor') return [...list].reverse();

    return list.filter((entry) => !entry.ends_on || entry.ends_on >= today);
}
function formerFor(item: AssignmentField): MemberAssignment[] {
    if (item.type === 'honor') return [];

    return listFor(item.key)
        .filter((entry) => entry.ends_on && entry.ends_on < today)
        .reverse();
}
function optionFor(item: AssignmentField, value: string) {
    return item.optionDetails.find((option) => option.value === value);
}
function upcoming(entry: MemberAssignment): boolean {
    return entry.starts_on !== null && entry.starts_on > today;
}
const editable = computed(() => props.canEdit && !props.disabled);
const choices = computed(() => {
    if (!field.value) return [];
    const keep = mode.value === 'correct' ? current.value?.option : undefined;

    return field.value.optionDetails
        .filter(
            (option) =>
                (option.active || option.value === keep) &&
                !(mode.value === 'switch' && option.value === keep),
        )
        .map((option) => ({ value: option.value, label: option.label }));
});
const title = computed(() => {
    const label = field.value?.label ?? '';

    return {
        add: `${label}: Zuordnung hinzufügen`,
        end: 'Zuordnung beenden',
        switch: 'Zuordnung wechseln',
        correct: 'Zuordnung korrigieren',
        delete: 'Zuordnung löschen',
    }[mode.value];
});
const isHonor = computed(() => field.value?.type === 'honor');
/** Errors that belong to no single input, e.g. a concurrent change. */
const generalError = computed(() => {
    const errors: Record<string, string | undefined> = form.errors;

    return errors.lock_version || errors.form || errors.field_key;
});

function start(
    nextMode: Mode,
    item: AssignmentField,
    entry: MemberAssignment | null = null,
) {
    mode.value = nextMode;
    field.value = item;
    current.value = entry;
    form.defaults({
        field_key: item.key,
        option: nextMode === 'switch' ? '' : (entry?.option ?? ''),
        starts_on:
            nextMode === 'add' ? (item.type === 'honor' ? today : '') : '',
        ends_on: nextMode === 'end' || nextMode === 'switch' ? today : '',
        note: nextMode === 'correct' ? (entry?.note ?? '') : '',
        lock_version: props.lockVersion,
        return_to: props.returnTo,
    });
    if (nextMode === 'correct' && entry) {
        form.defaults({
            ...form.data(),
            starts_on: entry.starts_on ?? '',
            ends_on: entry.ends_on ?? '',
        });
    }
    form.reset();
    form.clearErrors();
    open.value = true;
}
function close(value: boolean) {
    if (!value && form.processing) return;
    open.value = value;
}
function submit() {
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
        },
    };
    const args = {
        member: props.memberNumber,
        assignment: current.value?.id ?? 0,
    };
    if (mode.value === 'add')
        form.post(store.url({ member: props.memberNumber }), options);
    else if (mode.value === 'end') form.patch(end.url(args), options);
    else if (mode.value === 'switch')
        form.patch(switchMethod.url(args), options);
    else if (mode.value === 'correct') form.patch(update.url(args), options);
    else form.delete(destroy.url(args), options);
}
</script>

<template>
    <section
        v-if="fields.length"
        class="rounded-xl border bg-card"
        aria-labelledby="assignments-title"
        data-test="member-assignments"
    >
        <div class="border-b px-5 py-4">
            <h2 id="assignments-title" class="text-sm font-semibold">
                Abteilungen, Ämter &amp; Ehrungen
            </h2>
            <p
                v-if="canEdit && disabled"
                class="mt-1 text-sm text-muted-foreground"
            >
                Zuordnungen lassen sich bearbeiten, sobald die Stammdaten
                gespeichert oder verworfen sind.
            </p>
        </div>
        <div class="divide-y">
            <div
                v-for="item in fields"
                :key="item.key"
                class="space-y-3 p-5"
                :data-assignment-field="item.key"
            >
                <div class="flex flex-wrap items-start justify-between gap-2">
                    <div class="min-w-0">
                        <h3 class="font-medium break-words">
                            {{ item.label }}
                        </h3>
                        <p class="text-xs text-muted-foreground">
                            {{ TYPE_LABELS[item.type]
                            }}{{ item.readOnly ? ' · deaktiviert' : '' }}
                        </p>
                    </div>
                    <Button
                        v-if="editable && !item.readOnly"
                        type="button"
                        size="sm"
                        variant="outline"
                        :aria-label="`${item.label}: Zuordnung hinzufügen`"
                        @click="start('add', item)"
                        ><Plus class="size-4" />Hinzufügen</Button
                    >
                </div>
                <p
                    v-if="!currentFor(item).length"
                    class="text-sm text-muted-foreground"
                >
                    {{
                        item.type === 'honor'
                            ? 'Keine Ehrungen erfasst.'
                            : 'Derzeit keine Zuordnung.'
                    }}
                </p>
                <ul v-else class="space-y-3">
                    <li
                        v-for="entry in currentFor(item)"
                        :key="entry.id"
                        class="flex flex-wrap items-start gap-x-4 gap-y-2"
                    >
                        <div class="min-w-0 flex-1 basis-48">
                            <p
                                class="flex flex-wrap items-center gap-2 text-sm font-medium break-words"
                            >
                                {{ item.options[entry.option] || entry.option
                                }}<Badge
                                    v-if="optionFor(item, entry.option)?.board"
                                    variant="secondary"
                                    >Vorstand</Badge
                                ><Badge v-if="upcoming(entry)" variant="outline"
                                    >künftig</Badge
                                >
                            </p>
                            <p class="text-sm text-muted-foreground">
                                {{ assignmentPeriod(entry, item.type) }}
                            </p>
                            <p
                                v-if="entry.note"
                                class="text-sm break-words text-muted-foreground"
                            >
                                {{ entry.note }}
                            </p>
                        </div>
                        <div
                            v-if="editable && !item.readOnly"
                            class="flex flex-wrap gap-1"
                        >
                            <template v-if="item.type !== 'honor'">
                                <Button
                                    v-if="!entry.ends_on"
                                    type="button"
                                    size="sm"
                                    variant="ghost"
                                    @click="start('end', item, entry)"
                                    ><CalendarX2
                                        class="size-4"
                                    />Beenden</Button
                                >
                                <Button
                                    v-if="!entry.ends_on"
                                    type="button"
                                    size="sm"
                                    variant="ghost"
                                    @click="start('switch', item, entry)"
                                    ><ArrowRightLeft
                                        class="size-4"
                                    />Wechseln</Button
                                >
                            </template>
                            <Button
                                type="button"
                                size="icon-sm"
                                variant="ghost"
                                :aria-label="`${item.options[entry.option] || entry.option} korrigieren`"
                                @click="start('correct', item, entry)"
                                ><Pencil class="size-4"
                            /></Button>
                            <Button
                                type="button"
                                size="icon-sm"
                                variant="ghost"
                                :aria-label="`${item.options[entry.option] || entry.option} löschen`"
                                @click="start('delete', item, entry)"
                                ><Trash2 class="size-4"
                            /></Button>
                        </div>
                    </li>
                </ul>
                <details v-if="formerFor(item).length" class="text-sm">
                    <summary
                        class="cursor-pointer rounded font-medium text-muted-foreground outline-offset-4 hover:text-foreground"
                    >
                        {{ `Frühere anzeigen (${formerFor(item).length})` }}
                    </summary>
                    <ul class="mt-3 space-y-3">
                        <li
                            v-for="entry in formerFor(item)"
                            :key="entry.id"
                            class="flex flex-wrap items-start gap-x-4 gap-y-2"
                        >
                            <div class="min-w-0 flex-1 basis-48">
                                <p class="font-medium break-words">
                                    {{
                                        item.options[entry.option] ||
                                        entry.option
                                    }}
                                </p>
                                <p class="text-muted-foreground">
                                    {{ assignmentPeriod(entry, item.type) }}
                                </p>
                                <p
                                    v-if="entry.note"
                                    class="break-words text-muted-foreground"
                                >
                                    {{ entry.note }}
                                </p>
                            </div>
                            <div
                                v-if="editable && !item.readOnly"
                                class="flex flex-wrap gap-1"
                            >
                                <Button
                                    type="button"
                                    size="icon-sm"
                                    variant="ghost"
                                    :aria-label="`${item.options[entry.option] || entry.option} korrigieren`"
                                    @click="start('correct', item, entry)"
                                    ><Pencil class="size-4"
                                /></Button>
                                <Button
                                    type="button"
                                    size="icon-sm"
                                    variant="ghost"
                                    :aria-label="`${item.options[entry.option] || entry.option} löschen`"
                                    @click="start('delete', item, entry)"
                                    ><Trash2 class="size-4"
                                /></Button>
                            </div>
                        </li>
                    </ul>
                </details>
            </div>
        </div>
    </section>

    <Dialog :open="open" @update:open="close">
        <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-lg">
            <DialogHeader>
                <DialogTitle>{{ title }}</DialogTitle>
                <DialogDescription v-if="mode === 'switch'"
                    >Die bisherige Zuordnung endet am gewählten Tag, die neue
                    beginnt am Folgetag.</DialogDescription
                >
                <DialogDescription v-else-if="mode === 'delete'"
                    >Löschen ist für Erfassungsfehler gedacht. Die Zuordnung
                    bleibt in der Änderungshistorie sichtbar. Endet sie regulär,
                    bitte stattdessen beenden.</DialogDescription
                >
                <DialogDescription v-else-if="mode === 'correct'"
                    >Korrigiert eine falsch erfasste Zuordnung. Die Änderung
                    wird in der Historie protokolliert.</DialogDescription
                >
                <DialogDescription v-else>{{
                    isHonor
                        ? 'Datum der Ehrung angeben.'
                        : 'Ein leerer Beginn bedeutet „unbekannt“, ein leeres Ende „läuft noch“.'
                }}</DialogDescription>
            </DialogHeader>
            <form
                class="space-y-5"
                data-test="assignment-form"
                @submit.prevent="submit"
            >
                <InputError :message="generalError" role="alert" />
                <p v-if="mode === 'delete' && current" class="text-sm">
                    {{
                        `${field?.options[current.option] || current.option} (${assignmentPeriod(current, field?.type)})`
                    }}
                </p>
                <div
                    v-if="
                        mode === 'add' ||
                        mode === 'correct' ||
                        mode === 'switch'
                    "
                    class="min-w-0 space-y-2"
                >
                    <Label for="assignment-option">{{
                        mode === 'switch' ? 'Neue Auswahl' : 'Auswahl'
                    }}</Label>
                    <SearchableDropdown
                        id="assignment-option"
                        :model-value="form.option || null"
                        :options="choices"
                        placeholder="Bitte auswählen"
                        search-placeholder="Auswahl durchsuchen"
                        empty-text="Keine passende Auswahl"
                        :aria-label="field?.label"
                        trigger-class="h-9 w-full rounded-md border border-input bg-background px-3"
                        @update:model-value="form.option = $event"
                    />
                    <InputError :message="form.errors.option" />
                </div>
                <div v-if="mode !== 'delete'" class="grid gap-5 sm:grid-cols-2">
                    <div
                        v-if="mode === 'add' || mode === 'correct'"
                        class="min-w-0 space-y-2"
                        :class="{ 'sm:col-span-2': isHonor }"
                    >
                        <Label for="assignment-start">{{
                            isHonor ? 'Datum' : 'Beginn'
                        }}</Label>
                        <Input
                            id="assignment-start"
                            v-model="form.starts_on"
                            type="date"
                            :max="isHonor ? today : undefined"
                        />
                        <InputError :message="form.errors.starts_on" />
                    </div>
                    <div v-if="!isHonor" class="min-w-0 space-y-2">
                        <Label for="assignment-end">{{
                            mode === 'switch'
                                ? 'Bisherige Zuordnung endet am'
                                : 'Ende'
                        }}</Label>
                        <Input
                            id="assignment-end"
                            v-model="form.ends_on"
                            type="date"
                            :required="mode === 'end' || mode === 'switch'"
                        />
                        <InputError :message="form.errors.ends_on" />
                    </div>
                    <div
                        v-if="
                            mode === 'add' ||
                            mode === 'correct' ||
                            mode === 'switch'
                        "
                        class="min-w-0 space-y-2 sm:col-span-2"
                    >
                        <Label for="assignment-note">Notiz</Label>
                        <Input
                            id="assignment-note"
                            v-model="form.note"
                            maxlength="255"
                            placeholder="z. B. Wahl in der Mitgliederversammlung"
                        />
                        <InputError :message="form.errors.note" />
                    </div>
                </div>
                <DialogFooter>
                    <Button
                        type="button"
                        variant="outline"
                        :disabled="form.processing"
                        @click="close(false)"
                        >Abbrechen</Button
                    >
                    <Button
                        :variant="mode === 'delete' ? 'destructive' : 'default'"
                        :disabled="form.processing"
                        data-test="save-assignment"
                        ><Spinner v-if="form.processing" /><Trash2
                            v-else-if="mode === 'delete'"
                            class="size-4"
                        /><Save v-else class="size-4" />{{
                            mode === 'delete' ? 'Löschen' : 'Speichern'
                        }}</Button
                    >
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
