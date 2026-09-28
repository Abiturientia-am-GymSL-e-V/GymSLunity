<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ArrowLeft, Pencil } from '@lucide/vue';
import BookingCalendar from '@/components/bookings/BookingCalendar.vue';
import { Button } from '@/components/ui/button';
import type { BookingResource, ResourceBooking } from '@/types/bookings';

const props = defineProps<{
    resource: BookingResource;
    month: string;
    bookings: ResourceBooking[];
}>();
defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Buchungen', href: '/buchungen' },
            { title: 'Ressource' },
        ],
    },
});
</script>

<template>
    <Head :title="'Belegung · ' + resource.name" />
    <div class="mx-auto w-full max-w-[1500px] space-y-6 p-4 sm:p-6">
        <header>
            <Button as-child variant="ghost" size="sm">
                <Link href="/buchungen/ressourcen"
                    ><ArrowLeft class="size-4" />Zu den Ressourcen</Link
                >
            </Button>
            <div class="mt-3 flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h1 class="text-2xl font-semibold tracking-tight">
                        {{ resource.name }}
                    </h1>
                    <p class="mt-1 text-sm text-muted-foreground">
                        {{ resource.location || 'Ohne Ortsangabe'
                        }}<template v-if="resource.description">
                            · {{ resource.description }}</template
                        >
                    </p>
                </div>
                <Button as-child variant="outline">
                    <Link
                        :href="
                            '/buchungen/ressourcen/anlegen?edit=' + resource.id
                        "
                        ><Pencil class="size-4" />Ressource bearbeiten</Link
                    >
                </Button>
            </div>
        </header>

        <p class="text-sm text-muted-foreground">
            Die Belegung enthält auch Termine verbundener Ober- und
            Teilressourcen.
        </p>
        <BookingCalendar
            :month="month"
            :bookings="bookings"
            :resources="[resource]"
            :base-url="'/buchungen/ressourcen/' + resource.id"
        />
    </div>
</template>
