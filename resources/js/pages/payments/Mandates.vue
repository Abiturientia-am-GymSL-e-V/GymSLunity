<script setup lang="ts">
import { FileDown, Printer } from '@lucide/vue';
import PaymentsPage from '@/components/payments/PaymentsPage.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import type { PaymentMember } from '@/types/payments';

defineProps<{ missingMandates: PaymentMember[] }>();
defineOptions({
    layout: { breadcrumbs: [{ title: 'Beiträge', href: '/beitraege' }] },
});
</script>

<template>
    <PaymentsPage active="mandates">
        <section class="overflow-hidden rounded-xl border bg-card">
            <div
                class="flex flex-wrap items-center justify-between gap-3 border-b p-5"
            >
                <div class="space-y-2">
                    <h2 class="font-semibold">Fehlende SEPA-Mandate</h2>
                    <p class="text-sm text-muted-foreground">
                        Unvollständige Mandatsdaten bei Zahlungsart
                        SEPA-Lastschrift.
                    </p>
                </div>
                <div class="flex gap-2">
                    <Button as-child variant="outline"
                        ><a href="/beitraege/mandate/export?format=csv"
                            ><FileDown class="size-4" />CSV</a
                        ></Button
                    ><Button as-child variant="outline"
                        ><a
                            href="/beitraege/mandate/export?format=print"
                            target="_blank"
                            ><Printer class="size-4" />Drucken</a
                        ></Button
                    >
                </div>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left">
                        <tr>
                            <th class="p-3">Mitglied</th>
                            <th class="p-3">E-Mail</th>
                            <th class="p-3">Fehlt</th>
                            <th class="p-3">Referenz</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr
                            v-for="member in missingMandates"
                            :key="member.member_number"
                        >
                            <td class="p-3">
                                <a
                                    class="font-medium hover:underline"
                                    :href="
                                        '/mitglieder/' + member.member_number
                                    "
                                    >{{ member.name }}</a
                                ><small class="block"
                                    >Nr. {{ member.member_number }}</small
                                >
                            </td>
                            <td class="p-3">{{ member.email || '–' }}</td>
                            <td class="p-3">
                                <Badge
                                    v-for="reason in member.missing"
                                    :key="reason"
                                    variant="outline"
                                    class="mr-1"
                                    >{{ reason }}</Badge
                                >
                            </td>
                            <td class="p-3">
                                {{ member.mandate_reference || '–' }}
                            </td>
                        </tr>
                        <tr v-if="!missingMandates.length">
                            <td
                                colspan="4"
                                class="p-8 text-center text-muted-foreground"
                            >
                                Alle SEPA-Mandate sind vollständig.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </PaymentsPage>
</template>
