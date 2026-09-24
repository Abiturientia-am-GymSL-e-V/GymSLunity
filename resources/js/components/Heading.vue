<script setup lang="ts">
type Props = {
    title: string;
    description?: string;
    variant?: 'default' | 'small';
    as?: 'h1' | 'h2';
};

withDefaults(defineProps<Props>(), {
    variant: 'default',
    as: 'h2',
});
</script>

<template>
    <header :class="variant === 'small' ? '' : ''">
        <component
            :is="as"
            :class="
                variant === 'small'
                    ? 'mb-0.5 text-base font-medium'
                    : 'text-2xl font-semibold tracking-tight'
            "
        >
            {{ title }}
        </component>
        <p
            v-if="description"
            :class="[
                'text-sm text-muted-foreground',
                variant === 'default' && 'mt-1',
            ]"
        >
            {{ description }}
        </p>
    </header>
</template>
