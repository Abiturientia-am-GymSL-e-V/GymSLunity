<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { CalendarDays, ChevronLeft, ChevronRight, List } from '@lucide/vue';
import { computed, ref } from 'vue';
import StatusAlert from '@/components/StatusAlert.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { bookingStatusLabel } from '@/lib/bookings';
import { formatDateTime, formatMoney } from '@/lib/format';
import type { BookingResource, ResourceBooking } from '@/types/bookings';

const props = withDefaults(
    defineProps<{
        month: string;
        bookings: ResourceBooking[];
        resources: BookingResource[];
        baseUrl?: string;
    }>(),
    { baseUrl: '/buchungen' },
);
const viewMode = ref<'month' | 'list'>('month');
const pad = (value: number) => String(value).padStart(2, '0');
const monthDate = computed(() => new Date(props.month + '-01T00:00:00'));
const monthTitle = computed(() =>
    new Intl.DateTimeFormat('de-DE', { month: 'long', year: 'numeric' }).format(
        monthDate.value,
    ),
);
const calendarDays = computed(() => {
    const first = new Date(monthDate.value);
    first.setDate(first.getDate() - ((first.getDay() + 6) % 7));
    return Array.from({ length: 42 }, (_, index) => {
        const date = new Date(first);
        date.setDate(date.getDate() + index);
        const key =
            date.getFullYear() +
            '-' +
            pad(date.getMonth() + 1) +
            '-' +
            pad(date.getDate());
        return {
            day: date.getDate(),
            key,
            current: date.getMonth() === monthDate.value.getMonth(),
            bookings: props.bookings.filter((item) => occursOn(item, key)),
        };
    });
});
const listedBookings = computed(() => {
    const start = new Date(monthDate.value);
    const end = new Date(start);
    end.setMonth(end.getMonth() + 1);

    return props.bookings.filter(
        (booking) =>
            new Date(booking.starts_at) < end &&
            new Date(booking.ends_at) > start,
    );
});
function occursOn(booking: ResourceBooking, day: string) {
    const start = new Date(day + 'T00:00:00');
    const end = new Date(start);
    end.setDate(end.getDate() + 1);
    return (
        new Date(booking.starts_at) < end && new Date(booking.ends_at) > start
    );
}
function changeMonth(offset: number) {
    const target = new Date(monthDate.value);
    target.setMonth(target.getMonth() + offset);
    router.get(
        props.baseUrl,
        { month: target.getFullYear() + '-' + pad(target.getMonth() + 1) },
        { preserveState: true, preserveScroll: true },
    );
}
function goToday() {
    const today = new Date();
    router.get(
        props.baseUrl,
        { month: today.getFullYear() + '-' + pad(today.getMonth() + 1) },
        { preserveState: true, preserveScroll: true },
    );
}
</script>

<template>
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
            <h2 class="text-lg font-semibold capitalize">{{ monthTitle }}</h2>
            <div class="flex gap-2">
                <Button
                    size="sm"
                    :variant="viewMode === 'month' ? 'default' : 'outline'"
                    :aria-pressed="viewMode === 'month'"
                    @click="viewMode = 'month'"
                    ><CalendarDays class="size-4" />Monat</Button
                >
                <Button
                    size="sm"
                    :variant="viewMode === 'list' ? 'default' : 'outline'"
                    :aria-pressed="viewMode === 'list'"
                    @click="viewMode = 'list'"
                    ><List class="size-4" />Liste</Button
                >
            </div>
        </div>

        <StatusAlert
            v-if="!resources.length"
            class="m-4"
            type="info"
            title="Noch keine Ressourcen"
        >
            Lege zuerst im Reiter „Ressource anlegen“ einen Raum, ein Gerät oder
            eine andere Ressource an.
        </StatusAlert>

        <div v-else-if="viewMode === 'month'" class="overflow-x-auto">
            <div class="min-w-[850px]">
                <div
                    class="grid grid-cols-7 border-b bg-muted/30 text-center text-xs font-medium text-muted-foreground"
                >
                    <div
                        v-for="day in [
                            'Montag',
                            'Dienstag',
                            'Mittwoch',
                            'Donnerstag',
                            'Freitag',
                            'Samstag',
                            'Sonntag',
                        ]"
                        :key="day"
                        class="p-2"
                    >
                        {{ day }}
                    </div>
                </div>
                <div class="grid grid-cols-7">
                    <div
                        v-for="day in calendarDays"
                        :key="day.key"
                        class="min-h-32 border-r border-b p-2"
                        :class="{ 'bg-muted/20': !day.current }"
                    >
                        <span
                            class="text-sm font-medium"
                            :class="{ 'text-muted-foreground': !day.current }"
                            >{{ day.day }}</span
                        >
                        <div class="mt-2 space-y-1">
                            <div
                                v-for="booking in day.bookings"
                                :key="booking.id"
                                class="rounded border bg-background p-2 text-xs"
                            >
                                <Link
                                    :href="'/buchungen/' + booking.id"
                                    class="block font-medium underline-offset-4 hover:underline"
                                >
                                    {{ booking.starts_at.slice(11) }} ·
                                    {{ booking.resource_name }}
                                </Link>
                                <span class="block truncate">{{
                                    booking.title
                                }}</span>
                                <div
                                    class="mt-1 flex flex-wrap items-center gap-1"
                                >
                                    <Badge variant="outline">{{
                                        bookingStatusLabel(booking.status)
                                    }}</Badge>
                                    <Link
                                        v-if="booking.series_id"
                                        :href="
                                            '/buchungen/' +
                                            booking.id +
                                            '?scope=series'
                                        "
                                        class="text-muted-foreground underline hover:text-foreground"
                                        >Serie</Link
                                    >
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div v-else class="p-4">
            <StatusAlert
                v-if="!listedBookings.length"
                type="info"
                title="Keine Belegungen"
            >
                In diesem Monat liegen keine aktiven Buchungen vor.
            </StatusAlert>
            <div v-else class="overflow-x-auto rounded-lg border">
                <table class="w-full min-w-[850px] text-sm">
                    <thead class="bg-muted/60 text-left">
                        <tr>
                            <th class="px-4 py-3 font-semibold">Zeitraum</th>
                            <th class="px-4 py-3 font-semibold">Ressource</th>
                            <th class="px-4 py-3 font-semibold">Buchung</th>
                            <th class="px-4 py-3 font-semibold">
                                Buchende Person
                            </th>
                            <th class="px-4 py-3 font-semibold">Preis</th>
                            <th class="px-4 py-3 font-semibold">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="booking in listedBookings" :key="booking.id">
                            <td class="px-4 py-3 whitespace-nowrap">
                                {{ formatDateTime(booking.starts_at) }}<br />
                                bis {{ formatDateTime(booking.ends_at) }}
                            </td>
                            <td class="px-4 py-3">
                                {{ booking.resource_name }}
                            </td>
                            <td class="px-4 py-3">
                                <Link
                                    :href="'/buchungen/' + booking.id"
                                    class="font-medium underline-offset-4 hover:underline"
                                    >{{ booking.title }}</Link
                                >
                                <Link
                                    v-if="booking.series_id"
                                    :href="
                                        '/buchungen/' +
                                        booking.id +
                                        '?scope=series'
                                    "
                                    class="mt-1 block text-xs text-muted-foreground underline"
                                    >Gesamte Serie anzeigen</Link
                                >
                            </td>
                            <td class="px-4 py-3">
                                {{ booking.requester_name }}
                            </td>
                            <td class="px-4 py-3">
                                {{ formatMoney(booking.price_cents) }}
                            </td>
                            <td class="px-4 py-3">
                                <Badge variant="outline">{{
                                    bookingStatusLabel(booking.status)
                                }}</Badge>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </section>
</template>
