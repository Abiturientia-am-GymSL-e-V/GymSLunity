<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { CalendarPlus, Plus } from '@lucide/vue';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import PaymentsPage from '@/components/payments/PaymentsPage.vue';
import SearchableDropdown from '@/components/SearchableDropdown.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import type { ContributionFilterField, PaymentsClub } from '@/types/payments';

const props = defineProps<{
    filterOptions: {
        membership_types: string[];
        payment_methods: string[];
        fields: ContributionFilterField[];
    };
    club: PaymentsClub;
}>();
defineOptions({
    layout: { breadcrumbs: [{ title: 'Beiträge', href: '/beitraege' }] },
});

const today = new Date().toISOString().slice(0, 10);
const year = new Date().getFullYear();
const createForm = useForm({
    period_start: year + '-01-01',
    period_end: year + '-12-31',
    due_date: today,
    description: 'Mitgliedsbeitrag ' + year,
    amount_mode: 'fixed',
    amount: '',
    membership_type: '',
    payment_method: '',
    honorary: 'exclude',
    tax_deductible: false,
    filters: [] as Array<{ key: string; value: string }>,
});
const filterFieldToAdd = ref('');
const availableContributionFilters = computed(() =>
    props.filterOptions.fields.filter(
        (field) =>
            !createForm.filters.some((filter) => filter.key === field.key),
    ),
);
const membershipOptions = computed(() => [
    { value: '', label: 'Alle Mitgliedschaften' },
    ...props.filterOptions.membership_types.map((value) => ({
        value,
        label: value,
    })),
]);
const paymentMethodOptions = computed(() => [
    { value: '', label: 'Alle Zahlungsarten' },
    ...props.filterOptions.payment_methods.map((value) => ({
        value,
        label: value,
    })),
]);
const additionalFilterOptions = computed(() => [
    { value: '', label: 'Feld auswählen' },
    ...availableContributionFilters.value.map((field) => ({
        value: field.key,
        label: field.label,
    })),
]);
function contributionFilterField(key: string) {
    return props.filterOptions.fields.find((field) => field.key === key);
}
function contributionValueOptions(key: string) {
    const field = contributionFilterField(key);
    if (field?.type === 'boolean') {
        return [
            { value: '1', label: 'Ja' },
            { value: '0', label: 'Nein' },
        ];
    }
    return Object.entries(field?.options || {}).map(([value, label]) => ({
        value,
        label,
    }));
}
function addContributionFilter() {
    const field = contributionFilterField(filterFieldToAdd.value);
    if (!field) return;
    createForm.filters.push({
        key: field.key,
        value: field.type === 'boolean' ? '1' : '',
    });
    filterFieldToAdd.value = '';
}
function removeContributionFilter(key: string) {
    createForm.filters = createForm.filters.filter(
        (filter) => filter.key !== key,
    );
}
function period(value: string) {
    const values: Record<string, [string, string, string]> = {
        year: [year + '-01-01', year + '-12-31', 'Mitgliedsbeitrag ' + year],
        h1: [
            year + '-01-01',
            year + '-06-30',
            'Mitgliedsbeitrag 1. Halbjahr ' + year,
        ],
        h2: [
            year + '-07-01',
            year + '-12-31',
            'Mitgliedsbeitrag 2. Halbjahr ' + year,
        ],
        q1: [
            year + '-01-01',
            year + '-03-31',
            'Mitgliedsbeitrag 1. Quartal ' + year,
        ],
        q2: [
            year + '-04-01',
            year + '-06-30',
            'Mitgliedsbeitrag 2. Quartal ' + year,
        ],
        q3: [
            year + '-07-01',
            year + '-09-30',
            'Mitgliedsbeitrag 3. Quartal ' + year,
        ],
        q4: [
            year + '-10-01',
            year + '-12-31',
            'Mitgliedsbeitrag 4. Quartal ' + year,
        ],
    };
    if (values[value])
        [
            createForm.period_start,
            createForm.period_end,
            createForm.description,
        ] = values[value];
}
</script>

<template>
    <PaymentsPage active="create">
        <form
            class="space-y-5 rounded-xl border bg-card p-5"
            @submit.prevent="
                createForm.post('/beitraege/anlegen', { preserveScroll: true })
            "
        >
            <div>
                <h2 class="font-semibold">Offene Beiträge anlegen</h2>
                <p class="text-sm text-muted-foreground">
                    Aktive Mitglieder nach Eigenschaften filtern; identische
                    Beiträge werden übersprungen.
                </p>
            </div>
            <div class="grid gap-5 md:grid-cols-2 2xl:grid-cols-3">
                <div class="min-w-0 space-y-2">
                    <Label for="period">Zeitraumvorlage</Label
                    ><select
                        id="period"
                        class="field"
                        @change="
                            period(($event.target as HTMLSelectElement).value)
                        "
                    >
                        <option value="year">Jahr {{ year }}</option>
                        <option value="h1">1. Halbjahr</option>
                        <option value="h2">2. Halbjahr</option>
                        <option value="q1">1. Quartal</option>
                        <option value="q2">2. Quartal</option>
                        <option value="q3">3. Quartal</option>
                        <option value="q4">4. Quartal</option>
                    </select>
                </div>
                <div class="min-w-0 space-y-2">
                    <Label for="period-start">Von</Label
                    ><Input
                        id="period-start"
                        v-model="createForm.period_start"
                        type="date"
                        class="date-safe"
                    /><InputError :message="createForm.errors.period_start" />
                </div>
                <div class="min-w-0 space-y-2">
                    <Label for="period-end">Bis</Label
                    ><Input
                        id="period-end"
                        v-model="createForm.period_end"
                        type="date"
                        class="date-safe"
                    /><InputError :message="createForm.errors.period_end" />
                </div>
                <div class="min-w-0 space-y-2">
                    <Label for="due-date">Fällig am</Label
                    ><Input
                        id="due-date"
                        v-model="createForm.due_date"
                        type="date"
                        class="date-safe"
                    /><InputError :message="createForm.errors.due_date" />
                </div>
                <div class="space-y-2 md:col-span-2">
                    <Label for="description">Bezeichnung</Label
                    ><Input
                        id="description"
                        v-model="createForm.description"
                    /><InputError :message="createForm.errors.description" />
                </div>
                <div class="space-y-2">
                    <Label for="amount-mode">Betragsquelle</Label
                    ><select
                        id="amount-mode"
                        v-model="createForm.amount_mode"
                        class="field"
                    >
                        <option value="fixed">Fester Betrag</option>
                        <option value="member">
                            Förderbeitrag des Mitglieds
                        </option>
                    </select>
                </div>
                <div
                    v-if="createForm.amount_mode === 'fixed'"
                    class="space-y-2"
                >
                    <Label for="amount">Betrag in Euro</Label
                    ><Input
                        id="amount"
                        v-model="createForm.amount"
                        type="number"
                        min="0.01"
                        step="0.01"
                    /><InputError :message="createForm.errors.amount" />
                </div>
                <div class="space-y-2">
                    <Label for="membership">Mitgliedschaft</Label
                    ><SearchableDropdown
                        id="membership"
                        v-model="createForm.membership_type"
                        :options="membershipOptions"
                        search-placeholder="Mitgliedschaft suchen"
                        aria-label="Mitgliedschaft filtern"
                        trigger-class="h-9 w-full rounded-md border border-input bg-background px-3"
                    />
                </div>
                <div class="space-y-2">
                    <Label for="payment-method">Zahlungsart</Label
                    ><SearchableDropdown
                        id="payment-method"
                        v-model="createForm.payment_method"
                        :options="paymentMethodOptions"
                        search-placeholder="Zahlungsart suchen"
                        aria-label="Zahlungsart filtern"
                        trigger-class="h-9 w-full rounded-md border border-input bg-background px-3"
                    />
                </div>
                <div class="space-y-2">
                    <Label for="honorary">Ehrenmitglieder</Label
                    ><select
                        id="honorary"
                        v-model="createForm.honorary"
                        class="field"
                    >
                        <option value="exclude">Ausschließen</option>
                        <option value="include">Einschließen</option>
                        <option value="only">Nur Ehrenmitglieder</option>
                    </select>
                </div>
            </div>
            <section class="space-y-4 rounded-lg border bg-muted/20 p-4">
                <div>
                    <h3 class="text-sm font-medium">Weitere Filter</h3>
                    <p class="text-xs text-muted-foreground">
                        Verfügbare Filter richten sich nach der
                        Mitgliederfeld-Konfiguration.
                    </p>
                </div>
                <div
                    v-if="createForm.filters.length"
                    class="grid gap-4 md:grid-cols-2 xl:grid-cols-3"
                >
                    <div
                        v-for="filterItem in createForm.filters"
                        :key="filterItem.key"
                        class="min-w-0 space-y-2"
                    >
                        <div class="flex items-center justify-between gap-2">
                            <Label
                                :for="`contribution-filter-${filterItem.key}`"
                            >
                                {{
                                    contributionFilterField(filterItem.key)
                                        ?.label
                                }}
                            </Label>
                            <button
                                type="button"
                                class="text-xs text-muted-foreground hover:text-foreground"
                                @click="
                                    removeContributionFilter(filterItem.key)
                                "
                            >
                                Entfernen
                            </button>
                        </div>
                        <SearchableDropdown
                            v-if="
                                ['select', 'boolean'].includes(
                                    contributionFilterField(filterItem.key)
                                        ?.type || '',
                                )
                            "
                            :id="`contribution-filter-${filterItem.key}`"
                            v-model="filterItem.value"
                            :options="contributionValueOptions(filterItem.key)"
                            search-placeholder="Wert suchen"
                            :aria-label="
                                contributionFilterField(filterItem.key)?.label
                            "
                            trigger-class="h-9 w-full rounded-md border border-input bg-background px-3"
                        />
                        <Input
                            v-else
                            :id="`contribution-filter-${filterItem.key}`"
                            v-model="filterItem.value"
                            :type="
                                contributionFilterField(filterItem.key)
                                    ?.type === 'date'
                                    ? 'date'
                                    : ['number', 'decimal'].includes(
                                            contributionFilterField(
                                                filterItem.key,
                                            )?.type || '',
                                        )
                                      ? 'number'
                                      : 'text'
                            "
                            :step="
                                contributionFilterField(filterItem.key)
                                    ?.type === 'decimal'
                                    ? '0.01'
                                    : undefined
                            "
                            :class="{
                                'date-safe':
                                    contributionFilterField(filterItem.key)
                                        ?.type === 'date',
                            }"
                            required
                        />
                    </div>
                </div>
                <div
                    v-if="availableContributionFilters.length"
                    class="flex flex-col gap-2 sm:flex-row"
                >
                    <SearchableDropdown
                        v-model="filterFieldToAdd"
                        id="additional-contribution-filter"
                        :options="additionalFilterOptions"
                        root-class="w-full sm:max-w-sm"
                        trigger-class="h-9 w-full rounded-md border border-input bg-background px-3"
                        aria-label="Weiteren Filter auswählen"
                        search-placeholder="Feld suchen"
                    />
                    <Button
                        type="button"
                        variant="outline"
                        :disabled="!filterFieldToAdd"
                        @click="addContributionFilter"
                    >
                        <Plus class="size-4" />Filter hinzufügen
                    </Button>
                </div>
                <p v-else class="text-xs text-muted-foreground">
                    Alle verfügbaren Filter wurden hinzugefügt.
                </p>
            </section>
            <label v-if="club.tax_deductible_enabled" class="flex gap-2 text-sm"
                ><input
                    v-model="createForm.tax_deductible"
                    type="checkbox"
                />Als möglicherweise steuerlich abzugsfähig kennzeichnen</label
            >
            <Button type="submit" :disabled="createForm.processing"
                ><Spinner v-if="createForm.processing" /><CalendarPlus
                    v-else
                    class="size-4"
                />Beiträge anlegen</Button
            >
        </form>
    </PaymentsPage>
</template>
