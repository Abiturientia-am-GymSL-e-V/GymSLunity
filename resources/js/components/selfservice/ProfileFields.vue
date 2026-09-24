<script setup lang="ts">
const model = defineModel<Record<string, string>>({ required: true });
const fields = [
    { key: 'first_name', label: 'Vorname', type: 'text', required: true },
    {
        key: 'middle_name',
        label: 'Zweiter Vorname',
        type: 'text',
        required: false,
    },
    { key: 'last_name', label: 'Nachname', type: 'text', required: true },
    { key: 'birth_date', label: 'Geburtsdatum', type: 'date', required: true },
    { key: 'mobile_phone', label: 'Mobilnummer', type: 'tel', required: false },
    {
        key: 'street',
        label: 'Straße und Hausnummer',
        type: 'text',
        required: true,
    },
    { key: 'postal_code', label: 'Postleitzahl', type: 'text', required: true },
    { key: 'city', label: 'Ort', type: 'text', required: true },
    { key: 'country', label: 'Land', type: 'text', required: true },
];
</script>
<template>
    <div class="grid gap-4 sm:grid-cols-2">
        <label v-for="field in fields" :key="field.key" class="block space-y-1"
            ><span>{{ field.label }}{{ field.required ? ' *' : '' }}</span
            ><input
                v-model="model[field.key]"
                :type="field.type"
                :required="field.required"
                class="w-full rounded border bg-background p-2"
        /></label>
    </div>
</template>
