<script setup lang="ts">
import CountryInput from '@/components/CountryInput.vue';
import PhoneInput from '@/components/PhoneInput.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

const model = defineModel<Record<string, string>>({ required: true });
defineProps<{
    email?: string;
    defaultCountry?: string;
    genderOptions?: Record<string, string>;
}>();
const personalFields = [
    { key: 'first_name', label: 'Vorname', type: 'text', required: true },
    {
        key: 'middle_name',
        label: 'Zweiter Vorname',
        type: 'text',
        required: false,
    },
    { key: 'last_name', label: 'Nachname', type: 'text', required: true },
    { key: 'birth_date', label: 'Geburtsdatum', type: 'date', required: true },
    { key: 'gender', label: 'Geschlecht', type: 'select', required: false },
];
const addressFields = [
    {
        key: 'street',
        label: 'Straße und Hausnummer',
        type: 'text',
        required: true,
    },
    { key: 'postal_code', label: 'Postleitzahl', type: 'text', required: true },
    { key: 'city', label: 'Ort', type: 'text', required: true },
];
</script>
<template>
    <div class="grid gap-5 sm:grid-cols-2">
        <div v-for="field in personalFields" :key="field.key" class="space-y-2">
            <Label :for="`profile-${field.key}`"
                >{{ field.label }}{{ field.required ? ' *' : '' }}</Label
            >
            <select
                v-if="field.type === 'select'"
                :id="`profile-${field.key}`"
                v-model="model[field.key]"
                class="h-9 w-full rounded-md border border-input bg-background px-3 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50"
            >
                <option value="">Keine Angabe</option>
                <option
                    v-for="(label, value) in genderOptions"
                    :key="value"
                    :value="value"
                >
                    {{ label }}
                </option>
            </select>
            <Input
                v-else
                :id="`profile-${field.key}`"
                v-model="model[field.key]"
                :type="field.type"
                :required="field.required"
            />
        </div>
        <div
            class="space-y-2"
            :class="{ 'sm:col-span-2': email === undefined }"
        >
            <Label for="profile-mobile-phone">Mobilnummer</Label>
            <PhoneInput
                id="profile-mobile-phone"
                v-model="model.mobile_phone"
                :default-country="defaultCountry"
            />
        </div>
        <div v-if="email !== undefined" class="space-y-2">
            <Label for="profile-email">E-Mail-Adresse</Label>
            <Input
                id="profile-email"
                :value="email"
                type="email"
                readonly
                class="bg-muted text-muted-foreground"
            />
        </div>
        <div v-for="field in addressFields" :key="field.key" class="space-y-2">
            <Label :for="`profile-${field.key}`"
                >{{ field.label }}{{ field.required ? ' *' : '' }}</Label
            >
            <Input
                :id="`profile-${field.key}`"
                v-model="model[field.key]"
                :type="field.type"
                :required="field.required"
            />
        </div>
        <div class="space-y-2">
            <Label for="profile-country">Land *</Label>
            <CountryInput
                id="profile-country"
                v-model="model.country"
                required
            />
        </div>
    </div>
</template>
