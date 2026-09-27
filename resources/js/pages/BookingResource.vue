<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ArrowLeft, X } from '@lucide/vue';
import { computed } from 'vue';
import StatusAlert from '@/components/StatusAlert.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Booking = {
    id: number;
    resource_name: string;
    requester_name: string;
    title: string;
    starts_at: string;
    ends_at: string;
    status: 'requested' | 'confirmed' | 'cancelled' | 'rejected';
    price_cents: number;
    series_id: string | null;
    occurrence: number;
};
const props = defineProps<{
    resource: {
        id: number;
        name: string;
        location: string | null;
        description: string | null;
    };
    month: string;
    bookings: Booking[];
}>();
defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Buchungen', href: '/buchungen' },
            { title: 'Ressource' },
        ],
    },
});
const cancelForm = useForm({});
const currentMonth = computed({
    get: () => props.month,
    set: (month: string) =>
        router.get(
            `/buchungen/ressourcen/${props.resource.id}`,
            { month },
            { preserveState: true },
        ),
});
const days = computed(() => {
    const [year, month] = props.month.split('-').map(Number);
    const first = new Date(year, month - 1, 1);
    const offset = (first.getDay() + 6) % 7;
    return Array.from(
        { length: offset + new Date(year, month, 0).getDate() },
        (_, index) =>
            index < offset
                ? null
                : (() => {
                      const day = index - offset + 1;
                      const key = `${props.month}-${String(day).padStart(2, '0')}`;
                      return {
                          day,
                          bookings: props.bookings.filter((item) =>
                              occursOn(item, key),
                          ),
                      };
                  })(),
    );
});
function occursOn(booking: Booking, day: string) {
    const start = new Date(`${day}T00:00:00`);
    const end = new Date(start);
    end.setDate(end.getDate() + 1);
    return (
        new Date(booking.starts_at) < end && new Date(booking.ends_at) > start
    );
}
const labels = {
    requested: 'Angefragt',
    confirmed: 'Bestätigt',
    cancelled: 'Storniert',
    rejected: 'Abgelehnt',
};
function cancel(booking: Booking) {
    if (window.confirm('Diesen einzelnen Termin stornieren?'))
        cancelForm.patch(`/buchungen/${booking.id}/stornieren`, {
            preserveScroll: true,
        });
}
</script>
<template>
    <Head :title="`Belegung · ${resource.name}`" />
    <div class="mx-auto w-full max-w-[1200px] space-y-6 p-4 sm:p-6">
        <header>
            <Button as-child variant="ghost" size="sm"
                ><Link href="/buchungen/ressourcen"
                    ><ArrowLeft class="size-4" />Zu den Ressourcen</Link
                ></Button
            >
            <h1 class="mt-3 text-2xl font-semibold tracking-tight">
                {{ resource.name }}
            </h1>
            <p class="mt-1 text-sm text-muted-foreground">
                {{ resource.location || 'Ohne Ortsangabe'
                }}<template v-if="resource.description">
                    · {{ resource.description }}</template
                >
            </p>
        </header>
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <h2 class="text-lg font-semibold">Belegungskalender</h2>
                <p class="mt-1 text-sm text-muted-foreground">
                    Enthält auch Belegungen verbundener Ober- und
                    Teilressourcen.
                </p>
            </div>
            <div class="min-w-0 space-y-2">
                <Label for="resource-month">Monat</Label
                ><Input
                    id="resource-month"
                    v-model="currentMonth"
                    type="month"
                />
            </div>
        </div>
        <StatusAlert
            v-if="!bookings.length"
            type="success"
            title="Keine Belegung"
            >In diesem Monat liegen keine Buchungen vor.</StatusAlert
        >
        <div class="overflow-x-auto rounded-xl border bg-card">
            <div
                class="grid min-w-[760px] grid-cols-7 border-b bg-muted/30 text-sm font-medium"
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
                    class="p-3"
                >
                    {{ weekday }}
                </div>
            </div>
            <div class="grid min-w-[760px] grid-cols-7">
                <div
                    v-for="(day, index) in days"
                    :key="index"
                    class="min-h-36 border-r border-b p-2"
                    :class="{ 'bg-muted/20': !day }"
                >
                    <template v-if="day"
                        ><span class="text-sm font-medium">{{ day.day }}</span>
                        <article
                            v-for="booking in day.bookings"
                            :key="booking.id"
                            class="mt-2 space-y-1 rounded border bg-background p-2 text-xs"
                        >
                            <p class="font-medium">
                                {{ booking.starts_at.slice(11) }}–{{
                                    booking.ends_at.slice(11)
                                }}
                                · {{ booking.resource_name }}
                            </p>
                            <p>{{ booking.title }}</p>
                            <p class="text-muted-foreground">
                                {{ booking.requester_name }}
                            </p>
                            <div class="flex flex-wrap items-center gap-1">
                                <Badge variant="outline">{{
                                    labels[booking.status]
                                }}</Badge
                                ><Button
                                    v-if="
                                        ['requested', 'confirmed'].includes(
                                            booking.status,
                                        )
                                    "
                                    type="button"
                                    size="sm"
                                    variant="ghost"
                                    :disabled="cancelForm.processing"
                                    @click="cancel(booking)"
                                    ><X class="size-3" />Termin
                                    stornieren</Button
                                >
                            </div>
                        </article></template
                    >
                </div>
            </div>
        </div>
    </div>
</template>
