<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { Copy, Link2, Plus, Trash2 } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import { firstError } from '@/lib/formErrors';
import SearchableDropdown from '@/components/SearchableDropdown.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type {
    CalendarShareField,
    CalendarShareRule,
    ClubCalendar,
} from '@/types/calendar';
const props = defineProps<{
    calendars: ClubCalendar[];
    shareFields: CalendarShareField[];
}>();
const manageOpen = defineModel<boolean>('open', { required: true });

const managedId = ref(props.calendars[0]?.id ?? 0);
const managed = computed(
    () =>
        props.calendars.find((calendar) => calendar.id === managedId.value) ??
        null,
);
const calendarForm = useForm({ name: '', color: '#2563eb' });
const ruleForm = useForm<{ rules: CalendarShareRule[] }>({ rules: [] });
const creating = ref(false);
const calendarOptions = computed(() =>
    props.calendars.map((calendar) => ({
        value: String(calendar.id),
        label: calendar.name,
    })),
);
const shareFieldOptions = computed(() =>
    props.shareFields.map((item) => ({ value: item.key, label: item.label })),
);
function loadManaged() {
    if (!managed.value) return;
    calendarForm.defaults({
        name: managed.value.name,
        color: managed.value.color,
    });
    calendarForm.reset();
    calendarForm.clearErrors();
    ruleForm.defaults({
        rules: managed.value.rules.map(({ field_key, value }) => ({
            field_key,
            value,
        })),
    });
    ruleForm.reset();
    ruleForm.clearErrors();
}
watch(managedId, loadManaged);
/** Open the dialog, optionally with a specific calendar selected. */
function open(id?: number) {
    if (id) managedId.value = id;
    loadManaged();
    manageOpen.value = true;
}
function saveCalendar() {
    if (managed.value)
        calendarForm.patch(`/kalender/${managed.value.id}`, {
            preserveScroll: true,
        });
}
function selectManaged(value: string) {
    managedId.value = Number(value);
}
function addCalendar() {
    const usedNames = new Set(props.calendars.map((calendar) => calendar.name));
    let number = 1;
    let name = 'Neuer Kalender';
    while (usedNames.has(name)) {
        number++;
        name = `Neuer Kalender ${number}`;
    }
    const colors = [
        '#16a34a',
        '#7c3aed',
        '#ea580c',
        '#0891b2',
        '#dc2626',
        '#4f46e5',
    ];
    const customCount = props.calendars.filter(
        (calendar) => calendar.type === 'custom',
    ).length;
    const form = useForm({
        name,
        color: colors[customCount % colors.length],
    });
    creating.value = true;
    form.post('/kalender', {
        preserveScroll: true,
        onSuccess: () => {
            managedId.value = Math.max(
                ...props.calendars.map((calendar) => calendar.id),
            );
        },
        onFinish: () => (creating.value = false),
    });
}
function removeCalendar() {
    if (
        !managed.value ||
        managed.value.type !== 'custom' ||
        !confirm(`„${managed.value.name}“ und alle Termine wirklich löschen?`)
    )
        return;
    router.delete(`/kalender/${managed.value.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            managedId.value = props.calendars[0]?.id ?? 0;
        },
    });
}
function addRule() {
    const field = props.shareFields[0];
    if (!field) return;
    ruleForm.rules.push({
        field_key: field.key,
        value: field.options[0]?.value ?? '',
    });
}
function field(key: string) {
    return props.shareFields.find((item) => item.key === key);
}
function changeRuleField(rule: CalendarShareRule) {
    rule.value = field(rule.field_key)?.options[0]?.value ?? '';
}
function selectRuleField(rule: CalendarShareRule, value: string) {
    rule.field_key = value;
    changeRuleField(rule);
}
function selectRuleValue(rule: CalendarShareRule, value: string) {
    rule.value = value;
}
function ruleValueOptions(rule: CalendarShareRule) {
    return (field(rule.field_key)?.options ?? []).map((option) => ({
        value: option.value,
        label: option.label,
    }));
}
function saveRules() {
    if (managed.value)
        ruleForm.put(`/kalender/${managed.value.id}/freigaben`, {
            preserveScroll: true,
        });
}
function createPublicLink() {
    if (managed.value)
        router.post(
            `/kalender/${managed.value.id}/abo-link`,
            {},
            { preserveScroll: true },
        );
}
function removePublicLink() {
    if (
        managed.value &&
        confirm(
            'Den öffentlichen Abo-Link deaktivieren? Bereits eingerichtete Abos funktionieren danach nicht mehr.',
        )
    )
        router.delete(`/kalender/${managed.value.id}/abo-link`, {
            preserveScroll: true,
        });
}
function copyLink(value: string) {
    void window.navigator.clipboard.writeText(value);
}

defineExpose({ open, add: addCalendar });
</script>

<template>
    <Dialog v-model:open="manageOpen">
        <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
            <DialogHeader
                ><DialogTitle>Kalender verwalten</DialogTitle
                ><DialogDescription
                    >Darstellung, Abo-Link und Freigaben für Mitglieder
                    festlegen.</DialogDescription
                ></DialogHeader
            >
            <div class="flex gap-2">
                <SearchableDropdown
                    id="managed-calendar"
                    :model-value="String(managedId)"
                    :options="calendarOptions"
                    root-class="min-w-0 flex-1"
                    trigger-class="h-9 w-full rounded-md border border-input bg-background px-3 shadow-xs"
                    aria-label="Kalender zur Verwaltung auswählen"
                    search-placeholder="Kalender suchen"
                    empty-text="Kein Kalender gefunden"
                    @update:model-value="selectManaged"
                />
                <Button
                    type="button"
                    variant="outline"
                    :disabled="creating"
                    @click="addCalendar"
                    ><Plus class="size-4" />Neu</Button
                >
            </div>
            <template v-if="managed">
                <form
                    class="grid gap-5 rounded-lg border p-4 sm:grid-cols-[minmax(0,1fr)_5rem_auto]"
                    @submit.prevent="saveCalendar"
                >
                    <div class="min-w-0 space-y-2">
                        <Label for="calendar-name">Name</Label
                        ><Input
                            id="calendar-name"
                            v-model="calendarForm.name"
                            required
                        /><InputError :message="calendarForm.errors.name" />
                    </div>
                    <div class="min-w-0 space-y-2">
                        <Label for="calendar-color">Farbe</Label
                        ><Input
                            id="calendar-color"
                            v-model="calendarForm.color"
                            type="color"
                            class="px-1"
                        />
                    </div>
                    <Button
                        class="h-9 sm:self-end"
                        :disabled="calendarForm.processing"
                        >Speichern</Button
                    >
                </form>
                <section
                    v-if="managed.type !== 'birthdays'"
                    class="space-y-3 rounded-lg border p-4"
                >
                    <div>
                        <h3 class="font-medium">Öffentlicher Abo-Link</h3>
                        <p class="text-sm text-muted-foreground">
                            Jede Person mit diesem Link kann den Kalender
                            abonnieren.
                        </p>
                    </div>
                    <div v-if="managed.public_url" class="flex flex-wrap gap-2">
                        <Input
                            :model-value="managed.public_url"
                            class="min-w-0 flex-1"
                            readonly
                        /><Button
                            variant="outline"
                            size="icon"
                            title="Link kopieren"
                            @click="copyLink(managed.public_url)"
                            ><Copy class="size-4" /></Button
                        ><Button variant="outline" @click="createPublicLink"
                            >Neu erzeugen</Button
                        ><Button variant="destructive" @click="removePublicLink"
                            >Deaktivieren</Button
                        >
                    </div>
                    <Button v-else variant="outline" @click="createPublicLink"
                        ><Link2 class="size-4" />Abo-Link erzeugen</Button
                    >
                </section>
                <form
                    class="space-y-3 rounded-lg border p-4"
                    @submit.prevent="saveRules"
                >
                    <div>
                        <h3 class="font-medium">
                            Freigabe nach Mitgliedseigenschaften
                        </h3>
                        <p class="text-sm text-muted-foreground">
                            Ein Mitglied erhält Zugriff, sobald mindestens eine
                            der Regeln zutrifft. Sein persönlicher Sammel-Link
                            bündelt alle passenden Kalender.
                        </p>
                    </div>
                    <div
                        v-for="(rule, index) in ruleForm.rules"
                        :key="index"
                        class="grid gap-2 sm:grid-cols-[1fr_1fr_auto]"
                    >
                        <SearchableDropdown
                            :id="`calendar-rule-field-${index}`"
                            :model-value="rule.field_key"
                            :options="shareFieldOptions"
                            trigger-class="h-9 w-full rounded-md border border-input bg-background px-3 shadow-xs"
                            aria-label="Mitgliedseigenschaft auswählen"
                            search-placeholder="Eigenschaft suchen"
                            empty-text="Keine Eigenschaft gefunden"
                            @update:model-value="selectRuleField(rule, $event)"
                        />
                        <SearchableDropdown
                            :id="`calendar-rule-value-${index}`"
                            :model-value="rule.value"
                            :options="ruleValueOptions(rule)"
                            trigger-class="h-9 w-full rounded-md border border-input bg-background px-3 shadow-xs"
                            aria-label="Feldwert auswählen"
                            search-placeholder="Feldwert suchen"
                            empty-text="Kein Feldwert gefunden"
                            @update:model-value="selectRuleValue(rule, $event)"
                        />
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            aria-label="Regel entfernen"
                            @click="ruleForm.rules.splice(index, 1)"
                            ><Trash2 class="size-4"
                        /></Button>
                        <InputError
                            class="sm:col-span-3"
                            :message="
                                firstError(ruleForm.errors, `rules.${index}`)
                            "
                        />
                    </div>
                    <InputError :message="ruleForm.errors.rules" />
                    <div class="flex flex-wrap justify-between gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            :disabled="!shareFields.length"
                            @click="addRule"
                            ><Plus class="size-4" />Regel hinzufügen</Button
                        ><Button :disabled="ruleForm.processing"
                            >Freigaben speichern</Button
                        >
                    </div>
                </form>
                <div v-if="managed.type === 'custom'" class="flex justify-end">
                    <Button variant="destructive" @click="removeCalendar"
                        ><Trash2 class="size-4" />Kalender löschen</Button
                    >
                </div>
            </template>
        </DialogContent>
    </Dialog>
</template>
