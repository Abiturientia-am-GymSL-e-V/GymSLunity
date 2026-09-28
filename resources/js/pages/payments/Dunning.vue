<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { FileDown, Mail, Printer, Search } from '@lucide/vue';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import PaymentsPage from '@/components/payments/PaymentsPage.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { postDownload } from '@/lib/download';
import { formatDate, formatMoney } from '@/lib/format';
import type { OpenDebtor } from '@/types/payments';

const props = defineProps<{ openDebtors: OpenDebtor[] }>();
defineOptions({
    layout: { breadcrumbs: [{ title: 'Beiträge', href: '/beitraege' }] },
});

const dunningQuery = ref('');
const dunningSelection = ref<number[]>([]);
const dunningForm = useForm<{ member_numbers: number[] }>({
    member_numbers: [],
});
const dunningRows = computed(() => {
    const query = dunningQuery.value.trim().toLocaleLowerCase('de');
    return props.openDebtors.filter(
        (item) =>
            !query ||
            `${item.member_name} ${item.member_number} ${item.email || ''}`
                .toLocaleLowerCase('de')
                .includes(query),
    );
});
const dunningTableUrl = computed(() => {
    const params = new URLSearchParams();
    if (dunningQuery.value.trim()) {
        params.set('q', dunningQuery.value.trim());
    }
    const query = params.toString();
    return `/beitraege/mahnwesen/tabelle${query ? `?${query}` : ''}`;
});
function toggleDunning(memberNumber: number) {
    dunningSelection.value = dunningSelection.value.includes(memberNumber)
        ? dunningSelection.value.filter((value) => value !== memberNumber)
        : [...dunningSelection.value, memberNumber];
}
function toggleAllDunning() {
    const ids = dunningRows.value.map((item) => item.member_number);
    dunningSelection.value =
        ids.length > 0 && ids.every((id) => dunningSelection.value.includes(id))
            ? []
            : ids;
}
function sendDunning() {
    dunningForm.member_numbers = dunningSelection.value;
    dunningForm.post('/beitraege/mahnwesen/versenden', {
        preserveScroll: true,
        onSuccess: () => (dunningSelection.value = []),
    });
}
const dunningDownloadBusy = ref(false);
const dunningDownloadError = ref('');
async function downloadDunningLetters() {
    dunningDownloadBusy.value = true;
    dunningDownloadError.value = '';
    try {
        await postDownload(
            '/beitraege/mahnwesen/briefe',
            { member_numbers: dunningSelection.value },
            'zahlungserinnerungen.pdf',
            {
                accept: 'application/pdf, application/json',
                errorKeys: ['member_numbers'],
                fallback: 'Das PDF konnte nicht erstellt werden.',
            },
        );
    } catch (cause) {
        dunningDownloadError.value =
            cause instanceof Error
                ? cause.message
                : 'Das PDF konnte nicht erstellt werden.';
    } finally {
        dunningDownloadBusy.value = false;
    }
}
</script>

<template>
    <PaymentsPage active="dunning">
        <section class="overflow-hidden rounded-xl border bg-card">
            <div class="space-y-4 border-b p-5">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h2 class="font-semibold">Offene Beiträge</h2>
                        <p class="text-sm text-muted-foreground">
                            Mitglieder auswählen und per E-Mail oder PDF an
                            offene Beträge erinnern.
                        </p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <Button as-child variant="outline">
                            <a href="/beitraege/mahnwesen/export">
                                <FileDown class="size-4" />CSV
                            </a>
                        </Button>
                        <Button as-child variant="outline">
                            <a :href="dunningTableUrl" target="_blank">
                                <Printer class="size-4" />Drucken
                            </a>
                        </Button>
                        <Button
                            variant="outline"
                            :disabled="
                                !dunningSelection.length || dunningDownloadBusy
                            "
                            @click="downloadDunningLetters"
                        >
                            <Spinner v-if="dunningDownloadBusy" />
                            <FileDown v-else class="size-4" />PDF
                        </Button>
                        <Button
                            :disabled="
                                !dunningSelection.length ||
                                dunningForm.processing
                            "
                            @click="sendDunning"
                        >
                            <Spinner v-if="dunningForm.processing" />
                            <Mail v-else class="size-4" />Mail senden
                        </Button>
                    </div>
                </div>
                <InputError :message="dunningForm.errors.member_numbers" />
                <InputError :message="dunningDownloadError" />
                <div class="relative max-w-md">
                    <Search
                        class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                    />
                    <Input
                        v-model="dunningQuery"
                        type="search"
                        class="pl-9"
                        placeholder="Name, Mitgliedsnummer oder E-Mail"
                        aria-label="Offene Beiträge durchsuchen"
                    />
                </div>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left">
                        <tr>
                            <th class="p-3">
                                <input
                                    type="checkbox"
                                    aria-label="Alle sichtbaren Mitglieder auswählen"
                                    @change="toggleAllDunning"
                                />
                            </th>
                            <th class="p-3">Mitglied</th>
                            <th class="p-3">Kontakt</th>
                            <th class="p-3">Älteste Fälligkeit</th>
                            <th class="p-3 text-right">Offen</th>
                            <th class="p-3">Dokumente</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr
                            v-for="item in dunningRows"
                            :key="item.member_number"
                        >
                            <td class="p-3">
                                <input
                                    type="checkbox"
                                    :checked="
                                        dunningSelection.includes(
                                            item.member_number,
                                        )
                                    "
                                    :aria-label="`${item.member_name} auswählen`"
                                    @change="toggleDunning(item.member_number)"
                                />
                            </td>
                            <td class="p-3">
                                <a
                                    class="font-medium hover:underline"
                                    :href="'/mitglieder/' + item.member_number"
                                >
                                    {{ item.member_name }}
                                </a>
                                <small class="block"
                                    >Nr. {{ item.member_number }} ·
                                    {{ item.open_count }} Posten</small
                                >
                            </td>
                            <td class="p-3">
                                <span>{{ item.email || 'keine E-Mail' }}</span>
                                <small
                                    v-if="!item.address_ready"
                                    class="block text-amber-700"
                                    >Postanschrift unvollständig</small
                                >
                            </td>
                            <td class="p-3">
                                {{ formatDate(item.earliest_due_date) }}
                                <small class="block">
                                    {{ item.overdue_count }} überfällig ·
                                    {{ formatMoney(item.overdue_cents) }}
                                </small>
                            </td>
                            <td class="p-3 text-right font-medium">
                                {{ formatMoney(item.open_cents) }}
                            </td>
                            <td class="p-3">
                                <div class="flex gap-1">
                                    <Button
                                        as-child
                                        size="icon-sm"
                                        variant="ghost"
                                    >
                                        <a
                                            :href="
                                                '/beitraege/mahnwesen/' +
                                                item.member_number +
                                                '?format=pdf'
                                            "
                                            :aria-label="`Zahlungserinnerung für ${item.member_name} herunterladen`"
                                            title="PDF herunterladen"
                                        >
                                            <FileDown class="size-4" />
                                        </a>
                                    </Button>
                                    <Button
                                        as-child
                                        size="icon-sm"
                                        variant="ghost"
                                    >
                                        <a
                                            :href="
                                                '/beitraege/mahnwesen/' +
                                                item.member_number +
                                                '?format=print'
                                            "
                                            target="_blank"
                                            :aria-label="`Zahlungserinnerung für ${item.member_name} drucken`"
                                            title="Zahlungserinnerung drucken"
                                        >
                                            <Printer class="size-4" />
                                        </a>
                                    </Button>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="!dunningRows.length">
                            <td
                                colspan="6"
                                class="p-8 text-center text-muted-foreground"
                            >
                                Keine passenden offenen Beiträge.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </PaymentsPage>
</template>
