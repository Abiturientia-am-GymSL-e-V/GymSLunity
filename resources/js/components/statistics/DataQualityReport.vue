<script setup lang="ts">
import { CircleAlert, CircleCheck } from '@lucide/vue';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent } from '@/components/ui/card';
import { formatNumber } from '@/lib/format';
import type { DataQuality } from '@/types/statistics';

defineProps<{ dataQuality: DataQuality }>();
</script>

<template>
    <Card class="gap-0 overflow-hidden py-0"
        ><CardContent
            class="grid gap-6 p-5 sm:grid-cols-[auto_1fr] sm:items-center"
            ><div
                class="relative grid size-32 place-items-center rounded-full"
                :style="{
                    background: `conic-gradient(var(--primary) ${dataQuality.score}%, var(--muted) 0)`,
                }"
            >
                <div
                    class="grid size-24 place-items-center rounded-full bg-card text-center"
                >
                    <div>
                        <strong class="text-3xl tabular-nums"
                            >{{ dataQuality.score }} %</strong
                        >
                        <p class="text-xs text-muted-foreground">Vollständig</p>
                    </div>
                </div>
            </div>
            <div>
                <h2 class="text-lg font-semibold">Datenbestand zum Stichtag</h2>
                <p class="mt-1 max-w-2xl text-sm text-muted-foreground">
                    Der Wert berücksichtigt E-Mail-Adresse, Geburtsdatum,
                    Geschlecht, Anschrift und Zahlungsart aller aktiven
                    Mitglieder. Das SEPA-Mandat wird zusätzlich geprüft, sofern
                    Lastschrift gewählt wurde.
                </p>
            </div></CardContent
        ></Card
    >
    <div class="grid gap-4 lg:grid-cols-2">
        <Card
            v-for="check in dataQuality.checks"
            :key="check.key"
            class="gap-0 py-0"
            ><CardContent class="flex items-start gap-4 p-5"
                ><div
                    class="rounded-lg p-2"
                    :class="
                        check.count
                            ? 'bg-amber-100 text-amber-700 dark:bg-amber-950 dark:text-amber-300'
                            : 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300'
                    "
                >
                    <CircleAlert
                        v-if="check.count"
                        class="size-5"
                    /><CircleCheck v-else class="size-5" />
                </div>
                <div class="min-w-0 flex-1">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <h2 class="font-semibold">
                                {{ check.label }}
                            </h2>
                            <p class="mt-1 text-sm text-muted-foreground">
                                {{ check.description }}
                            </p>
                        </div>
                        <Badge
                            :variant="check.count ? 'secondary' : 'outline'"
                            class="shrink-0 tabular-nums"
                            >{{ formatNumber(check.count) }}</Badge
                        >
                    </div>
                    <div class="mt-4 h-2 overflow-hidden rounded-full bg-muted">
                        <div
                            class="h-full rounded-full"
                            :class="
                                check.count ? 'bg-amber-500' : 'bg-emerald-500'
                            "
                            :style="{
                                width: `${check.count ? Math.max(2, check.percentage) : 100}%`,
                            }"
                        />
                    </div>
                    <p class="mt-1.5 text-right text-xs text-muted-foreground">
                        {{ check.percentage }} % betroffen
                    </p>
                </div></CardContent
            ></Card
        >
    </div>
</template>
