<script setup lang="ts">
import { computed } from 'vue';

type Point = {
    key: string;
    label: string;
    active: number;
    joined: number;
    departed: number;
};

const props = defineProps<{ points: Point[] }>();
const width = 760;
const height = 230;
const inset = { top: 24, right: 20, bottom: 42, left: 50 };
const plotWidth = width - inset.left - inset.right;
const plotHeight = height - inset.top - inset.bottom;
const values = computed(() => props.points.map((point) => point.active));
const minimum = computed(() => Math.min(0, ...values.value));
const maximum = computed(() => Math.max(1, ...values.value));
const span = computed(() => Math.max(1, maximum.value - minimum.value));
const x = (index: number) =>
    inset.left +
    (props.points.length <= 1
        ? plotWidth / 2
        : (index / (props.points.length - 1)) * plotWidth);
const y = (value: number) =>
    inset.top +
    plotHeight -
    ((value - minimum.value) / span.value) * plotHeight;
const linePoints = computed(() =>
    props.points
        .map((point, index) => `${x(index)},${y(point.active)}`)
        .join(' '),
);
const areaPoints = computed(() => {
    if (props.points.length === 0) return '';
    const bottom = inset.top + plotHeight;
    return `${inset.left},${bottom} ${linePoints.value} ${x(props.points.length - 1)},${bottom}`;
});
const maxMovement = computed(() =>
    Math.max(
        1,
        ...props.points.flatMap((point) => [point.joined, point.departed]),
    ),
);
</script>

<template>
    <div v-if="points.length" class="space-y-5">
        <div class="overflow-x-auto">
            <svg
                class="min-w-[620px]"
                :viewBox="`0 0 ${width} ${height}`"
                role="img"
                :aria-label="`Mitgliederbestand von ${points[0].label} bis ${points[points.length - 1].label}`"
            >
                <defs>
                    <linearGradient
                        id="member-area"
                        x1="0"
                        y1="0"
                        x2="0"
                        y2="1"
                    >
                        <stop
                            offset="0"
                            stop-color="var(--primary)"
                            stop-opacity="0.2"
                        />
                        <stop
                            offset="1"
                            stop-color="var(--primary)"
                            stop-opacity="0.02"
                        />
                    </linearGradient>
                </defs>
                <line
                    :x1="inset.left"
                    :y1="inset.top"
                    :x2="inset.left"
                    :y2="inset.top + plotHeight"
                    stroke="var(--border)"
                />
                <line
                    :x1="inset.left"
                    :y1="inset.top + plotHeight"
                    :x2="width - inset.right"
                    :y2="inset.top + plotHeight"
                    stroke="var(--border)"
                />
                <text
                    :x="inset.left - 10"
                    :y="inset.top + 4"
                    text-anchor="end"
                    class="fill-muted-foreground text-[11px]"
                >
                    {{ maximum }}
                </text>
                <text
                    :x="inset.left - 10"
                    :y="inset.top + plotHeight + 4"
                    text-anchor="end"
                    class="fill-muted-foreground text-[11px]"
                >
                    {{ minimum }}
                </text>
                <polygon :points="areaPoints" fill="url(#member-area)" />
                <polyline
                    :points="linePoints"
                    fill="none"
                    stroke="var(--primary)"
                    stroke-width="3"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                />
                <g v-for="(point, index) in points" :key="point.key">
                    <circle
                        :cx="x(index)"
                        :cy="y(point.active)"
                        r="4"
                        fill="var(--background)"
                        stroke="var(--primary)"
                        stroke-width="3"
                    >
                        <title>
                            {{ point.label }}: {{ point.active }} aktive
                            Mitglieder
                        </title>
                    </circle>
                    <text
                        :x="x(index)"
                        :y="height - 16"
                        text-anchor="middle"
                        class="fill-muted-foreground text-[10px]"
                    >
                        {{ point.label }}
                    </text>
                </g>
            </svg>
        </div>
        <div>
            <div
                class="mb-3 flex flex-wrap gap-4 text-xs text-muted-foreground"
            >
                <span class="flex items-center gap-1.5"
                    ><i class="size-2.5 rounded-sm bg-emerald-500" />
                    Eintritte</span
                >
                <span class="flex items-center gap-1.5"
                    ><i class="size-2.5 rounded-sm bg-rose-400" /> Abgänge</span
                >
            </div>
            <div class="overflow-x-auto">
                <div
                    class="grid min-w-[620px] grid-cols-[repeat(var(--months),minmax(34px,1fr))] gap-2"
                    :style="{ '--months': points.length }"
                >
                    <div
                        v-for="point in points"
                        :key="point.key"
                        class="space-y-1"
                    >
                        <div
                            class="flex h-16 items-end justify-center gap-1"
                            :title="`${point.label}: ${point.joined} Eintritte, ${point.departed} Abgänge`"
                        >
                            <div
                                class="w-2.5 rounded-t-sm bg-emerald-500"
                                :style="{
                                    height: `${Math.max(point.joined ? 5 : 0, (point.joined / maxMovement) * 100)}%`,
                                }"
                            />
                            <div
                                class="w-2.5 rounded-t-sm bg-rose-400"
                                :style="{
                                    height: `${Math.max(point.departed ? 5 : 0, (point.departed / maxMovement) * 100)}%`,
                                }"
                            />
                        </div>
                        <p
                            class="text-center text-[10px] text-muted-foreground tabular-nums"
                        >
                            {{ point.joined }}/{{ point.departed }}
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <p v-else class="text-sm text-muted-foreground">
        Für den gewählten Zeitraum liegen keine Daten vor.
    </p>
</template>
