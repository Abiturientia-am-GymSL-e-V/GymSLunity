<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ArchiveX, Download, Eye, PackagePlus, Search } from '@lucide/vue';
import { computed, ref } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { formatDate, formatMoney } from '@/lib/format';
import type {
    InventoryItem,
    InventoryOptions,
    InventoryStatus,
    InventorySummary,
} from '@/types/inventory';

const props = defineProps<{
    items: InventoryItem[];
    options: InventoryOptions;
    summary: InventorySummary;
}>();
const emit = defineEmits<{
    details: [item: InventoryItem];
    dispose: [item: InventoryItem];
}>();

const query = ref('');
const statusFilter = ref<'all' | InventoryStatus>('all');
const categoryFilter = ref('all');
const filteredItems = computed(() => {
    const needle = query.value.trim().toLocaleLowerCase('de');
    return props.items.filter((item) => {
        if (statusFilter.value !== 'all' && item.status !== statusFilter.value)
            return false;
        if (
            categoryFilter.value !== 'all' &&
            item.category !== categoryFilter.value
        )
            return false;
        if (!needle) return true;
        return [
            item.inventory_number,
            item.name,
            item.manufacturer,
            item.model,
            item.serial_number,
            item.location,
            item.responsible_person,
        ]
            .filter(Boolean)
            .join(' ')
            .toLocaleLowerCase('de')
            .includes(needle);
    });
});
const reportUrl = computed(() => {
    const params = new URLSearchParams();

    if (query.value.trim()) {
        params.set('search', query.value.trim());
    }

    if (categoryFilter.value !== 'all') {
        params.set('category', categoryFilter.value);
    }

    if (statusFilter.value !== 'all') {
        params.set('status', statusFilter.value);
    }

    const search = params.toString();

    return `/inventar/inventarliste.pdf${search ? `?${search}` : ''}`;
});
</script>

<template>
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <div class="rounded-xl border bg-card p-5">
            <p class="text-sm text-muted-foreground">Im Bestand</p>
            <p class="mt-2 text-3xl font-semibold">
                {{ summary.active_count }}
            </p>
        </div>
        <div class="rounded-xl border bg-card p-5">
            <p class="text-sm text-muted-foreground">
                Anschaffungswert (Bestand)
            </p>
            <p class="mt-2 text-2xl font-semibold">
                {{ formatMoney(summary.acquisition_value_cents) }}
            </p>
        </div>
        <div class="rounded-xl border bg-card p-5">
            <p class="text-sm text-muted-foreground">Aktueller Restwert</p>
            <p class="mt-2 text-2xl font-semibold">
                {{ formatMoney(summary.book_value_cents) }}
            </p>
            <p class="mt-1 text-xs text-muted-foreground">
                Monatsgenau zum heutigen Datum
            </p>
        </div>
        <div class="rounded-xl border bg-card p-5">
            <p class="text-sm text-muted-foreground">Erfasste Abgänge</p>
            <p class="mt-2 text-3xl font-semibold">
                {{ summary.retired_count }}
            </p>
        </div>
    </div>

    <div
        class="grid gap-3 rounded-xl border bg-card p-4 md:grid-cols-[minmax(16rem,1fr)_14rem_14rem]"
    >
        <div class="relative">
            <Search
                class="pointer-events-none absolute top-2.5 left-3 size-4 text-muted-foreground"
            />
            <Input
                v-model="query"
                type="search"
                class="pl-9"
                aria-label="Inventar durchsuchen"
                placeholder="Nummer, Gegenstand, Seriennummer, Standort …"
            />
        </div>
        <select
            v-model="categoryFilter"
            aria-label="Kategorie filtern"
            class="h-9 rounded-md border border-input bg-background px-3 text-sm shadow-xs"
        >
            <option value="all">Alle Kategorien</option>
            <option
                v-for="(label, value) in options.categories"
                :key="value"
                :value="value"
            >
                {{ label }}
            </option>
        </select>
        <select
            v-model="statusFilter"
            aria-label="Status filtern"
            class="h-9 rounded-md border border-input bg-background px-3 text-sm shadow-xs"
        >
            <option value="all">Alle Status</option>
            <option
                v-for="(label, value) in options.statuses"
                :key="value"
                :value="value"
            >
                {{ label }}
            </option>
        </select>
    </div>

    <section
        class="overflow-hidden rounded-xl border bg-card"
        aria-label="Inventartabelle"
    >
        <div class="flex items-center justify-between border-b px-5 py-4">
            <div>
                <h2 class="font-semibold">Inventarübersicht</h2>
                <p class="mt-1 text-sm text-muted-foreground">
                    {{ filteredItems.length }} von {{ items.length }} Einträgen
                </p>
            </div>
            <div class="flex flex-wrap gap-2">
                <Button size="sm" variant="outline" as-child>
                    <a :href="reportUrl">
                        <Download class="size-4" />
                        PDF
                    </a>
                </Button>

                <Button size="sm" as-child>
                    <Link href="/inventar/inventarisieren">
                        <PackagePlus class="size-4" />
                        Inventarisieren
                    </Link>
                </Button>
            </div>
        </div>
        <p
            v-if="!filteredItems.length"
            class="p-8 text-center text-sm text-muted-foreground"
        >
            Keine passenden Inventareinträge gefunden.
        </p>
        <div v-else class="overflow-x-auto">
            <table class="w-full min-w-[1180px] text-sm">
                <thead class="bg-muted/60 text-left">
                    <tr>
                        <th class="px-4 py-3 font-medium">Inventar-Nr.</th>
                        <th class="px-4 py-3 font-medium">Gegenstand</th>
                        <th class="px-4 py-3 font-medium">Zuordnung</th>
                        <th class="px-4 py-3 font-medium">Anschaffung</th>
                        <th class="px-4 py-3 font-medium">Abschreibung</th>
                        <th class="px-4 py-3 text-right font-medium">
                            Restwert
                        </th>
                        <th class="px-4 py-3 font-medium">Status</th>
                        <th class="px-4 py-3 text-right font-medium">
                            Aktionen
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <tr
                        v-for="item in filteredItems"
                        :key="item.id"
                        class="align-top"
                    >
                        <td class="px-4 py-4 font-mono text-xs font-medium">
                            {{ item.inventory_number }}
                        </td>
                        <td class="px-4 py-4">
                            <p class="font-medium">{{ item.name }}</p>
                            <p class="mt-1 text-xs text-muted-foreground">
                                {{ options.categories[item.category] }}
                                <template v-if="item.serial_number">
                                    · S/N
                                    {{ item.serial_number }}</template
                                >
                            </p>
                        </td>
                        <td class="px-4 py-4">
                            <p>{{ item.location }}</p>
                            <p
                                v-if="item.responsible_person"
                                class="mt-1 text-xs text-muted-foreground"
                            >
                                {{ item.responsible_person }}
                            </p>
                        </td>
                        <td class="px-4 py-4 whitespace-nowrap">
                            <p>
                                {{ formatDate(item.acquisition_date) }}
                            </p>
                            <p class="mt-1 text-xs text-muted-foreground">
                                {{ formatMoney(item.acquisition_cost_cents) }}
                                ·
                                {{
                                    options.acquisitionTypes[
                                        item.acquisition_type
                                    ]
                                }}
                            </p>
                        </td>
                        <td class="px-4 py-4">
                            <p>
                                {{
                                    options.depreciationMethods[
                                        item.depreciation_method
                                    ]
                                }}
                            </p>
                            <p
                                v-if="item.depreciation_method === 'linear'"
                                class="mt-1 text-xs text-muted-foreground"
                            >
                                {{ item.useful_life_years }} Jahre ·
                                {{
                                    formatMoney(item.annual_depreciation_cents)
                                }}/Jahr
                            </p>
                        </td>
                        <td
                            class="px-4 py-4 text-right font-medium whitespace-nowrap"
                        >
                            {{ formatMoney(item.book_value_cents) }}
                            <p
                                v-if="item.status !== 'active'"
                                class="mt-1 text-xs font-normal text-muted-foreground"
                            >
                                bei Abgang
                            </p>
                        </td>
                        <td class="px-4 py-4">
                            <Badge
                                :variant="
                                    item.status === 'active'
                                        ? 'outline'
                                        : 'secondary'
                                "
                            >
                                {{ options.statuses[item.status] }}
                            </Badge>
                            <p
                                v-if="item.disposed_at"
                                class="mt-1 text-xs text-muted-foreground"
                            >
                                {{ formatDate(item.disposed_at) }}
                            </p>
                        </td>
                        <td class="px-4 py-4">
                            <div class="flex justify-end gap-2">
                                <Button
                                    variant="outline"
                                    size="sm"
                                    :aria-label="`${item.inventory_number} Details anzeigen`"
                                    @click="emit('details', item)"
                                >
                                    <Eye class="size-3.5" />Details
                                </Button>
                                <Button
                                    v-if="item.status === 'active'"
                                    variant="outline"
                                    size="sm"
                                    :aria-label="`${item.inventory_number} als Abgang erfassen`"
                                    @click="emit('dispose', item)"
                                >
                                    <ArchiveX class="size-3.5" />Abgang
                                </Button>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>
</template>
