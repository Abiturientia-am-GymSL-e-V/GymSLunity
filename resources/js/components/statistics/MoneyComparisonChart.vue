<script setup lang="ts">
import { computed } from 'vue';

type Point = {
    key: string;
    label: string;
    contributions_cents: number;
    donations_cents: number;
};

const props = defineProps<{ points: Point[] }>();
const maximum = computed(() =>
    Math.max(
        1,
        ...props.points.flatMap((point) => [
            point.contributions_cents,
            point.donations_cents,
        ]),
    ),
);
const money = (cents: number) =>
    new Intl.NumberFormat('de-DE', {
        style: 'currency',
        currency: 'EUR',
    }).format(cents / 100);
</script>

<template>
    <div class="space-y-5">
        <div class="flex flex-wrap gap-4 text-xs text-muted-foreground">
            <span class="flex items-center gap-1.5"
                ><i class="size-2.5 rounded-sm bg-primary" /> Sollbeiträge</span
            >
            <span class="flex items-center gap-1.5"
                ><i class="size-2.5 rounded-sm bg-amber-400" /> Spenden</span
            >
        </div>
        <div class="overflow-x-auto pb-1">
            <div
                class="grid min-w-[620px] grid-cols-[repeat(var(--months),minmax(40px,1fr))] gap-2"
                :style="{ '--months': points.length }"
            >
                <div v-for="point in points" :key="point.key" class="space-y-2">
                    <div
                        class="flex h-44 items-end justify-center gap-1 border-b"
                        :title="`${point.label}: ${money(point.contributions_cents)} Beiträge, ${money(point.donations_cents)} Spenden`"
                    >
                        <div
                            class="w-3 rounded-t-sm bg-primary"
                            :style="{
                                height: `${Math.max(point.contributions_cents ? 3 : 0, (point.contributions_cents / maximum) * 100)}%`,
                            }"
                        />
                        <div
                            class="w-3 rounded-t-sm bg-amber-400"
                            :style="{
                                height: `${Math.max(point.donations_cents ? 3 : 0, (point.donations_cents / maximum) * 100)}%`,
                            }"
                        />
                    </div>
                    <p class="text-center text-[10px] text-muted-foreground">
                        {{ point.label }}
                    </p>
                </div>
            </div>
        </div>
    </div>
</template>
