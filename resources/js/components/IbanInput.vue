<script setup lang="ts">
import { computed } from 'vue';
import { Input } from '@/components/ui/input';
import { formatIban } from '@/lib/formatIban';

defineOptions({ inheritAttrs: false });

const props = defineProps<{
    modelValue?: string | null;
}>();
const emit = defineEmits<{
    'update:modelValue': [value: string];
}>();

const displayedValue = computed(() => formatIban(props.modelValue));
</script>

<template>
    <Input
        v-bind="$attrs"
        :model-value="displayedValue"
        type="text"
        inputmode="text"
        autocapitalize="characters"
        autocomplete="off"
        spellcheck="false"
        maxlength="42"
        class="font-mono tracking-wide"
        @update:model-value="
            emit('update:modelValue', formatIban(String($event)))
        "
    />
</template>
