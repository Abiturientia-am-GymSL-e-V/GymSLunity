<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import CountryInput from '@/components/CountryInput.vue';
import IbanInput from '@/components/IbanInput.vue';
import PhoneInput from '@/components/PhoneInput.vue';
import PostalCityInput from '@/components/PostalCityInput.vue';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import type { MemberField, MemberValue } from '@/types/members';

const props = defineProps<{
    field: MemberField;
    value: MemberValue;
    values: Record<string, MemberValue>;
    disabled: boolean;
    error?: string;
}>();
const emit = defineEmits<{ change: [value: MemberValue] }>();
const page = usePage();
function stringValue(key: string): string | null {
    return props.values[key] == null ? null : String(props.values[key]);
}
</script>

<template>
    <PhoneInput
        v-if="field.key === 'mobile_phone'"
        :id="`member-${field.key}`"
        :model-value="value == null ? '' : String(value)"
        :default-country="page.props.defaultCountry"
        :disabled="disabled"
        :aria-invalid="!!error"
        :aria-describedby="error ? `error-${field.key}` : undefined"
        @update:model-value="emit('change', $event || null)"
    />
    <CountryInput
        v-else-if="
            field.key === 'country' || field.key === 'account_holder_country'
        "
        :id="`member-${field.key}`"
        :model-value="value == null ? null : String(value)"
        :disabled="disabled"
        :aria-invalid="!!error"
        :aria-describedby="error ? `error-${field.key}` : undefined"
        @update:model-value="emit('change', $event)"
    />
    <IbanInput
        v-else-if="field.key === 'iban'"
        :id="`member-${field.key}`"
        :model-value="value == null ? '' : String(value)"
        :disabled="disabled"
        :required="field.required"
        :aria-invalid="!!error"
        :aria-describedby="error ? `error-${field.key}` : undefined"
        @update:model-value="emit('change', $event || null)"
    />
    <PostalCityInput
        v-else-if="field.key === 'city' || field.key === 'account_holder_city'"
        :id="`member-${field.key}`"
        :model-value="value == null ? null : String(value)"
        :postal-code="
            stringValue(
                field.key === 'city'
                    ? 'postal_code'
                    : 'account_holder_postal_code',
            )
        "
        :country="
            stringValue(
                field.key === 'city' ? 'country' : 'account_holder_country',
            ) ||
            stringValue('country') ||
            page.props.defaultCountry
        "
        :disabled="disabled"
        :aria-invalid="!!error"
        :aria-describedby="error ? `error-${field.key}` : undefined"
        @update:model-value="emit('change', $event)"
    />
    <Select
        v-else-if="field.type === 'select' || field.type === 'boolean'"
        :model-value="
            value == null || value === '' ? '__empty__' : String(value)
        "
        :disabled="disabled"
        @update:model-value="
            emit(
                'change',
                $event === '__empty__'
                    ? null
                    : field.type === 'boolean'
                      ? $event === 'true'
                      : String($event),
            )
        "
    >
        <SelectTrigger
            :id="`member-${field.key}`"
            class="w-full"
            :aria-invalid="!!error"
            :aria-describedby="error ? `error-${field.key}` : undefined"
            ><SelectValue
        /></SelectTrigger>
        <SelectContent>
            <SelectItem
                v-if="
                    !field.required &&
                    (field.type !== 'boolean' || field.custom)
                "
                value="__empty__"
                >{{ field.emptyLabel }}</SelectItem
            >
            <template v-if="field.type === 'boolean'"
                ><SelectItem value="false">Nein</SelectItem
                ><SelectItem value="true">Ja</SelectItem></template
            >
            <template v-else>
                <SelectItem
                    v-if="value && !field.activeOptions[String(value)]"
                    :value="String(value)"
                    >{{
                        `${field.options[String(value)] || value} (bisheriger Wert)`
                    }}</SelectItem
                >
                <SelectItem
                    v-for="(label, option) in field.activeOptions"
                    :key="option"
                    :value="String(option)"
                    >{{ label }}</SelectItem
                >
            </template>
        </SelectContent>
    </Select>
    <Input
        v-else
        :id="`member-${field.key}`"
        :name="field.key"
        :class="{
            'block max-w-full appearance-none': field.type === 'date',
        }"
        :model-value="value == null ? '' : String(value)"
        :type="field.type === 'decimal' ? 'text' : field.type"
        :inputmode="field.type === 'decimal' ? 'decimal' : undefined"
        :maxlength="field.key === 'iban' ? 42 : field.max"
        :required="field.required"
        :disabled="disabled"
        :aria-invalid="!!error"
        :aria-describedby="error ? `error-${field.key}` : undefined"
        @update:model-value="emit('change', $event === '' ? null : $event)"
    />
</template>
