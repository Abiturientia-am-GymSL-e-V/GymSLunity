<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type BookingForm = {
    member_number: string;
    amount: string;
    booking_date: string;
    description: string;
    reference: string;
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
defineProps<{
    form: BookingForm;
    members: Array<{
        member_number: number;
        first_name: string;
        last_name: string;
    }>;
    amountLabel: string;
}>();
</script>

<template>
    <div class="grid gap-5 sm:grid-cols-2">
        <div class="space-y-2 sm:col-span-2">
            <Label for="booking-member">Mitglied</Label>
            <select
                id="booking-member"
                v-model="form.member_number"
                class="h-9 w-full rounded-md border bg-background px-3 text-sm"
            >
                <option value="">Bitte auswählen</option>
                <option
                    v-for="member in members"
                    :key="member.member_number"
                    :value="member.member_number"
                >
                    {{ member.last_name }}, {{ member.first_name }} · Nr.
                    {{ member.member_number }}
                </option>
            </select>
            <InputError :message="form.errors.member_number" />
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
            <Input id="booking-date" v-model="form.booking_date" type="date" />
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
