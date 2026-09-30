<script setup lang="ts">
import { computed } from 'vue';

type Series = { value: string; label: string; counts: number[] };

/** Monthly active members per department as one line per department. */
const props = defineProps<{ months: string[]; series: Series[] }>();
const width = 760;
const height = 250;
const inset = { top: 20, right: 20, bottom: 42, left: 50 };
const plotWidth = width - inset.left - inset.right;
const plotHeight = height - inset.top - inset.bottom;
// Colors repeat after five series; the dash pattern keeps lines apart and
// does not rely on color alone.
const colors = [
    'var(--chart-1)',
    'var(--chart-2)',
    'var(--chart-3)',
    'var(--chart-4)',
    'var(--chart-5)',
];
const dashes = ['', '8 5', '2 4'];
const style = (index: number) => ({
    color: colors[index % colors.length],
    dash: dashes[Math.floor(index / colors.length) % dashes.length],
});
const maximum = computed(() =>
    Math.max(1, ...props.series.flatMap((line) => line.counts)),
);
const x = (index: number) =>
    inset.left +
    (props.months.length <= 1
        ? plotWidth / 2
        : (index / (props.months.length - 1)) * plotWidth);
const y = (value: number) =>
    inset.top + plotHeight - (value / maximum.value) * plotHeight;
const points = (counts: number[]) =>
    counts.map((count, index) => `${x(index)},${y(count)}`).join(' ');
// Long periods only label every n-th month so the labels do not overlap.
const labelStep = computed(() => Math.ceil(props.months.length / 12));
const last = (counts: number[]) => counts[counts.length - 1] ?? 0;
const change = (counts: number[]) => {
    const difference = last(counts) - (counts[0] ?? 0);
    return difference > 0 ? `+${difference}` : String(difference);
};
</script>

<template>
    <div v-if="series.length && months.length" class="space-y-5">
        <div class="overflow-x-auto">
            <svg
                class="min-w-[620px]"
                :viewBox="`0 0 ${width} ${height}`"
                role="img"
                :aria-label="`Aktive Mitglieder je Abteilung von ${months[0]} bis ${months[months.length - 1]}`"
            >
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
                    0
                </text>
                <template v-for="(month, index) in months" :key="month">
                    <text
                        v-if="
                            index % labelStep === 0 ||
                            index === months.length - 1
                        "
                        :x="x(index)"
                        :y="height - 16"
                        text-anchor="middle"
                        class="fill-muted-foreground text-[10px]"
                    >
                        {{ month }}
                    </text>
                </template>
                <g v-for="(line, lineIndex) in series" :key="line.value">
                    <polyline
                        :points="points(line.counts)"
                        fill="none"
                        :stroke="style(lineIndex).color"
                        :stroke-dasharray="style(lineIndex).dash || undefined"
                        stroke-width="2.5"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                    />
                    <circle
                        v-for="(count, index) in line.counts"
                        :key="index"
                        :cx="x(index)"
                        :cy="y(count)"
                        r="3"
                        fill="var(--background)"
                        :stroke="style(lineIndex).color"
                        stroke-width="2"
                    >
                        <title>
                            {{ line.label }}, {{ months[index] }}:
                            {{ count }} aktive Mitglieder
                        </title>
                    </circle>
                </g>
            </svg>
        </div>
        <ul class="flex flex-wrap gap-x-5 gap-y-2 text-sm">
            <li
                v-for="(line, index) in series"
                :key="line.value"
                class="flex min-w-0 items-center gap-2"
            >
                <svg
                    class="h-2 w-6 shrink-0"
                    viewBox="0 0 24 8"
                    aria-hidden="true"
                >
                    <line
                        x1="1"
                        y1="4"
                        x2="23"
                        y2="4"
                        :stroke="style(index).color"
                        :stroke-dasharray="style(index).dash || undefined"
                        stroke-width="2.5"
                        stroke-linecap="round"
                    />
                </svg>
                <span class="break-words">{{ line.label }}</span>
                <span class="text-muted-foreground tabular-nums"
                    >{{ last(line.counts) }} ({{ change(line.counts) }})</span
                >
            </li>
        </ul>
        <details class="text-sm">
            <summary class="cursor-pointer text-muted-foreground">
                Werte als Tabelle anzeigen
            </summary>
            <div class="mt-3 overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b">
                            <th class="py-2 pr-4 text-left font-semibold">
                                Abteilung
                            </th>
                            <th
                                v-for="month in months"
                                :key="month"
                                class="px-2 py-2 text-right font-semibold whitespace-nowrap"
                            >
                                {{ month }}
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="line in series" :key="line.value">
                            <td class="py-2 pr-4">{{ line.label }}</td>
                            <td
                                v-for="(count, index) in line.counts"
                                :key="index"
                                class="px-2 py-2 text-right tabular-nums"
                            >
                                {{ count }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </details>
    </div>
    <p v-else class="text-sm text-muted-foreground">
        Im gewählten Zeitraum gehört kein aktives Mitglied einer Abteilung an.
    </p>
</template>
