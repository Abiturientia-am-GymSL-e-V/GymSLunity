<script setup lang="ts">
import { computed } from 'vue';
import SearchableDropdown from '@/components/SearchableDropdown.vue';
import countries from '../../data/countries.json';

defineOptions({ inheritAttrs: false });
const props = defineProps<{
    id: string;
    modelValue: string | null;
    disabled?: boolean;
}>();
const emit = defineEmits<{ 'update:modelValue': [value: string | null] }>();

const labels: Record<string, string> = countries;
const flag = (code: string) =>
    /^[A-Z]{2}$/.test(code)
        ? [...code]
              .map((letter) =>
                  String.fromCodePoint(letter.charCodeAt(0) + 127397),
              )
              .join('')
        : '';
const standardOptions = Object.entries(labels)
    .map(([code, label]) => ({
        value: code,
        label,
        icon: flag(code),
        suffix: code,
    }))
    .sort((a, b) => a.label.localeCompare(b.label, 'de'));
const options = computed(() => {
    const value = props.modelValue?.trim();
    if (!value || labels[value]) return standardOptions;
    return [
        {
            value,
            label: value,
            icon: flag(value),
            suffix: /^[A-Z]{2}$/.test(value) ? value : '',
        },
        ...standardOptions,
    ];
});
</script>

<template>
    <div>
        <SearchableDropdown
            v-bind="$attrs"
            :id="id"
            :model-value="modelValue"
            :options="options"
            :disabled="disabled"
            aria-label="Land auswählen"
            search-placeholder="Land suchen"
            empty-text="Kein Land gefunden"
            trigger-class="h-9 w-full rounded-md border border-input bg-background px-3"
            dropdown-class="max-w-[calc(100vw-2rem)]"
            @update:model-value="emit('update:modelValue', $event)"
        >
            <template #trigger="{ option }">
                <span v-if="option?.icon" aria-hidden="true">{{
                    option.icon
                }}</span>
                <span class="min-w-0 flex-1 truncate">{{
                    option?.label ?? 'Land auswählen'
                }}</span>
                <span
                    v-if="option?.suffix"
                    class="shrink-0 text-xs text-muted-foreground"
                    >{{ option.suffix }}</span
                >
            </template>
        </SearchableDropdown>
        <input
            v-if="typeof $attrs.name === 'string'"
            type="hidden"
            :name="$attrs.name"
            :value="modelValue ?? ''"
        />
    </div>
</template>
