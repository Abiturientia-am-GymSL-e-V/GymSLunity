<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { FileDown, HandCoins } from '@lucide/vue';
import { formatDate, formatMoney } from '@/lib/format';
import { receipt } from '@/routes/payments/transactions';

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
const page = usePage();
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
                {{ formatMoney(account.balance_cents) }}
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
                        >{{ formatMoney(entry.amount_cents) }}</span
                    >
                </div>
                <p class="mt-1 text-xs text-muted-foreground">
                    {{ formatDate(entry.booking_date) }} · {{ entry.actor_name
                    }}<template v-if="entry.reference">
                        · {{ entry.reference }}</template
                    >
                </p>
                <a
                    v-if="page.props.can.viewPayments"
                    :href="receipt.url(entry.id)"
                    class="mt-1 inline-flex items-center gap-1 text-xs text-muted-foreground underline-offset-2 hover:text-foreground hover:underline"
                    ><FileDown class="size-3" aria-hidden="true" />Beleg
                    herunterladen</a
                >
            </li>
        </ol>
    </aside>
</template>
