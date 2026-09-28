<script setup lang="ts">
import { formatDateTime, formatNumber } from '@/lib/format';
import type { Campaign, Delivery } from '@/types/communication';
import { Link } from '@inertiajs/vue3';
import { Archive, Mail } from '@lucide/vue';
import { Badge } from '@/components/ui/badge';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';

defineProps<{
    campaigns: Campaign[];
    selectedCampaign: Campaign | null;
    deliveries: Delivery[];
}>();
</script>

<template>
    <Card class="gap-0 overflow-hidden py-0"
        ><CardHeader class="border-b py-5"
            ><CardTitle class="text-base">Letzte Vorgänge</CardTitle
            ><CardDescription
                >Protokollierte Serienmail-Versände und
                Briefexporte.</CardDescription
            ></CardHeader
        ><CardContent class="overflow-x-auto p-0"
            ><table class="w-full min-w-[860px] text-sm">
                <thead
                    class="bg-muted/50 text-left text-xs text-muted-foreground"
                >
                    <tr>
                        <th class="px-5 py-3 font-medium">Zeitpunkt</th>
                        <th class="px-3 py-3 font-medium">Art</th>
                        <th class="px-3 py-3 font-medium">Betreff</th>
                        <th class="px-3 py-3 text-right font-medium">
                            Erfolgreich
                        </th>
                        <th class="px-3 py-3 text-right font-medium">
                            Übersprungen
                        </th>
                        <th class="px-3 py-3 text-right font-medium">Fehler</th>
                        <th class="px-3 py-3 font-medium">Erstellt von</th>
                        <th class="px-5 py-3 text-right font-medium">
                            Details
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <tr v-for="campaign in campaigns" :key="campaign.id">
                        <td class="px-5 py-3 whitespace-nowrap">
                            {{ formatDateTime(campaign.created_at) }}
                        </td>
                        <td class="px-3 py-3">
                            <Badge variant="outline"
                                ><Mail
                                    v-if="campaign.kind === 'mail'"
                                    class="mr-1 size-3"
                                /><Archive v-else class="mr-1 size-3" />{{
                                    campaign.kind === 'mail'
                                        ? 'E-Mail'
                                        : campaign.format?.toUpperCase()
                                }}</Badge
                            >
                        </td>
                        <td class="max-w-sm truncate px-3 py-3 font-medium">
                            {{ campaign.subject }}
                        </td>
                        <td class="px-3 py-3 text-right tabular-nums">
                            {{ formatNumber(campaign.success_count) }}
                        </td>
                        <td class="px-3 py-3 text-right tabular-nums">
                            {{ formatNumber(campaign.skipped_count) }}
                        </td>
                        <td
                            class="px-3 py-3 text-right tabular-nums"
                            :class="
                                campaign.failure_count ? 'text-destructive' : ''
                            "
                        >
                            {{ formatNumber(campaign.failure_count) }}
                        </td>
                        <td class="px-3 py-3">
                            {{ campaign.created_by_name }}
                        </td>
                        <td class="px-5 py-3 text-right">
                            <Link
                                :href="`/kommunikation/verlauf?campaign=${campaign.id}`"
                                class="font-medium text-primary underline-offset-4 hover:underline"
                                >Anzeigen</Link
                            >
                        </td>
                    </tr>
                    <tr v-if="campaigns.length === 0">
                        <td
                            colspan="8"
                            class="px-5 py-10 text-center text-muted-foreground"
                        >
                            Noch keine Kommunikationsvorgänge vorhanden.
                        </td>
                    </tr>
                </tbody>
            </table></CardContent
        ></Card
    >

    <Card v-if="selectedCampaign" class="gap-0 overflow-hidden py-0"
        ><CardHeader class="border-b py-5"
            ><CardTitle class="text-base"
                >Vorgang #{{ selectedCampaign.id }}:
                {{ selectedCampaign.subject }}</CardTitle
            ><CardDescription
                >{{ formatNumber(selectedCampaign.recipient_count) }}
                Empfänger · erstellt von
                {{ selectedCampaign.created_by_name }} am
                {{ formatDateTime(selectedCampaign.created_at)
                }}<template v-if="selectedCampaign.attachments.length">
                    · {{ selectedCampaign.attachments.length }}
                    {{
                        selectedCampaign.attachments.length === 1
                            ? 'Anhang'
                            : 'Anhänge'
                    }}:
                    {{
                        selectedCampaign.attachments
                            .map((file) => file.name)
                            .join(', ')
                    }}
                </template></CardDescription
            ></CardHeader
        ><CardContent class="overflow-x-auto p-0"
            ><table class="w-full min-w-[720px] text-sm">
                <thead
                    class="bg-muted/50 text-left text-xs text-muted-foreground"
                >
                    <tr>
                        <th class="px-5 py-3 font-medium">Nr.</th>
                        <th class="px-3 py-3 font-medium">Empfänger</th>
                        <th class="px-3 py-3 font-medium">E-Mail</th>
                        <th class="px-3 py-3 font-medium">Status</th>
                        <th class="px-5 py-3 font-medium">Hinweis</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <tr v-for="delivery in deliveries" :key="delivery.id">
                        <td class="px-5 py-3 tabular-nums">
                            {{ delivery.member_number }}
                        </td>
                        <td class="px-3 py-3 font-medium">
                            {{ delivery.recipient_name }}
                        </td>
                        <td class="px-3 py-3">
                            {{ delivery.recipient_email || '–' }}
                        </td>
                        <td class="px-3 py-3">
                            <Badge
                                :variant="
                                    delivery.status === 'failed'
                                        ? 'destructive'
                                        : 'outline'
                                "
                                >{{
                                    delivery.status === 'sent'
                                        ? 'Versendet'
                                        : delivery.status === 'generated'
                                          ? 'Erzeugt'
                                          : delivery.status === 'failed'
                                            ? 'Fehlgeschlagen'
                                            : 'Ausstehend'
                                }}</Badge
                            >
                        </td>
                        <td class="px-5 py-3 text-muted-foreground">
                            {{ delivery.error || '–' }}
                        </td>
                    </tr>
                </tbody>
            </table></CardContent
        ></Card
    >
</template>
