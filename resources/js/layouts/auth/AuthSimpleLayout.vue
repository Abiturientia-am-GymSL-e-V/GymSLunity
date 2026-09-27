<script setup lang="ts">
import Frame from '@/components/selfservice/Frame.vue';

defineProps<{
    title?: string;
    description?: string;
}>();
</script>

<template>
    <Frame>
        <div class="max-w-xl space-y-6">
            <div class="space-y-2">
                <h1 class="text-3xl font-semibold">{{ title }}</h1>
                <p class="text-muted-foreground">{{ description }}</p>
            </div>
            <div class="rounded-xl border bg-card p-5">
                <slot />
            </div>
        </div>
    </Frame>
</template>
