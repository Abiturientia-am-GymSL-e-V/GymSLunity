<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import { computed } from 'vue';
import InputError from '@/components/InputError.vue';
import SearchableDropdown from '@/components/SearchableDropdown.vue';
import StatusAlert from '@/components/StatusAlert.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { resourceLabel } from '@/lib/bookings';
import type { BookingMemberOption, BookingResource } from '@/types/bookings';

const props = defineProps<{
    resources: BookingResource[];
    members: BookingMemberOption[];
}>();

const bookingForm = useForm({
    resource_id: '' as string,
    member_id: '' as string,
    requester_name: '',
    title: '',
    notes: '',
    starts_at: '',
    ends_at: '',
    recurrence: 'none' as 'none' | 'weekly' | 'monthly',
    occurrences: 1,
});
const resourceOptions = computed(() =>
    props.resources
        .filter((resource) => resource.is_active)
        .map((resource) => {
            const label = resourceLabel(resource, props.resources);
            return {
                value: String(resource.id),
                label,
                search: `${label} ${resource.location ?? ''}`,
            };
        }),
);
const memberOptions = computed(() => [
    { value: '', label: 'Ohne Mitgliedsbezug / Dritte' },
    ...props.members.map((member) => ({
        value: String(member.id),
        label: member.label,
        suffix: member.membership_type,
        search: `${member.label} ${member.membership_type}`,
    })),
]);
function createBooking() {
    bookingForm.post('/buchungen', {
        preserveScroll: true,
        onSuccess: () => bookingForm.reset(),
    });
}
</script>

<template>
    <section class="space-y-4">
        <div>
            <h2 class="text-lg font-semibold">Manuelle Buchung</h2>
            <p class="mt-1 text-sm text-muted-foreground">
                Für Mitglieder, Abteilungen oder externe Dritte. Manuelle
                Buchungen werden direkt bestätigt.
            </p>
        </div>
        <StatusAlert
            v-if="!resources.some((resource) => resource.is_active)"
            type="warning"
            title="Keine aktive Ressource"
            >{{ $address('Lege', 'Legen Sie') }} zuerst eine aktive Ressource
            an.</StatusAlert
        >
        <form
            v-else
            class="space-y-5 rounded-xl border bg-card p-5"
            @submit.prevent="createBooking"
        >
            <div class="grid gap-5 sm:grid-cols-2">
                <div class="min-w-0 space-y-2">
                    <Label for="booking-resource">Ressource *</Label
                    ><SearchableDropdown
                        id="booking-resource"
                        :model-value="bookingForm.resource_id"
                        :options="resourceOptions"
                        aria-label="Ressource auswählen"
                        search-placeholder="Ressource suchen"
                        empty-text="Keine Ressource gefunden"
                        trigger-class="h-9 w-full rounded-md border border-input bg-background px-3"
                        @update:model-value="bookingForm.resource_id = $event"
                    /><InputError :message="bookingForm.errors.resource_id" />
                </div>
                <div class="min-w-0 space-y-2">
                    <Label for="booking-member">Mitglied oder Dritte</Label
                    ><SearchableDropdown
                        id="booking-member"
                        :model-value="bookingForm.member_id"
                        :options="memberOptions"
                        aria-label="Mitglied auswählen"
                        search-placeholder="Name oder Mitgliedsnummer suchen"
                        empty-text="Kein Mitglied gefunden"
                        trigger-class="h-9 w-full rounded-md border border-input bg-background px-3"
                        @update:model-value="bookingForm.member_id = $event"
                    /><InputError :message="bookingForm.errors.member_id" />
                </div>
                <div
                    v-if="!bookingForm.member_id"
                    class="min-w-0 space-y-2 sm:col-span-2"
                >
                    <Label for="booking-requester"
                        >Bezeichnung der Abteilung oder des Dritten *</Label
                    ><Input
                        id="booking-requester"
                        v-model="bookingForm.requester_name"
                        required
                    /><InputError
                        :message="bookingForm.errors.requester_name"
                    />
                </div>
                <div class="min-w-0 space-y-2 sm:col-span-2">
                    <Label for="booking-title">Anlass *</Label
                    ><Input
                        id="booking-title"
                        v-model="bookingForm.title"
                        required
                    /><InputError :message="bookingForm.errors.title" />
                </div>
                <div class="min-w-0 space-y-2">
                    <Label for="booking-start">Beginn *</Label
                    ><Input
                        id="booking-start"
                        v-model="bookingForm.starts_at"
                        type="datetime-local"
                        required
                    /><InputError :message="bookingForm.errors.starts_at" />
                </div>
                <div class="min-w-0 space-y-2">
                    <Label for="booking-end">Ende *</Label
                    ><Input
                        id="booking-end"
                        v-model="bookingForm.ends_at"
                        type="datetime-local"
                        required
                    /><InputError :message="bookingForm.errors.ends_at" />
                </div>
                <div class="min-w-0 space-y-2">
                    <Label for="booking-recurrence">Wiederholung</Label
                    ><select
                        id="booking-recurrence"
                        v-model="bookingForm.recurrence"
                        class="field"
                    >
                        <option value="none">Keine</option>
                        <option value="weekly">Wöchentlich</option>
                        <option value="monthly">Monatlich</option>
                    </select>
                </div>
                <div
                    v-if="bookingForm.recurrence !== 'none'"
                    class="min-w-0 space-y-2"
                >
                    <Label for="booking-occurrences">Anzahl Termine</Label
                    ><Input
                        id="booking-occurrences"
                        v-model="bookingForm.occurrences"
                        type="number"
                        min="1"
                        max="52"
                        required
                    /><InputError :message="bookingForm.errors.occurrences" />
                </div>
                <div class="min-w-0 space-y-2 sm:col-span-2">
                    <Label for="booking-notes">Notizen</Label
                    ><Textarea
                        id="booking-notes"
                        v-model="bookingForm.notes"
                    /><InputError :message="bookingForm.errors.notes" />
                </div>
            </div>
            <Button :disabled="bookingForm.processing"
                ><Plus class="size-4" />Buchung anlegen</Button
            >
        </form>
    </section>
</template>
