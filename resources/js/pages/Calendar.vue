<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import {
    CalendarDays,
    CalendarPlus,
    ChevronLeft,
    ChevronRight,
    Download,
    List,
    Plus,
    Settings2,
    Trash2,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import ManageCalendarsDialog from '@/components/calendar/ManageCalendarsDialog.vue';
import InputError from '@/components/InputError.vue';
import SearchableDropdown from '@/components/SearchableDropdown.vue';
import StatusAlert from '@/components/StatusAlert.vue';
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
import type {
    CalendarEvent,
    CalendarShareField,
    ClubCalendar,
} from '@/types/calendar';

const props = defineProps<{
    month: string;
    view: 'month' | 'list';
    listFrom: string;
    listUntil: string;
    calendars: ClubCalendar[];
    events: CalendarEvent[];
    shareFields: CalendarShareField[];
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
const viewMode = ref<'month' | 'list'>(props.view);
const listFrom = ref(props.listFrom);
const listUntil = ref(props.listUntil);
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
const writableCalendarOptions = computed(() =>
    writableCalendars.value.map((calendar) => ({
        value: String(calendar.id),
        label: calendar.name,
    })),
);
const listedEvents = computed(() => {
    const from = parseDate(listFrom.value);
    const until = parseDate(listUntil.value);
    until.setDate(until.getDate() + 1);

    return props.events.filter(
        (event) =>
            visible.value[event.calendar_id] &&
            new Date(event.starts_at) < until &&
            new Date(event.ends_at) > from,
    );
});
const visibleCalendarIds = computed(() =>
    props.calendars
        .filter((calendar) => visible.value[calendar.id])
        .map((calendar) => calendar.id),
);
const reportUrl = computed(() => {
    const params = new URLSearchParams({
        layout: viewMode.value,
        month: props.month,
    });
    if (viewMode.value === 'list') {
        params.set('from', listFrom.value);
        params.set('until', listUntil.value);
    }
    for (const id of visibleCalendarIds.value) {
        params.append('calendars[]', String(id));
    }

    return `/kalender/terminliste.pdf?${params.toString()}`;
});
function applyListRange() {
    router.get(
        '/kalender',
        {
            month: props.month,
            view: 'list',
            from: listFrom.value,
            until: listUntil.value,
        },
        { preserveState: true, preserveScroll: true },
    );
}
const dateFormatter = new Intl.DateTimeFormat('de-DE', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
});
function eventPeriod(event: CalendarEvent) {
    const start = new Date(event.starts_at);
    const end = new Date(event.ends_at);
    const displayEnd = new Date(end);
    if (event.all_day) displayEnd.setDate(displayEnd.getDate() - 1);
    const startDate = dateFormatter.format(start);
    const endDate = dateFormatter.format(displayEnd);

    if (event.all_day) {
        return startDate === endDate
            ? `${startDate} · ganztägig`
            : `${startDate}–${endDate} · ganztägig`;
    }
    if (startDate === endDate) {
        return `${startDate} · ${formatTime(event.starts_at)}–${formatTime(event.ends_at)}`;
    }

    return `${startDate}, ${formatTime(event.starts_at)} – ${endDate}, ${formatTime(event.ends_at)}`;
}
function selectEventCalendar(value: string) {
    eventForm.calendar_id = Number(value);
}
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
const manageDialog = ref<InstanceType<typeof ManageCalendarsDialog> | null>(
    null,
);
const openManage = (id?: number) => manageDialog.value?.open(id);
const addCalendar = () => manageDialog.value?.add();
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
                    <div
                        v-if="viewMode === 'month'"
                        class="flex items-center gap-2"
                    >
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
                    <h2
                        v-if="viewMode === 'month'"
                        class="text-lg font-semibold capitalize"
                    >
                        {{ monthTitle }}
                    </h2>
                    <div class="flex flex-wrap gap-2">
                        <Button
                            size="sm"
                            :variant="
                                viewMode === 'month' ? 'default' : 'outline'
                            "
                            :aria-pressed="viewMode === 'month'"
                            @click="viewMode = 'month'"
                        >
                            <CalendarDays class="size-4" />Monat
                        </Button>
                        <Button
                            size="sm"
                            :variant="
                                viewMode === 'list' ? 'default' : 'outline'
                            "
                            :aria-pressed="viewMode === 'list'"
                            @click="viewMode = 'list'"
                        >
                            <List class="size-4" />Liste
                        </Button>
                        <Button
                            v-if="visibleCalendarIds.length"
                            size="sm"
                            variant="outline"
                            as-child
                        >
                            <a :href="reportUrl">
                                <Download class="size-4" />PDF
                            </a>
                        </Button>
                        <Button v-else size="sm" variant="outline" disabled>
                            <Download class="size-4" />PDF
                        </Button>
                    </div>
                </div>
                <form
                    v-if="viewMode === 'list'"
                    class="grid gap-4 border-b p-4 sm:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto] sm:items-end"
                    @submit.prevent="applyListRange"
                >
                    <div class="min-w-0 space-y-2">
                        <Label for="calendar-list-from">Von</Label>
                        <Input
                            id="calendar-list-from"
                            v-model="listFrom"
                            type="date"
                            class="date-safe"
                            required
                        />
                    </div>
                    <div class="min-w-0 space-y-2">
                        <Label for="calendar-list-until">Bis</Label>
                        <Input
                            id="calendar-list-until"
                            v-model="listUntil"
                            type="date"
                            class="date-safe"
                            required
                        />
                    </div>
                    <Button type="submit">Zeitraum anwenden</Button>
                </form>
                <div v-if="viewMode === 'month'" class="overflow-x-auto">
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
                <div v-else class="p-4">
                    <StatusAlert
                        v-if="!listedEvents.length"
                        type="info"
                        title="Keine Termine"
                    >
                        In den ausgewählten Kalendern gibt es in diesem Monat
                        keine Termine.
                    </StatusAlert>
                    <div v-else class="overflow-x-auto rounded-lg border">
                        <table class="w-full min-w-[720px] text-sm">
                            <thead class="bg-muted/60 text-left">
                                <tr>
                                    <th class="px-4 py-3 font-medium">
                                        Zeitraum
                                    </th>
                                    <th class="px-4 py-3 font-medium">
                                        Termin
                                    </th>
                                    <th class="px-4 py-3 font-medium">
                                        Kalender
                                    </th>
                                    <th class="px-4 py-3 font-medium">Ort</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y">
                                <tr
                                    v-for="event in listedEvents"
                                    :key="event.id"
                                    class="align-top"
                                >
                                    <td class="px-4 py-3 whitespace-nowrap">
                                        {{ eventPeriod(event) }}
                                    </td>
                                    <td class="px-4 py-3">
                                        <button
                                            v-if="event.editable"
                                            type="button"
                                            class="text-left font-medium underline-offset-4 hover:underline"
                                            @click="openEvent(event)"
                                        >
                                            {{ event.title }}
                                        </button>
                                        <p v-else class="font-medium">
                                            {{ event.title }}
                                        </p>
                                        <p
                                            v-if="event.description"
                                            class="mt-1 text-xs text-muted-foreground"
                                        >
                                            {{ event.description }}
                                        </p>
                                    </td>
                                    <td class="px-4 py-3">
                                        <span
                                            class="mr-2 inline-block size-2.5 rounded-full"
                                            :style="{
                                                backgroundColor: event.color,
                                            }"
                                            aria-hidden="true"
                                        ></span>
                                        {{ event.calendar_name }}
                                    </td>
                                    <td class="px-4 py-3">
                                        {{ event.location || '–' }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>
        </div>
    </div>

    <Dialog v-model:open="eventOpen">
        <DialogContent class="sm:max-w-2xl">
            <DialogHeader
                ><DialogTitle>{{
                    selectedEvent ? 'Termin bearbeiten' : 'Termin anlegen'
                }}</DialogTitle
                ><DialogDescription
                    >Titel, Zeitraum und weitere Angaben zum
                    Termin.</DialogDescription
                ></DialogHeader
            >
            <form class="grid gap-5 sm:grid-cols-2" @submit.prevent="saveEvent">
                <div class="min-w-0 space-y-2 sm:col-span-2">
                    <Label for="event-title">Titel</Label
                    ><Input
                        id="event-title"
                        v-model="eventForm.title"
                        required
                        autofocus
                    /><InputError :message="eventForm.errors.title" />
                </div>
                <div class="min-w-0 space-y-2">
                    <Label for="event-calendar">Kalender</Label
                    ><SearchableDropdown
                        id="event-calendar"
                        :model-value="String(eventForm.calendar_id)"
                        :options="writableCalendarOptions"
                        aria-label="Kalender auswählen"
                        search-placeholder="Kalender suchen"
                        empty-text="Kein Kalender gefunden"
                        trigger-class="h-9 w-full rounded-md border border-input bg-background px-3 shadow-xs"
                        @update:model-value="selectEventCalendar"
                    />
                    <InputError :message="eventForm.errors.calendar_id" />
                </div>
                <div class="min-w-0 space-y-2">
                    <Label for="event-location">Ort</Label
                    ><Input id="event-location" v-model="eventForm.location" />
                </div>
                <label class="flex items-center gap-2 sm:col-span-2"
                    ><Checkbox v-model="eventForm.all_day" />
                    <span class="text-sm">Ganztägig</span></label
                >
                <div class="min-w-0 space-y-2 sm:col-span-2">
                    <Label for="event-start">Beginn</Label
                    ><Input
                        v-if="eventForm.all_day"
                        id="event-start"
                        :model-value="eventForm.starts_at.slice(0, 10)"
                        type="date"
                        class="date-safe"
                        required
                        @update:model-value="
                            eventForm.starts_at = `${String($event)}T00:00`
                        "
                    /><Input
                        v-else
                        id="event-start"
                        v-model="eventForm.starts_at"
                        type="datetime-local"
                        class="date-safe"
                        required
                    /><InputError :message="eventForm.errors.starts_at" />
                </div>
                <div class="min-w-0 space-y-2 sm:col-span-2">
                    <Label for="event-end">Ende</Label
                    ><Input
                        v-if="eventForm.all_day"
                        id="event-end"
                        :model-value="eventForm.ends_at.slice(0, 10)"
                        type="date"
                        class="date-safe"
                        required
                        @update:model-value="
                            eventForm.ends_at = `${String($event)}T00:00`
                        "
                    /><Input
                        v-else
                        id="event-end"
                        v-model="eventForm.ends_at"
                        type="datetime-local"
                        class="date-safe"
                        required
                    /><InputError :message="eventForm.errors.ends_at" />
                </div>
                <div class="min-w-0 space-y-2 sm:col-span-2">
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

    <ManageCalendarsDialog
        ref="manageDialog"
        v-model:open="manageOpen"
        :calendars="calendars"
        :share-fields="shareFields"
    />
</template>
