<script setup lang="ts">
import { computed } from 'vue';
import InputError from '@/components/InputError.vue';
import SearchableDropdown from '@/components/SearchableDropdown.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type BookingForm = {
    member_number: string;
    amount: string;
    booking_date: string;
    description: string;
    reference: string;
    direction?: 'payment' | 'charge';
    errors: Partial<
        Record<
            | 'member_number'
            | 'amount'
            | 'booking_date'
            | 'description'
            | 'reference',
            string
        >
    >;
};
const props = defineProps<{
    form: BookingForm;
    members: Array<{
        member_number: number;
        first_name: string;
        last_name: string;
    }>;
    amountLabel: string;
    showDirection?: boolean;
}>();

const memberOptions = computed(() =>
    props.members.map((member) => ({
        value: String(member.member_number),
        label: `${member.last_name}, ${member.first_name}`,
        suffix: `Nr. ${member.member_number}`,
        search: `${member.first_name} ${member.last_name} ${member.member_number}`,
    })),
);
</script>

<template>
    <div class="grid gap-5 sm:grid-cols-2">
        <div class="space-y-2 sm:col-span-2">
            <Label for="booking-member">Mitglied</Label>
            <SearchableDropdown
                id="booking-member"
                :model-value="String(form.member_number || '')"
                :options="memberOptions"
                placeholder="Mitglied auswählen"
                search-placeholder="Name oder Mitgliedsnummer suchen"
                empty-text="Kein Mitglied gefunden"
                aria-label="Mitglied auswählen"
                trigger-class="h-9 w-full rounded-md border border-input bg-background px-3"
                dropdown-class="max-w-[calc(100vw-2rem)]"
                @update:model-value="form.member_number = $event"
            />
            <InputError :message="form.errors.member_number" />
        </div>
        <div v-if="showDirection" class="space-y-2 sm:col-span-2">
            <Label for="booking-direction">Buchungsart</Label>
            <select
                id="booking-direction"
                v-model="form.direction"
                class="h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
            >
                <option value="payment">Zahlungseingang / Gutschrift</option>
                <option value="charge">Forderung / Belastung</option>
            </select>
        </div>
        <div class="space-y-2">
            <Label for="booking-amount">{{ amountLabel }}</Label>
            <Input
                id="booking-amount"
                v-model="form.amount"
                type="number"
                min="0.01"
                step="0.01"
            />
            <InputError :message="form.errors.amount" />
        </div>
        <div class="space-y-2">
            <Label for="booking-date">Buchungsdatum</Label>
            <Input
                id="booking-date"
                v-model="form.booking_date"
                type="date"
                class="date-safe"
            />
            <InputError :message="form.errors.booking_date" />
        </div>
        <div class="space-y-2 sm:col-span-2">
            <Label for="booking-description">Beschreibung</Label>
            <Input
                id="booking-description"
                v-model="form.description"
                maxlength="255"
            />
            <InputError :message="form.errors.description" />
        </div>
        <div class="space-y-2 sm:col-span-2">
            <Label for="booking-reference">Referenz (optional)</Label>
            <Input
                id="booking-reference"
                v-model="form.reference"
                maxlength="255"
            />
            <InputError :message="form.errors.reference" />
        </div>
    </div>
</template>
