<script setup lang="ts">
import { formatNumber } from '@/lib/format';
import type {
    RecipientPreviewRow,
    RecipientSummary,
} from '@/types/communication';
import { AtSign, MapPin, UsersRound } from '@lucide/vue';
import { Badge } from '@/components/ui/badge';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';

defineProps<{
    summary: RecipientSummary;
    preview: RecipientPreviewRow[];
}>();
</script>

<template>
    <div class="grid gap-4 sm:grid-cols-3">
        <Card class="gap-2 py-5"
            ><CardHeader class="px-5 pb-0"
                ><div class="flex items-center justify-between">
                    <CardDescription>Gefilterte Empfänger</CardDescription
                    ><UsersRound class="size-5 text-muted-foreground" />
                </div>
                <CardTitle class="text-3xl tabular-nums">{{
                    formatNumber(summary.total)
                }}</CardTitle></CardHeader
            ><CardContent class="px-5 text-xs text-muted-foreground"
                >Die ersten 50 werden unten angezeigt.</CardContent
            ></Card
        >
        <Card class="gap-2 py-5"
            ><CardHeader class="px-5 pb-0"
                ><div class="flex items-center justify-between">
                    <CardDescription>Mit E-Mail-Adresse</CardDescription
                    ><AtSign class="size-5 text-muted-foreground" />
                </div>
                <CardTitle class="text-3xl tabular-nums">{{
                    formatNumber(summary.with_email)
                }}</CardTitle></CardHeader
            ><CardContent class="px-5 text-xs text-muted-foreground"
                >{{ formatNumber(summary.without_email) }} ohne
                E-Mail-Adresse</CardContent
            ></Card
        >
        <Card class="gap-2 py-5"
            ><CardHeader class="px-5 pb-0"
                ><div class="flex items-center justify-between">
                    <CardDescription>Vollständige Anschrift</CardDescription
                    ><MapPin class="size-5 text-muted-foreground" />
                </div>
                <CardTitle class="text-3xl tabular-nums">{{
                    formatNumber(summary.complete_address)
                }}</CardTitle></CardHeader
            ><CardContent class="px-5 text-xs text-muted-foreground"
                >{{
                    formatNumber(summary.incomplete_address)
                }}
                unvollständig</CardContent
            ></Card
        >
    </div>

    <Card class="gap-0 overflow-hidden py-0">
        <CardHeader class="border-b py-5"
            ><CardTitle class="text-base">Empfängervorschau</CardTitle
            ><CardDescription>{{
                $address(
                    'Kontrolliere die Auswahl, bevor du versendest oder PDFs erzeugst.',
                    'Kontrollieren Sie die Auswahl, bevor Sie versenden oder PDFs erzeugen.',
                )
            }}</CardDescription></CardHeader
        >
        <CardContent class="overflow-x-auto p-0"
            ><table class="w-full min-w-[720px] text-sm">
                <thead
                    class="bg-muted/50 text-left text-xs text-muted-foreground"
                >
                    <tr>
                        <th class="px-5 py-3 font-medium">Nr.</th>
                        <th class="px-3 py-3 font-medium">Name</th>
                        <th class="px-3 py-3 font-medium">Mitgliedsart</th>
                        <th class="px-3 py-3 font-medium">E-Mail</th>
                        <th class="px-5 py-3 font-medium">Anschrift</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <tr v-for="member in preview" :key="member.member_number">
                        <td class="px-5 py-3 tabular-nums">
                            {{ member.member_number }}
                        </td>
                        <td class="px-3 py-3 font-medium">
                            {{ member.name }}
                        </td>
                        <td class="px-3 py-3">
                            {{ member.membership_type }}
                        </td>
                        <td class="px-3 py-3">
                            <span
                                :class="
                                    member.email_ready ? '' : 'text-amber-700'
                                "
                                >{{ member.email || 'Fehlt' }}</span
                            >
                        </td>
                        <td class="px-5 py-3">
                            <Badge
                                :variant="
                                    member.address_ready
                                        ? 'outline'
                                        : 'secondary'
                                "
                                >{{
                                    member.address_ready
                                        ? member.city
                                        : 'Unvollständig'
                                }}</Badge
                            >
                        </td>
                    </tr>
                    <tr v-if="preview.length === 0">
                        <td
                            colspan="5"
                            class="px-5 py-8 text-center text-muted-foreground"
                        >
                            Keine Empfänger für diese Filter.
                        </td>
                    </tr>
                </tbody>
            </table></CardContent
        >
    </Card>
</template>
