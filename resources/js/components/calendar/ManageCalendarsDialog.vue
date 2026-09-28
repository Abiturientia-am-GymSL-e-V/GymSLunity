<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { Copy, Link2, Plus, Trash2 } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
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
function addCalendar() {
    const form = useForm({ name: 'Neuer Kalender', color: '#16a34a' });
    form.post('/kalender', {
        preserveScroll: true,
        onSuccess: () => {
            managedId.value = props.calendars.at(-1)?.id ?? managedId.value;
        },
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
                <select
                    v-model="managedId"
                    class="h-9 min-w-0 flex-1 rounded-md border bg-background px-3 text-sm"
                >
                    <option
                        v-for="calendar in calendars"
                        :key="calendar.id"
                        :value="calendar.id"
                    >
                        {{ calendar.name }}
                    </option></select
                ><Button variant="outline" @click="addCalendar"
                    ><Plus class="size-4" />Neu</Button
                >
            </div>
            <template v-if="managed">
                <form
                    class="grid gap-3 rounded-lg border p-4 sm:grid-cols-[1fr_5rem_auto]"
                    @submit.prevent="saveCalendar"
                >
                    <div class="space-y-2">
                        <Label>Name</Label
                        ><Input
                            v-model="calendarForm.name"
                            required
                        /><InputError :message="calendarForm.errors.name" />
                    </div>
                    <div class="space-y-2">
                        <Label>Farbe</Label
                        ><Input
                            v-model="calendarForm.color"
                            type="color"
                            class="px-1"
                        />
                    </div>
                    <div class="flex items-end">
                        <Button :disabled="calendarForm.processing"
                            >Speichern</Button
                        >
                    </div>
                </form>
                <section class="space-y-3 rounded-lg border p-4">
                    <div>
                        <h3 class="font-medium">Öffentlicher Abo-Link</h3>
                        <p class="text-sm text-muted-foreground">
                            Jede Person mit diesem Link kann den Kalender
                            abonnieren.
                        </p>
                    </div>
                    <div v-if="managed.public_url" class="flex gap-2">
                        <Input
                            :model-value="managed.public_url"
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
                        <select
                            v-model="rule.field_key"
                            class="h-9 rounded-md border bg-background px-3 text-sm"
                            @change="changeRuleField(rule)"
                        >
                            <option
                                v-for="item in shareFields"
                                :key="item.key"
                                :value="item.key"
                            >
                                {{ item.label }}
                            </option>
                        </select>
                        <select
                            v-model="rule.value"
                            class="h-9 rounded-md border bg-background px-3 text-sm"
                        >
                            <option
                                v-for="option in field(rule.field_key)
                                    ?.options ?? []"
                                :key="option.value"
                                :value="option.value"
                            >
                                {{ option.label }}
                            </option>
                        </select>
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            aria-label="Regel entfernen"
                            @click="ruleForm.rules.splice(index, 1)"
                            ><Trash2 class="size-4"
                        /></Button>
                    </div>
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
