<script setup lang="ts">
import { computed } from 'vue';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';

const props = defineProps<{
    title: string;
    description: string;
    items: { label: string; count: number }[];
}>();

const total = computed(() =>
    props.items.reduce((sum, item) => sum + item.count, 0),
);
const maximum = computed(() =>
    Math.max(1, ...props.items.map((item) => item.count)),
);
const percent = (value: number) =>
    total.value > 0 ? Math.round((value / total.value) * 100) : 0;
</script>

<template>
    <Card class="gap-0 py-0">
        <CardHeader class="border-b py-5">
            <CardTitle class="text-base">{{ title }}</CardTitle>
            <CardDescription>{{ description }}</CardDescription>
        </CardHeader>
        <CardContent class="space-y-4 py-5">
            <p v-if="items.length === 0" class="text-sm text-muted-foreground">
                Für diesen Stichtag liegen keine Daten vor.
            </p>
            <div v-for="item in items" :key="item.label" class="space-y-1.5">
                <div class="flex items-baseline justify-between gap-4 text-sm">
                    <span class="truncate font-medium">{{ item.label }}</span>
                    <span class="shrink-0 text-muted-foreground tabular-nums">
                        {{ item.count.toLocaleString('de-DE') }} ·
                        {{ percent(item.count) }} %
                    </span>
                </div>
                <div class="h-2 overflow-hidden rounded-full bg-muted">
                    <div
                        class="h-full rounded-full bg-primary transition-[width]"
                        :style="{
                            width: `${Math.max(2, (item.count / maximum) * 100)}%`,
                        }"
                    />
                </div>
            </div>
        </CardContent>
    </Card>
</template>
