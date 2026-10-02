<script setup lang="ts">
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';

defineProps<{
    id: string;
    label: string;
    value: string;
    options: string[];
    labels?: Record<string, string>;
}>();

const emit = defineEmits<{ change: [value: string] }>();
</script>

<template>
    <div class="grid min-w-0 gap-2">
        <Label :for="id" class="text-xs text-muted-foreground">{{
            label
        }}</Label>
        <Select
            :model-value="value || '__all__'"
            @update:model-value="
                emit('change', $event === '__all__' ? '' : String($event))
            "
        >
            <SelectTrigger :id="id" class="w-full min-w-0" :aria-label="label">
                <SelectValue placeholder="Alle" />
            </SelectTrigger>
            <SelectContent>
                <SelectItem value="__all__">Alle</SelectItem>
                <SelectItem
                    v-if="value && !options.includes(value)"
                    :value="value"
                    >{{ labels?.[value] || value }}</SelectItem
                >
                <SelectItem
                    v-for="option in options"
                    :key="option"
                    :value="option"
                    >{{ labels?.[option] || option }}</SelectItem
                >
            </SelectContent>
        </Select>
    </div>
</template>
