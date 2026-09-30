<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import { Badge } from '@/components/ui/badge';
import { formatDate, localDateString } from '@/lib/format';
import { assignmentPeriod } from '@/lib/memberFormatting';
import { show } from '@/routes/members';
import type { AssignmentRow, OfficeGroup } from '@/types/assignments';

/**
 * Office holders per office as bars on a shared time axis. Without a filter
 * period the axis spans the earliest known start up to today or the latest
 * end. Open starts and running terms reach the edge of the axis.
 */
const props = defineProps<{
    groups: OfficeGroup[];
    from: string | null;
    to: string | null;
}>();

const MONTHS = [
    'Jan',
    'Feb',
    'Mär',
    'Apr',
    'Mai',
    'Jun',
    'Jul',
    'Aug',
    'Sep',
    'Okt',
    'Nov',
    'Dez',
];
const dayNumber = (date: string) => {
    const [year, month, day] = date.split('-').map(Number);
    return Date.UTC(year, month - 1, day) / 86_400_000;
};
const today = localDateString();
const holders = computed(() =>
    props.groups.flatMap((group) =>
        group.options.flatMap((option) => option.holders),
    ),
);
const range = computed(() => {
    const starts = holders.value
        .map((holder) => holder.starts_on)
        .filter((date): date is string => date !== null)
        .sort();
    const ends = holders.value
        .map((holder) => holder.ends_on)
        .filter((date): date is string => date !== null)
        .sort();
    const latest = [today, ends[ends.length - 1] ?? today].sort()[1];
    let start =
        props.from ?? starts[0] ?? `${Number(today.slice(0, 4)) - 1}-01-01`;
    const end = props.to ?? latest;
    if (start >= end) start = `${Number(end.slice(0, 4)) - 1}${end.slice(4)}`;
    return { start: dayNumber(start), end: dayNumber(end) };
});
const span = computed(() => Math.max(1, range.value.end - range.value.start));
const percent = (day: number) =>
    ((Math.min(Math.max(day, range.value.start), range.value.end) -
        range.value.start) /
        span.value) *
    100;

/** Year ticks for long periods, otherwise month ticks; at most about 12. */
const ticks = computed(() => {
    const start = new Date(range.value.start * 86_400_000);
    const end = new Date(range.value.end * 86_400_000);
    const result: { key: string; label: string; left: number }[] = [];
    if (span.value > 730) {
        const first = start.getUTCFullYear() + 1;
        const last = end.getUTCFullYear();
        const step = Math.max(1, Math.ceil((last - first + 1) / 12));
        for (let year = first; year <= last; year += step) {
            result.push({
                key: String(year),
                label: String(year),
                left: percent(Date.UTC(year, 0, 1) / 86_400_000),
            });
        }
        return result;
    }
    const months =
        (end.getUTCFullYear() - start.getUTCFullYear()) * 12 +
        end.getUTCMonth() -
        start.getUTCMonth();
    const step = Math.max(1, Math.ceil(months / 12));
    for (let index = 1; index <= months; index += step) {
        const month = new Date(
            Date.UTC(start.getUTCFullYear(), start.getUTCMonth() + index, 1),
        );
        result.push({
            key: month.toISOString().slice(0, 7),
            label: `${MONTHS[month.getUTCMonth()]} ${String(month.getUTCFullYear()).slice(2)}`,
            left: percent(month.getTime() / 86_400_000),
        });
    }
    return result;
});
const todayLeft = computed(() => {
    const day = dayNumber(today);
    return day > range.value.start && day < range.value.end
        ? percent(day)
        : null;
});

type Bar = {
    row: AssignmentRow;
    left: number;
    width: number;
    openStart: boolean;
    openEnd: boolean;
    /** Where the name goes when the bar is too narrow for it. */
    label: 'inside' | 'after' | 'before';
};
/**
 * Bars in lanes so that overlapping terms of one office, including names
 * next to narrow bars, do not cover each other. The name width is an
 * estimate in percent of the axis.
 */
const lanes = (rows: AssignmentRow[]): Bar[][] => {
    const result: { end: number; bars: Bar[] }[] = [];
    const sorted = [...rows].sort((a, b) =>
        (a.starts_on ?? '').localeCompare(b.starts_on ?? ''),
    );
    for (const row of sorted) {
        const start = row.starts_on ? dayNumber(row.starts_on) : -Infinity;
        const end = row.ends_on ? dayNumber(row.ends_on) : Infinity;
        const left = percent(start);
        const width = Math.max(0.8, percent(end) - left);
        const nameWidth = Math.min(40, row.name.length * 1.3 + 3);
        const label =
            width >= nameWidth
                ? 'inside'
                : left + width + nameWidth <= 100
                  ? 'after'
                  : 'before';
        const bar: Bar = {
            row,
            left,
            width,
            openStart: start < range.value.start,
            openEnd: end > range.value.end,
            label,
        };
        const extentStart = label === 'before' ? left - nameWidth : left;
        const extentEnd = left + width + (label === 'after' ? nameWidth : 0);
        const lane = result.find((item) => item.end < extentStart);
        if (lane) {
            lane.bars.push(bar);
            lane.end = extentEnd;
        } else {
            result.push({ end: extentEnd, bars: [bar] });
        }
    }
    return result.map((lane) => lane.bars);
};
// Recent terms are the most relevant ones, so narrow screens start at the end.
const scrollers = ref<HTMLElement[]>([]);
const scrollToEnd = () =>
    nextTick(() =>
        scrollers.value.forEach((element) => {
            element.scrollLeft = element.scrollWidth;
        }),
    );
onMounted(scrollToEnd);
watch(() => props.groups, scrollToEnd);
const rangeLabel = computed(() => {
    const format = (day: number) =>
        formatDate(new Date(day * 86_400_000).toISOString().slice(0, 10));
    return `${format(range.value.start)} – ${format(range.value.end)}`;
});
</script>

<template>
    <section
        v-for="group in groups"
        :key="group.key"
        class="rounded-xl border bg-card"
        :aria-labelledby="`timeline-${group.key}`"
    >
        <div class="flex flex-wrap items-center gap-2 border-b px-5 py-4">
            <h2 :id="`timeline-${group.key}`" class="font-semibold">
                {{ group.label }}
            </h2>
            <Badge v-if="group.archived" variant="outline">archiviert</Badge>
            <span class="text-sm text-muted-foreground">{{ rangeLabel }}</span>
        </div>
        <p
            v-if="!group.options.length"
            class="p-5 text-sm text-muted-foreground"
        >
            Keine Ämter für diese Auswahl.
        </p>
        <div v-else ref="scrollers" class="overflow-x-auto py-5 pr-5">
            <div class="min-w-[640px]">
                <div
                    class="grid grid-cols-[9rem_minmax(0,1fr)] sm:grid-cols-[12rem_minmax(0,1fr)]"
                    aria-hidden="true"
                >
                    <span class="sticky left-0 z-10 bg-card" />
                    <div class="relative h-5 border-b">
                        <span
                            v-for="tick in ticks"
                            :key="tick.key"
                            class="absolute top-0 -translate-x-1/2 text-xs whitespace-nowrap text-muted-foreground tabular-nums"
                            :style="{ left: `${tick.left}%` }"
                            >{{ tick.label }}</span
                        >
                    </div>
                </div>
                <ul class="divide-y">
                    <li
                        v-for="option in group.options"
                        :key="option.value"
                        class="grid grid-cols-[9rem_minmax(0,1fr)] py-2 sm:grid-cols-[12rem_minmax(0,1fr)]"
                    >
                        <div
                            class="sticky left-0 z-10 min-w-0 bg-card py-1 pr-3 pl-5"
                        >
                            <p
                                class="text-sm font-medium break-words hyphens-auto"
                            >
                                {{ option.label }}
                            </p>
                            <p
                                v-if="option.board"
                                class="text-xs text-muted-foreground"
                            >
                                Vorstand
                            </p>
                        </div>
                        <div class="relative min-w-0">
                            <span
                                v-for="tick in ticks"
                                :key="tick.key"
                                class="absolute inset-y-0 border-l border-dashed border-border"
                                :style="{ left: `${tick.left}%` }"
                                aria-hidden="true"
                            />
                            <span
                                v-if="todayLeft !== null"
                                class="absolute inset-y-0 border-l-2 border-primary/60"
                                :style="{ left: `${todayLeft}%` }"
                                aria-hidden="true"
                            />
                            <p
                                v-if="!option.holders.length"
                                class="relative py-1 text-right text-sm text-muted-foreground"
                            >
                                Nicht besetzt
                            </p>
                            <ul v-else class="relative space-y-1">
                                <li
                                    v-for="(lane, laneIndex) in lanes(
                                        option.holders,
                                    )"
                                    :key="laneIndex"
                                    class="relative h-7"
                                >
                                    <template
                                        v-for="bar in lane"
                                        :key="bar.row.id"
                                    >
                                        <Link
                                            :href="show(bar.row.member_number)"
                                            class="absolute inset-y-0 flex min-w-0 items-center overflow-hidden rounded-md border border-primary/40 bg-primary/15 px-2 text-xs font-medium whitespace-nowrap text-foreground hover:bg-primary/25 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                            :class="{
                                                'rounded-l-none border-l-0':
                                                    bar.openStart,
                                                'rounded-r-none border-r-0':
                                                    bar.openEnd,
                                            }"
                                            :style="{
                                                left: `${bar.left}%`,
                                                width: `${bar.width}%`,
                                            }"
                                            :title="`${bar.row.name} · ${assignmentPeriod(bar.row, 'office')}`"
                                            :aria-label="`${bar.row.name}, ${assignmentPeriod(bar.row, 'office')}`"
                                            ><span
                                                v-if="bar.label === 'inside'"
                                                class="truncate"
                                                >{{ bar.row.name }}</span
                                            ></Link
                                        >
                                        <span
                                            v-if="bar.label !== 'inside'"
                                            class="absolute inset-y-0 flex items-center text-xs font-medium whitespace-nowrap"
                                            :class="
                                                bar.label === 'after'
                                                    ? 'pl-1.5'
                                                    : 'pr-1.5'
                                            "
                                            :style="
                                                bar.label === 'after'
                                                    ? {
                                                          left: `${bar.left + bar.width}%`,
                                                      }
                                                    : {
                                                          right: `${100 - bar.left}%`,
                                                      }
                                            "
                                            aria-hidden="true"
                                            >{{ bar.row.name }}</span
                                        >
                                    </template>
                                </li>
                            </ul>
                        </div>
                    </li>
                </ul>
            </div>
        </div>
    </section>
</template>
