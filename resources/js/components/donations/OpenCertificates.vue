<script setup lang="ts">
import { donationTypeLabels } from '@/lib/donations';
import type { Donation, DonationConfiguration } from '@/types/donations';
import { PenLine } from '@lucide/vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { formatDate, formatMoney } from '@/lib/format';

defineProps<{
    donations: Donation[];
    configuration: DonationConfiguration;
    issuing: boolean;
}>();
const emit = defineEmits<{ issue: [donation: Donation] }>();
</script>

<template>
    <section
        class="rounded-xl border bg-card"
        aria-label="Offene Zuwendungsbestätigungen"
    >
        <div class="border-b px-5 py-4">
            <h2 class="font-semibold">Offene Zuwendungsbestätigungen</h2>
            <p class="mt-1 text-sm text-muted-foreground">
                Spenden, für die noch keine Bestätigung ausgestellt wurde.
            </p>
        </div>
        <div class="divide-y">
            <div
                v-for="donation in donations"
                :key="donation.id"
                class="flex flex-wrap items-center justify-between gap-4 p-5"
            >
                <div>
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="font-medium">{{
                            donation.donor_name
                        }}</span
                        ><Badge variant="outline">{{
                            donationTypeLabels[donation.donation_type]
                        }}</Badge>
                    </div>
                    <p class="mt-1 text-sm text-muted-foreground">
                        {{ donation.receipt_number }} ·
                        {{ formatDate(donation.donated_at) }} ·
                        {{ formatMoney(donation.amount_cents) }}
                    </p>
                    <p class="mt-1 max-w-2xl text-xs text-muted-foreground">
                        {{ donation.purpose_label }}
                    </p>
                </div>
                <Button
                    variant="outline"
                    :disabled="issuing || !configuration.ready"
                    @click="emit('issue', donation)"
                    ><PenLine class="size-4" />{{
                        configuration.digital_delivery_allowed
                            ? 'Ausstellen & unterzeichnen'
                            : 'Druckversion erstellen'
                    }}</Button
                >
            </div>
            <p
                v-if="donations.length === 0"
                class="p-10 text-center text-sm text-muted-foreground"
            >
                Keine offenen Zuwendungsbestätigungen.
            </p>
        </div>
    </section>
</template>
