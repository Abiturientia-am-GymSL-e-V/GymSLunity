<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ArrowDownToLine } from '@lucide/vue';
import { computed, ref } from 'vue';
import PaymentsPage from '@/components/payments/PaymentsPage.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { postDownload } from '@/lib/download';
import { formatDate, formatMoney } from '@/lib/format';
import type { Contribution, PaymentsClub } from '@/types/payments';

const props = defineProps<{
    contributions: Contribution[];
    club: PaymentsClub;
}>();
defineOptions({
    layout: { breadcrumbs: [{ title: 'Beiträge', href: '/beitraege' }] },
});

const today = new Date().toISOString().slice(0, 10);
const sepaRows = computed(() =>
    props.contributions.filter((item) => item.sepa_ready),
);
const sepaSelection = ref<number[]>([]);
function toggleSepa(id: number) {
    sepaSelection.value = sepaSelection.value.includes(id)
        ? sepaSelection.value.filter((value) => value !== id)
        : [...sepaSelection.value, id];
}
function toggleAllSepa() {
    const ids = sepaRows.value.map((item) => item.id);
    sepaSelection.value =
        ids.length > 0 && ids.every((id) => sepaSelection.value.includes(id))
            ? []
            : ids;
}
const collectionDate = ref(today);
const sepaBusy = ref(false);
const sepaError = ref('');
async function sepaExport() {
    sepaBusy.value = true;
    sepaError.value = '';
    try {
        await postDownload(
            '/beitraege/sepa-export',
            { ids: sepaSelection.value, collection_date: collectionDate.value },
            'sepa-lastschriften.xml',
            {
                accept: 'application/json',
                errorKeys: ['ids'],
                fallback: 'Der SEPA-Export konnte nicht erstellt werden.',
            },
        );
        sepaSelection.value = [];
        router.reload();
    } catch (cause) {
        sepaError.value =
            cause instanceof Error
                ? cause.message
                : 'Der SEPA-Export ist fehlgeschlagen.';
    } finally {
        sepaBusy.value = false;
    }
}
</script>

<template>
    <PaymentsPage active="sepa">
        <section class="overflow-hidden rounded-xl border bg-card">
            <div
                class="flex flex-wrap items-end justify-between gap-3 border-b p-5"
            >
                <div>
                    <h2 class="font-semibold">SEPA-Lastschrift-Datei</h2>
                    <p class="text-sm text-muted-foreground">
                        PAIN.008 exportieren und Beiträge als bezahlt verbuchen.
                    </p>
                </div>
                <div
                    class="grid w-full gap-3 2xl:w-auto 2xl:grid-cols-[minmax(0,18rem)_auto] 2xl:items-end"
                >
                    <div class="min-w-0 space-y-2">
                        <Label for="collection-date">Einzugsdatum</Label
                        ><Input
                            id="collection-date"
                            v-model="collectionDate"
                            type="date"
                            :min="today"
                            class="date-safe"
                        />
                    </div>
                    <Button
                        :disabled="
                            !sepaSelection.length ||
                            sepaBusy ||
                            !club.sepa_ready
                        "
                        @click="sepaExport"
                        ><Spinner v-if="sepaBusy" /><ArrowDownToLine
                            v-else
                            class="size-4"
                        />Exportieren</Button
                    >
                </div>
                <p
                    v-if="!club.sepa_ready"
                    class="basis-full text-sm text-amber-700"
                >
                    Vereinsname, Vereins-IBAN und Gläubiger-ID fehlen in der
                    Konfiguration.
                </p>
                <p v-if="sepaError" class="basis-full text-sm text-destructive">
                    {{ sepaError }}
                </p>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left">
                        <tr>
                            <th class="p-3">
                                <input
                                    type="checkbox"
                                    @change="toggleAllSepa"
                                />
                            </th>
                            <th class="p-3">Mitglied</th>
                            <th class="p-3">Beitrag</th>
                            <th class="p-3">Fällig</th>
                            <th class="p-3 text-right">Einzug</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="item in sepaRows" :key="item.id">
                            <td class="p-3">
                                <input
                                    type="checkbox"
                                    :checked="sepaSelection.includes(item.id)"
                                    @change="toggleSepa(item.id)"
                                />
                            </td>
                            <td class="p-3">
                                {{ item.member_name
                                }}<small class="block"
                                    >Nr. {{ item.member_number }}</small
                                >
                            </td>
                            <td class="p-3">{{ item.description }}</td>
                            <td class="p-3">{{ formatDate(item.due_date) }}</td>
                            <td class="p-3 text-right font-medium">
                                {{ formatMoney(item.remaining_cents) }}
                            </td>
                        </tr>
                        <tr v-if="!sepaRows.length">
                            <td
                                colspan="5"
                                class="p-8 text-center text-muted-foreground"
                            >
                                Keine einziehbaren Beiträge im Zeitraum.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </PaymentsPage>
</template>
