<script setup lang="ts">
import { CircleAlert, HeartHandshake } from '@lucide/vue';
import MoneyComparisonChart from '@/components/statistics/MoneyComparisonChart.vue';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { formatMoney, formatNumber } from '@/lib/format';
import type { FinanceStatistics } from '@/types/statistics';

defineProps<{
    finances: FinanceStatistics;
    periodLabel: string;
}>();
</script>

<template>
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <Card class="gap-2 py-5"
            ><CardHeader class="px-5 pb-0"
                ><CardDescription>Sollstellungen</CardDescription
                ><CardTitle class="text-2xl tabular-nums">{{
                    formatMoney(finances.contributions.assessed_cents)
                }}</CardTitle></CardHeader
            ><CardContent class="px-5 text-xs text-muted-foreground"
                >{{
                    formatNumber(finances.contributions.count)
                }}
                Forderungen</CardContent
            ></Card
        >
        <Card class="gap-2 py-5"
            ><CardHeader class="px-5 pb-0"
                ><CardDescription>Bezahlt</CardDescription
                ><CardTitle class="text-2xl tabular-nums">{{
                    formatMoney(finances.contributions.paid_cents)
                }}</CardTitle></CardHeader
            ><CardContent class="px-5 text-xs text-muted-foreground"
                >Zahlungsquote
                {{ finances.contributions.collection_rate }}
                %</CardContent
            ></Card
        >
        <Card class="gap-2 py-5"
            ><CardHeader class="px-5 pb-0"
                ><CardDescription>Offene Beiträge</CardDescription
                ><CardTitle class="text-2xl tabular-nums">{{
                    formatMoney(finances.contributions.open_cents)
                }}</CardTitle></CardHeader
            ><CardContent class="px-5 text-xs text-muted-foreground"
                >Davon
                {{ formatMoney(finances.contributions.overdue_cents) }}
                überfällig</CardContent
            ></Card
        >
        <Card class="gap-2 py-5"
            ><CardHeader class="px-5 pb-0"
                ><CardDescription>Spenden</CardDescription
                ><CardTitle class="text-2xl tabular-nums">{{
                    formatMoney(finances.donations.amount_cents)
                }}</CardTitle></CardHeader
            ><CardContent class="px-5 text-xs text-muted-foreground"
                >{{ formatNumber(finances.donations.count) }} Spenden · Ø
                {{ formatMoney(finances.donations.average_cents) }}</CardContent
            ></Card
        >
    </div>
    <Card class="gap-0 py-0"
        ><CardHeader class="border-b py-5"
            ><CardTitle class="text-base"
                >Beiträge und Spenden im Zeitverlauf</CardTitle
            ><CardDescription
                >Monatliche Sollstellung und Spendeneingänge ·
                {{ periodLabel }}</CardDescription
            ></CardHeader
        ><CardContent class="py-5"
            ><MoneyComparisonChart :points="finances.monthly" /></CardContent
    ></Card>
    <div class="grid gap-4 lg:grid-cols-2">
        <Card class="gap-0 py-0"
            ><CardHeader class="border-b py-5"
                ><CardTitle class="text-base">Beitragsstatus</CardTitle
                ><CardDescription
                    >Alle Forderungen im Auswertungszeitraum</CardDescription
                ></CardHeader
            ><CardContent class="space-y-5 py-5"
                ><div>
                    <div class="mb-2 flex justify-between text-sm">
                        <span>Bezahlt</span
                        ><strong
                            >{{
                                finances.contributions.collection_rate
                            }}
                            %</strong
                        >
                    </div>
                    <div class="h-3 overflow-hidden rounded-full bg-muted">
                        <div
                            class="h-full rounded-full bg-emerald-500"
                            :style="{
                                width: `${Math.min(100, finances.contributions.collection_rate)}%`,
                            }"
                        />
                    </div>
                </div>
                <div
                    class="flex items-center justify-between rounded-lg border p-4"
                >
                    <div class="flex items-center gap-3">
                        <CircleAlert class="size-5 text-amber-600" />
                        <div>
                            <p class="font-medium">Überfällige Forderungen</p>
                            <p class="text-xs text-muted-foreground">
                                Aktuell offen und bereits fällig
                            </p>
                        </div>
                    </div>
                    <div class="text-right">
                        <strong class="tabular-nums">{{
                            formatMoney(finances.contributions.overdue_cents)
                        }}</strong>
                        <p class="text-xs text-muted-foreground">
                            {{
                                formatNumber(
                                    finances.contributions.overdue_count,
                                )
                            }}
                            Vorgänge
                        </p>
                    </div>
                </div></CardContent
            ></Card
        >
        <Card class="gap-0 py-0"
            ><CardHeader class="border-b py-5"
                ><CardTitle class="text-base">Spendenarten</CardTitle
                ><CardDescription
                    >Volumen und Anzahl im Zeitraum</CardDescription
                ></CardHeader
            ><CardContent class="divide-y py-1"
                ><div
                    v-for="item in finances.donations.by_type"
                    :key="item.label"
                    class="flex items-center justify-between gap-4 py-4"
                >
                    <div class="flex items-center gap-3">
                        <HeartHandshake class="size-5 text-muted-foreground" />
                        <div>
                            <p class="font-medium">{{ item.label }}</p>
                            <p class="text-xs text-muted-foreground">
                                {{ formatNumber(item.count) }} Buchungen
                            </p>
                        </div>
                    </div>
                    <strong class="tabular-nums">{{
                        formatMoney(item.amount_cents)
                    }}</strong>
                </div>
                <p
                    v-if="finances.donations.by_type.length === 0"
                    class="py-6 text-sm text-muted-foreground"
                >
                    Keine Spenden im gewählten Zeitraum.
                </p></CardContent
            ></Card
        >
    </div>
</template>
