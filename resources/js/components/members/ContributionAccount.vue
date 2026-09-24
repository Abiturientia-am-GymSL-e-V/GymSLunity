<script setup lang="ts">
import { HandCoins } from '@lucide/vue';

type Entry = {
    id: number;
    kind: string;
    amount_cents: number;
    booking_date: string;
    description: string;
    reference: string | null;
    actor_name: string;
};
defineProps<{ account: { balance_cents: number; transactions: Entry[] } }>();
const money = (cents: number) =>
    new Intl.NumberFormat('de-DE', {
        style: 'currency',
        currency: 'EUR',
    }).format(cents / 100);
const date = (value: string) =>
    new Intl.DateTimeFormat('de-DE').format(new Date(`${value}T00:00:00`));
</script>

<template>
    <aside
        class="min-w-0 self-start rounded-xl border bg-card"
        aria-labelledby="account-title"
        data-test="contribution-account"
    >
        <div class="flex items-center gap-2 border-b p-5">
            <HandCoins
                class="size-4 text-muted-foreground"
                aria-hidden="true"
            />
            <h2 id="account-title" class="font-semibold">Beitragskonto</h2>
        </div>
        <div class="border-b p-5">
            <p class="text-xs text-muted-foreground">Aktueller Saldo</p>
            <p
                class="mt-1 text-xl font-semibold tabular-nums"
                :class="
                    account.balance_cents > 0
                        ? 'text-amber-700 dark:text-amber-300'
                        : account.balance_cents < 0
                          ? 'text-emerald-700 dark:text-emerald-300'
                          : ''
                "
            >
                {{ money(account.balance_cents) }}
            </p>
            <p class="mt-1 text-xs text-muted-foreground">
                Positive Beträge sind noch offen.
            </p>
        </div>
        <p
            v-if="!account.transactions.length"
            class="p-5 text-sm text-muted-foreground"
        >
            Noch keine Beitragsbuchungen.
        </p>
        <ol v-else class="max-h-[32rem] divide-y overflow-auto">
            <li
                v-for="entry in account.transactions"
                :key="entry.id"
                class="p-4"
            >
                <div class="flex items-start justify-between gap-3 text-sm">
                    <span class="break-words">{{ entry.description }}</span>
                    <span
                        class="shrink-0 font-medium tabular-nums"
                        :class="
                            entry.amount_cents < 0
                                ? 'text-emerald-700 dark:text-emerald-300'
                                : ''
                        "
                        >{{ money(entry.amount_cents) }}</span
                    >
                </div>
                <p class="mt-1 text-xs text-muted-foreground">
                    {{ date(entry.booking_date) }} · {{ entry.actor_name
                    }}<template v-if="entry.reference">
                        · {{ entry.reference }}</template
                    >
                </p>
            </li>
        </ol>
    </aside>
</template>
