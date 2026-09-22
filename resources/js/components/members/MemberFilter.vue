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
    withPresence?: boolean;
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
                <template v-if="withPresence">
                    <SelectItem value="__any__">Mit Funktion</SelectItem>
                    <SelectItem value="__none__">Ohne Funktion</SelectItem>
                </template>
                <SelectItem
                    v-if="
                        value &&
                        !options.includes(value) &&
                        (!withPresence ||
                            !['__any__', '__none__'].includes(value))
                    "
                    :value="value"
                    >{{ value }}</SelectItem
                >
                <SelectItem
                    v-for="option in options"
                    :key="option"
                    :value="option"
                    >{{ option }}</SelectItem
                >
            </SelectContent>
        </Select>
    </div>
</template>
