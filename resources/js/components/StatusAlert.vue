<script setup lang="ts">
import { AlertCircle, CircleCheck, Info, TriangleAlert } from '@lucide/vue';
import { computed } from 'vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';

export type StatusAlertType = 'info' | 'success' | 'warning' | 'error';

const props = withDefaults(
    defineProps<{
        type?: StatusAlertType;
        title?: string;
        messages?: string[];
    }>(),
    {
        type: 'info',
        title: undefined,
        messages: () => [],
    },
);

const variant = computed(() =>
    props.type === 'error' ? 'destructive' : props.type,
);
</script>

<template>
    <Alert :variant="variant">
        <Info v-if="type === 'info'" aria-hidden="true" />
        <CircleCheck v-else-if="type === 'success'" aria-hidden="true" />
        <TriangleAlert v-else-if="type === 'warning'" aria-hidden="true" />
        <AlertCircle v-else aria-hidden="true" />
        <AlertTitle v-if="title">{{ title }}</AlertTitle>
        <AlertDescription>
            <slot />
            <ul v-if="messages.length" class="list-disc space-y-1 pl-4">
                <li v-for="message in messages" :key="message">
                    {{ message }}
                </li>
            </ul>
        </AlertDescription>
    </Alert>
</template>
