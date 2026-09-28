<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import StatusAlert from '@/components/StatusAlert.vue';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { bookingStatusLabel } from '@/lib/bookings';
import type { BookingResource, ResourceBooking } from '@/types/bookings';

const props = defineProps<{
    month: string;
    bookings: ResourceBooking[];
    resources: BookingResource[];
}>();

const currentMonth = computed({
    get: () => props.month,
    set: (value: string) =>
        router.get('/buchungen', { month: value }, { preserveState: true }),
});
const calendarDays = computed(() => {
    const [year, month] = props.month.split('-').map(Number);
    const first = new Date(year, month - 1, 1);
    const offset = (first.getDay() + 6) % 7;
    const days = new Date(year, month, 0).getDate();

    return Array.from({ length: offset + days }, (_, index) => {
        if (index < offset) return null;
        const day = index - offset + 1;
        const key = `${props.month}-${String(day).padStart(2, '0')}`;
        return {
            day,
            key,
            bookings: props.bookings.filter((item) => occursOn(item, key)),
        };
    });
});

function occursOn(booking: ResourceBooking, day: string) {
    const start = new Date(`${day}T00:00:00`);
    const end = new Date(start);
    end.setDate(end.getDate() + 1);
    return (
        new Date(booking.starts_at) < end && new Date(booking.ends_at) > start
    );
}
</script>

<template>
    <section class="space-y-4">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <h2 class="text-lg font-semibold">Belegungskalender</h2>
                <p class="mt-1 text-sm text-muted-foreground">
                    Bestätigte und angefragte Termine aller Ressourcen.
                </p>
            </div>
            <div class="min-w-0 space-y-2">
                <Label for="booking-month">Monat</Label>
                <Input id="booking-month" v-model="currentMonth" type="month" />
            </div>
        </div>
        <StatusAlert
            v-if="!resources.length"
            type="info"
            title="Noch keine Ressourcen"
        >
            {{ $address('Lege', 'Legen Sie') }} zuerst im Reiter „Ressourcen“
            einen Raum, ein Gerät oder eine andere Ressource an.
        </StatusAlert>
        <div v-else class="overflow-x-auto rounded-xl border bg-card">
            <div
                class="grid min-w-[760px] grid-cols-7 border-b bg-muted/30 text-sm font-medium"
            >
                <div
                    v-for="day in ['Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So']"
                    :key="day"
                    class="p-3"
                >
                    {{ day }}
                </div>
            </div>
            <div class="grid min-w-[760px] grid-cols-7">
                <div
                    v-for="(day, index) in calendarDays"
                    :key="index"
                    class="min-h-32 border-r border-b p-2 last:border-r-0"
                    :class="{ 'bg-muted/20': !day }"
                >
                    <template v-if="day">
                        <span class="text-sm font-medium">{{ day.day }}</span>
                        <div class="mt-2 space-y-1">
                            <Link
                                v-for="booking in day.bookings"
                                :key="booking.id"
                                :href="`/buchungen/ressourcen/${booking.resource_id}?month=${month}`"
                                class="block rounded border bg-background p-2 text-xs hover:bg-muted"
                            >
                                <span class="font-medium"
                                    >{{ booking.starts_at.slice(11) }} ·
                                    {{ booking.resource_name }}</span
                                >
                                <span class="block truncate">{{
                                    booking.title
                                }}</span>
                                <Badge class="mt-1" variant="outline">{{
                                    bookingStatusLabel(booking.status)
                                }}</Badge>
                            </Link>
                        </div>
                    </template>
                </div>
            </div>
        </div>
    </section>
</template>
