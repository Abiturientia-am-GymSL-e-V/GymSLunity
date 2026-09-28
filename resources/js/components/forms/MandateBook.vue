<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Ban, Download, Mail, PenLine, Search, Signature } from '@lucide/vue';
import { computed, ref } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type {
    FinanceMandate,
    FinanceMandateFilters,
    FinanceMandatePage,
} from '@/types/mandates';
const props = defineProps<{
    mandates: FinanceMandatePage;
    filters: FinanceMandateFilters;
}>();
const emit = defineEmits<{
    send: [mandate: FinanceMandate];
    sign: [mandate: FinanceMandate];
    revoke: [mandate: FinanceMandate];
}>();

const search = ref(props.filters.search);
const from = ref(props.filters.from);
const to = ref(props.filters.to);
const status = ref(props.filters.status);
const mandateType = ref(props.filters.mandate_type);
const reportUrl = computed(() => {
    const query = new URLSearchParams();
    if (search.value.trim()) query.set('search', search.value.trim());
    if (from.value) query.set('from', from.value);
    if (to.value) query.set('to', to.value);
    if (status.value !== 'all') query.set('status', status.value);
    if (mandateType.value !== 'all')
        query.set('mandate_type', mandateType.value);
    return `/formulare/sepa-mandate/mandatsbuch.pdf?${query.toString()}`;
});
const applyFilters = () =>
    router.get(
        '/formulare/sepa-mandate',
        {
            search: search.value || undefined,
            from: from.value || undefined,
            to: to.value || undefined,
            status: status.value,
            mandate_type: mandateType.value,
        },
        { preserveState: true, replace: true },
    );
const resetFilters = () => {
    search.value = '';
    from.value = '';
    to.value = '';
    status.value = 'all';
    mandateType.value = 'all';
    applyFilters();
};
</script>

<template>
    <section class="overflow-hidden rounded-xl border bg-card">
        <div class="border-b px-5 py-4">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 class="font-semibold">SEPA-Mandatsbuch</h2>
                    <p class="mt-1 text-sm text-muted-foreground">
                        Mandate filtern und als Liste ausgeben.
                    </p>
                </div>
                <Button as-child variant="outline">
                    <a :href="reportUrl"><Download class="size-4" />PDF</a>
                </Button>
            </div>
        </div>
        <form
            class="grid gap-3 border-b p-4 sm:grid-cols-2 xl:grid-cols-[minmax(0,1fr)_minmax(0,.65fr)_minmax(0,.65fr)_minmax(0,.8fr)_minmax(0,.8fr)_auto] xl:items-end"
            @submit.prevent="applyFilters"
        >
            <div class="min-w-0 space-y-2">
                <Label for="mandate-filter-search">Suche</Label>
                <div class="relative">
                    <Search
                        class="pointer-events-none absolute top-2.5 left-3 size-4 text-muted-foreground"
                        aria-hidden="true"
                    />
                    <Input
                        id="mandate-filter-search"
                        v-model="search"
                        class="pl-9"
                        placeholder="Referenz, Name oder E-Mail"
                    />
                </div>
            </div>
            <div class="min-w-0 space-y-2">
                <Label for="mandate-filter-from">Angelegt von</Label>
                <Input id="mandate-filter-from" v-model="from" type="date" />
            </div>
            <div class="min-w-0 space-y-2">
                <Label for="mandate-filter-to">Angelegt bis</Label>
                <Input
                    id="mandate-filter-to"
                    v-model="to"
                    type="date"
                    :min="from || undefined"
                />
            </div>
            <div class="min-w-0 space-y-2">
                <Label for="mandate-filter-type">Mandatsart</Label>
                <select
                    id="mandate-filter-type"
                    v-model="mandateType"
                    class="h-9 w-full rounded-md border border-input bg-background px-3 text-base md:text-sm"
                >
                    <option value="all">Alle Mandatsarten</option>
                    <option value="recurring">Wiederkehrend</option>
                    <option value="one_off">Einmalig</option>
                </select>
            </div>
            <div class="min-w-0 space-y-2">
                <Label for="mandate-filter-status">Status</Label>
                <select
                    id="mandate-filter-status"
                    v-model="status"
                    class="h-9 w-full rounded-md border border-input bg-background px-3 text-base md:text-sm"
                >
                    <option value="all">Alle Status</option>
                    <option value="pending">Unterschrift offen</option>
                    <option value="signed">Unterschrieben</option>
                    <option value="revoked">Widerrufen</option>
                </select>
            </div>
            <div class="flex flex-wrap gap-2 sm:col-span-2 xl:col-span-1">
                <Button type="submit">Anwenden</Button>
                <Button type="button" variant="outline" @click="resetFilters">
                    Zurücksetzen
                </Button>
            </div>
        </form>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-muted/50">
                    <tr class="border-b">
                        <th class="px-4 py-3 text-left font-medium">
                            Referenz
                        </th>
                        <th class="px-4 py-3 text-left font-medium">
                            Zahlungspflichtige Person
                        </th>
                        <th class="px-4 py-3 text-left font-medium">Mandat</th>
                        <th class="px-4 py-3 text-left font-medium">Status</th>
                        <th class="px-4 py-3 text-right font-medium">
                            Aktionen
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="mandate in mandates.data"
                        :key="mandate.id"
                        class="border-b last:border-0"
                    >
                        <td class="px-4 py-4 font-mono font-medium">
                            {{ mandate.mandate_reference }}
                        </td>
                        <td class="px-4 py-4">
                            <p class="font-medium">
                                {{ mandate.debtor_name }}
                            </p>
                            <p class="text-muted-foreground">
                                {{
                                    mandate.debtor_email ||
                                    'Keine E-Mail-Adresse'
                                }}
                            </p>
                        </td>
                        <td class="px-4 py-4">
                            <p>
                                {{
                                    mandate.mandate_type === 'one_off'
                                        ? 'Einmalig'
                                        : 'Wiederkehrend'
                                }}
                            </p>
                            <p class="font-mono text-xs text-muted-foreground">
                                •••• {{ mandate.iban.slice(-4) }}
                            </p>
                        </td>
                        <td class="px-4 py-4">
                            <Badge
                                :variant="
                                    mandate.status === 'revoked'
                                        ? 'destructive'
                                        : mandate.status === 'signed'
                                          ? 'default'
                                          : 'secondary'
                                "
                                >{{
                                    mandate.status === 'revoked'
                                        ? 'Widerrufen'
                                        : mandate.status === 'signed'
                                          ? 'Unterschrieben'
                                          : 'Unterschrift offen'
                                }}</Badge
                            >
                            <p
                                v-if="
                                    mandate.status === 'signed' &&
                                    mandate.signed_at
                                "
                                class="mt-1 text-xs text-muted-foreground"
                            >
                                {{
                                    new Date(
                                        mandate.signed_at,
                                    ).toLocaleDateString('de-DE')
                                }}
                            </p>
                            <p
                                v-if="
                                    mandate.status === 'revoked' &&
                                    mandate.revoked_at
                                "
                                class="mt-1 max-w-52 text-xs text-muted-foreground"
                            >
                                {{
                                    new Date(
                                        mandate.revoked_at,
                                    ).toLocaleDateString('de-DE')
                                }}
                                · {{ mandate.revocation_reason }}
                            </p>
                        </td>
                        <td class="px-4 py-4">
                            <div class="flex flex-wrap justify-end gap-2">
                                <Button as-child size="sm" variant="outline"
                                    ><a
                                        :href="`/formulare/sepa-mandate/${mandate.id}/pdf`"
                                        >PDF</a
                                    ></Button
                                ><Button
                                    v-if="mandate.status !== 'revoked'"
                                    size="sm"
                                    variant="outline"
                                    @click="emit('send', mandate)"
                                    ><Mail class="size-4" />Mail</Button
                                ><Button
                                    v-if="
                                        mandate.status === 'pending' &&
                                        mandate.signing_url
                                    "
                                    as-child
                                    size="sm"
                                    variant="outline"
                                    ><a
                                        :href="mandate.signing_url"
                                        target="_blank"
                                        rel="noopener"
                                        ><Signature class="size-4" />Digital
                                        unterschreiben</a
                                    ></Button
                                ><Button
                                    v-if="mandate.status === 'pending'"
                                    size="sm"
                                    @click="emit('sign', mandate)"
                                    ><PenLine class="size-4" />Papier
                                    bestätigen</Button
                                ><Button
                                    v-if="mandate.status !== 'revoked'"
                                    size="sm"
                                    variant="destructive"
                                    @click="emit('revoke', mandate)"
                                    ><Ban class="size-4" />Widerrufen</Button
                                >
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <p
            v-if="!mandates.data.length"
            class="p-8 text-center text-sm text-muted-foreground"
        >
            Keine SEPA-Mandate gefunden.
        </p>
        <div
            v-if="mandates.prev_page_url || mandates.next_page_url"
            class="flex justify-between border-t p-4"
        >
            <Button
                variant="outline"
                :disabled="!mandates.prev_page_url"
                @click="
                    mandates.prev_page_url && router.get(mandates.prev_page_url)
                "
                >Zurück</Button
            ><Button
                variant="outline"
                :disabled="!mandates.next_page_url"
                @click="
                    mandates.next_page_url && router.get(mandates.next_page_url)
                "
                >Weiter</Button
            >
        </div>
    </section>
</template>
