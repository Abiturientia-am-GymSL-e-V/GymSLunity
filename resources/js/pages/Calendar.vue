<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import {
    CalendarPlus,
    ChevronLeft,
    ChevronRight,
    Copy,
    Link2,
    Plus,
    Settings2,
    Trash2,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
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
import { Textarea } from '@/components/ui/textarea';

type Rule = { id?: number; field_key: string; value: string };
type ClubCalendar = {
    id: number;
    name: string;
    color: string;
    type: 'birthdays' | 'general' | 'custom';
    public_url: string | null;
    rules: Rule[];
};
type CalendarEvent = {
    id: string;
    event_id: number | null;
    calendar_id: number;
    calendar_name: string;
    color: string;
    title: string;
    location: string | null;
    description: string | null;
    starts_at: string;
    ends_at: string;
    all_day: boolean;
    editable: boolean;
};
type ShareField = {
    key: string;
    label: string;
    options: { value: string; label: string }[];
};

const props = defineProps<{
    month: string;
    calendars: ClubCalendar[];
    events: CalendarEvent[];
    shareFields: ShareField[];
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Kalender', href: '/kalender' }] },
});

const pad = (value: number) => String(value).padStart(2, '0');
const isoDate = (date: Date) =>
    `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
const parseDate = (value: string) => new Date(`${value.slice(0, 10)}T00:00:00`);
const monthDate = computed(() => new Date(`${props.month}-01T00:00:00`));
const monthTitle = computed(() =>
    new Intl.DateTimeFormat('de-DE', { month: 'long', year: 'numeric' }).format(
        monthDate.value,
    ),
);
const days = computed(() => {
    const first = new Date(monthDate.value);
    first.setDate(first.getDate() - ((first.getDay() + 6) % 7));
    return Array.from({ length: 42 }, (_, index) => {
        const date = new Date(first);
        date.setDate(first.getDate() + index);
        return {
            date: isoDate(date),
            day: date.getDate(),
            current: date.getMonth() === monthDate.value.getMonth(),
            today: isoDate(date) === isoDate(new Date()),
        };
    });
});
const visible = ref<Record<number, boolean>>(
    Object.fromEntries(props.calendars.map((calendar) => [calendar.id, true])),
);
const eventsOn = (date: string) =>
    props.events.filter((event) => {
        if (!visible.value[event.calendar_id]) return false;
        const day = parseDate(date);
        const next = new Date(day);
        next.setDate(day.getDate() + 1);
        return (
            new Date(event.starts_at) < next && new Date(event.ends_at) > day
        );
    });
function changeMonth(offset: number) {
    const target = new Date(monthDate.value);
    target.setMonth(target.getMonth() + offset);
    router.get(
        '/kalender',
        { month: `${target.getFullYear()}-${pad(target.getMonth() + 1)}` },
        { preserveState: true, preserveScroll: true },
    );
}
function goToday() {
    const today = new Date();
    router.get(
        '/kalender',
        { month: `${today.getFullYear()}-${pad(today.getMonth() + 1)}` },
        { preserveState: true, preserveScroll: true },
    );
}
const formatTime = (value: string) => value.slice(11, 16);

const eventOpen = ref(false);
const selectedEvent = ref<CalendarEvent | null>(null);
const writableCalendars = computed(() =>
    props.calendars.filter((calendar) => calendar.type !== 'birthdays'),
);
const eventForm = useForm({
    calendar_id: writableCalendars.value[0]?.id ?? 0,
    title: '',
    location: '',
    description: '',
    starts_at: '',
    ends_at: '',
    all_day: false,
});
function openCreate(date = isoDate(new Date())) {
    selectedEvent.value = null;
    eventForm.clearErrors();
    eventForm.defaults({
        calendar_id: writableCalendars.value[0]?.id ?? 0,
        title: '',
        location: '',
        description: '',
        starts_at: `${date}T18:00`,
        ends_at: `${date}T19:00`,
        all_day: false,
    });
    eventForm.reset();
    eventOpen.value = true;
}
function openEvent(event: CalendarEvent) {
    if (!event.editable) return;
    selectedEvent.value = event;
    let end = event.ends_at.slice(0, 16);
    if (event.all_day) {
        const inclusive = new Date(event.ends_at);
        inclusive.setDate(inclusive.getDate() - 1);
        end = `${isoDate(inclusive)}T00:00`;
    }
    eventForm.clearErrors();
    eventForm.defaults({
        calendar_id: event.calendar_id,
        title: event.title,
        location: event.location ?? '',
        description: event.description ?? '',
        starts_at: event.starts_at.slice(0, 16),
        ends_at: end,
        all_day: event.all_day,
    });
    eventForm.reset();
    eventOpen.value = true;
}
function saveEvent() {
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            eventOpen.value = false;
            selectedEvent.value = null;
        },
    };
    if (selectedEvent.value?.event_id) {
        eventForm.patch(
            `/kalender/termine/${selectedEvent.value.event_id}`,
            options,
        );
    } else {
        eventForm.post('/kalender/termine', options);
    }
}
function deleteEvent() {
    if (
        !selectedEvent.value?.event_id ||
        !confirm('Diesen Termin wirklich löschen?')
    )
        return;
    router.delete(`/kalender/termine/${selectedEvent.value.event_id}`, {
        preserveScroll: true,
        onSuccess: () => {
            eventOpen.value = false;
            selectedEvent.value = null;
        },
    });
}

const manageOpen = ref(false);
const managedId = ref(props.calendars[0]?.id ?? 0);
const managed = computed(
    () =>
        props.calendars.find((calendar) => calendar.id === managedId.value) ??
        null,
);
const calendarForm = useForm({ name: '', color: '#2563eb' });
const ruleForm = useForm<{ rules: Rule[] }>({ rules: [] });
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
function openManage(id?: number) {
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
function changeRuleField(rule: Rule) {
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
</script>

<template>
    <Head title="Kalender" />
    <div class="mx-auto w-full max-w-[1500px] space-y-5 p-4 sm:p-6">
        <header class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h1 class="text-2xl font-semibold tracking-tight">
                    Vereinskalender
                </h1>
                <p class="mt-1 text-sm text-muted-foreground">
                    Termine zentral planen und zielgerichtet mit Mitgliedern
                    teilen.
                </p>
            </div>
            <div class="flex gap-2">
                <Button variant="outline" @click="openManage()"
                    ><Settings2 class="size-4" />Kalender verwalten</Button
                >
                <Button
                    :disabled="!writableCalendars.length"
                    @click="openCreate()"
                    ><CalendarPlus class="size-4" />Termin anlegen</Button
                >
            </div>
        </header>

        <div class="grid gap-5 lg:grid-cols-[15rem_minmax(0,1fr)]">
            <aside class="space-y-4 rounded-xl border bg-card p-4">
                <div class="flex items-center justify-between">
                    <h2 class="font-medium">Kalender</h2>
                    <button
                        class="rounded p-1 text-muted-foreground hover:bg-muted hover:text-foreground"
                        aria-label="Kalender verwalten"
                        @click="openManage()"
                    >
                        <Settings2 class="size-4" />
                    </button>
                </div>
                <ul class="space-y-2">
                    <li
                        v-for="calendar in calendars"
                        :key="calendar.id"
                        class="flex items-center gap-2 text-sm"
                    >
                        <Checkbox
                            :model-value="visible[calendar.id]"
                            @update:model-value="
                                visible[calendar.id] = Boolean($event)
                            "
                        />
                        <span
                            class="size-3 rounded-full"
                            :style="{ backgroundColor: calendar.color }"
                        ></span>
                        <span class="min-w-0 flex-1 truncate">{{
                            calendar.name
                        }}</span>
                    </li>
                </ul>
                <Button
                    class="w-full"
                    variant="outline"
                    size="sm"
                    @click="addCalendar"
                    ><Plus class="size-4" />Kalender anlegen</Button
                >
                <p class="text-xs text-muted-foreground">
                    Geburtstage werden automatisch aus den Mitgliedsdaten
                    ergänzt.
                </p>
            </aside>

            <section class="min-w-0 overflow-hidden rounded-xl border bg-card">
                <div
                    class="flex flex-wrap items-center justify-between gap-3 border-b p-4"
                >
                    <div class="flex items-center gap-2">
                        <Button variant="outline" size="sm" @click="goToday"
                            >Heute</Button
                        >
                        <Button
                            variant="ghost"
                            size="icon"
                            aria-label="Vorheriger Monat"
                            @click="changeMonth(-1)"
                            ><ChevronLeft class="size-5"
                        /></Button>
                        <Button
                            variant="ghost"
                            size="icon"
                            aria-label="Nächster Monat"
                            @click="changeMonth(1)"
                            ><ChevronRight class="size-5"
                        /></Button>
                    </div>
                    <h2 class="text-lg font-semibold capitalize">
                        {{ monthTitle }}
                    </h2>
                    <div class="w-[7.5rem]"></div>
                </div>
                <div class="overflow-x-auto">
                    <div class="min-w-[700px]">
                        <div
                            class="grid grid-cols-7 border-b bg-muted/30 text-center text-xs font-medium text-muted-foreground"
                        >
                            <div
                                v-for="weekday in [
                                    'Mo',
                                    'Di',
                                    'Mi',
                                    'Do',
                                    'Fr',
                                    'Sa',
                                    'So',
                                ]"
                                :key="weekday"
                                class="py-2"
                            >
                                {{ weekday }}
                            </div>
                        </div>
                        <div class="grid grid-cols-7">
                            <div
                                v-for="day in days"
                                :key="day.date"
                                role="button"
                                tabindex="0"
                                class="min-h-28 border-r border-b p-1.5 text-left transition-colors hover:bg-muted/40 focus-visible:z-10 focus-visible:outline-ring"
                                :class="{
                                    'bg-muted/20 text-muted-foreground':
                                        !day.current,
                                }"
                                @click="openCreate(day.date)"
                                @keydown.enter="openCreate(day.date)"
                            >
                                <span
                                    class="mb-1 grid size-7 place-content-center rounded-full text-xs"
                                    :class="
                                        day.today
                                            ? 'bg-primary font-semibold text-primary-foreground'
                                            : ''
                                    "
                                    >{{ day.day }}</span
                                >
                                <span class="space-y-1">
                                    <button
                                        v-for="event in eventsOn(
                                            day.date,
                                        ).slice(0, 4)"
                                        :key="event.id"
                                        type="button"
                                        class="block w-full truncate rounded px-1.5 py-1 text-left text-[11px] font-medium text-white shadow-sm"
                                        :style="{
                                            backgroundColor: event.color,
                                        }"
                                        :title="event.title"
                                        @click.stop="openEvent(event)"
                                    >
                                        <span v-if="!event.all_day"
                                            >{{
                                                formatTime(event.starts_at)
                                            }} </span
                                        >{{ event.title }}
                                    </button>
                                    <span
                                        v-if="eventsOn(day.date).length > 4"
                                        class="block px-1 text-[11px] text-muted-foreground"
                                        >+
                                        {{ eventsOn(day.date).length - 4 }}
                                        weitere</span
                                    >
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            </section>
        </div>
    </div>

    <Dialog v-model:open="eventOpen">
        <DialogContent class="sm:max-w-xl">
            <DialogHeader
                ><DialogTitle>{{
                    selectedEvent ? 'Termin bearbeiten' : 'Termin anlegen'
                }}</DialogTitle
                ><DialogDescription
                    >Titel, Zeitraum und weitere Angaben zum
                    Termin.</DialogDescription
                ></DialogHeader
            >
            <form class="grid gap-4 sm:grid-cols-2" @submit.prevent="saveEvent">
                <div class="space-y-2 sm:col-span-2">
                    <Label for="event-title">Titel</Label
                    ><Input
                        id="event-title"
                        v-model="eventForm.title"
                        required
                        autofocus
                    /><InputError :message="eventForm.errors.title" />
                </div>
                <div class="space-y-2">
                    <Label for="event-calendar">Kalender</Label
                    ><select
                        id="event-calendar"
                        v-model="eventForm.calendar_id"
                        class="h-9 w-full rounded-md border bg-background px-3 text-sm"
                    >
                        <option
                            v-for="calendar in writableCalendars"
                            :key="calendar.id"
                            :value="calendar.id"
                        >
                            {{ calendar.name }}
                        </option></select
                    ><InputError :message="eventForm.errors.calendar_id" />
                </div>
                <div class="space-y-2">
                    <Label for="event-location">Ort</Label
                    ><Input id="event-location" v-model="eventForm.location" />
                </div>
                <label class="flex items-center gap-2 sm:col-span-2"
                    ><Checkbox v-model="eventForm.all_day" />
                    <span class="text-sm">Ganztägig</span></label
                >
                <div class="space-y-2">
                    <Label for="event-start">Beginn</Label
                    ><Input
                        v-if="eventForm.all_day"
                        id="event-start"
                        :model-value="eventForm.starts_at.slice(0, 10)"
                        type="date"
                        required
                        @update:model-value="
                            eventForm.starts_at = `${String($event)}T00:00`
                        "
                    /><Input
                        v-else
                        id="event-start"
                        v-model="eventForm.starts_at"
                        type="datetime-local"
                        required
                    /><InputError :message="eventForm.errors.starts_at" />
                </div>
                <div class="space-y-2">
                    <Label for="event-end">Ende</Label
                    ><Input
                        v-if="eventForm.all_day"
                        id="event-end"
                        :model-value="eventForm.ends_at.slice(0, 10)"
                        type="date"
                        required
                        @update:model-value="
                            eventForm.ends_at = `${String($event)}T00:00`
                        "
                    /><Input
                        v-else
                        id="event-end"
                        v-model="eventForm.ends_at"
                        type="datetime-local"
                        required
                    /><InputError :message="eventForm.errors.ends_at" />
                </div>
                <div class="space-y-2 sm:col-span-2">
                    <Label for="event-description">Beschreibung</Label
                    ><Textarea
                        id="event-description"
                        v-model="eventForm.description"
                        rows="4"
                    /><InputError :message="eventForm.errors.description" />
                </div>
                <DialogFooter class="sm:col-span-2 sm:justify-between">
                    <Button
                        v-if="selectedEvent"
                        type="button"
                        variant="destructive"
                        @click="deleteEvent"
                        ><Trash2 class="size-4" />Löschen</Button
                    ><span v-else></span>
                    <div class="flex gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            @click="eventOpen = false"
                            >Abbrechen</Button
                        ><Button :disabled="eventForm.processing"
                            >Speichern</Button
                        >
                    </div>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>

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
