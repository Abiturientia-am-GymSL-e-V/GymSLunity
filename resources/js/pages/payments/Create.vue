<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { CalendarPlus, Plus } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import PaymentsPage from '@/components/payments/PaymentsPage.vue';
import SearchableDropdown from '@/components/SearchableDropdown.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { localDateString } from '@/lib/format';
import { firstError } from '@/lib/formErrors';
import { assignmentFilterOptions, isTemporal } from '@/lib/memberFormatting';
import type { ContributionFilterField, PaymentsClub } from '@/types/payments';

type PeriodTemplate = {
    value: string;
    label: string;
    start: string;
    end: string;
    description: string;
};

const props = defineProps<{
    filterOptions: {
        membership_types: string[];
        payment_methods: string[];
        fields: ContributionFilterField[];
    };
    club: PaymentsClub;
    periodTemplates: PeriodTemplate[];
}>();
defineOptions({
    layout: { breadcrumbs: [{ title: 'Beiträge', href: '/beitraege' }] },
});

const today = localDateString();
const fiscalYear = props.periodTemplates[0];
const createForm = useForm({
    period_start: fiscalYear.start,
    period_end: fiscalYear.end,
    due_date: today,
    description: fiscalYear.description,
    amount_mode: 'fixed',
    amount: '',
    membership_type: '',
    payment_method: '',
    honorary: 'exclude',
    tax_deductible: false,
    filters: [] as Array<{ key: string; value: string }>,
});
const filterFieldToAdd = ref('');
const periodTemplate = ref('year');
const periodOptions = props.periodTemplates.map(({ value, label }) => ({
    value,
    label,
}));
const amountModeOptions = [
    { value: 'fixed', label: 'Fester Betrag' },
    { value: 'member', label: 'Förderbeitrag des Mitglieds' },
];
const honoraryOptions = [
    { value: 'exclude', label: 'Ausschließen' },
    { value: 'include', label: 'Einschließen' },
    { value: 'only', label: 'Nur Ehrenmitglieder' },
];
watch(periodTemplate, (value) => period(value));
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
    if (field && isTemporal(field)) return assignmentFilterOptions(field);
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
    const template = props.periodTemplates.find(
        (entry) => entry.value === value,
    );
    if (template)
        [
            createForm.period_start,
            createForm.period_end,
            createForm.description,
        ] = [template.start, template.end, template.description];
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
                    ><SearchableDropdown
                        id="period"
                        v-model="periodTemplate"
                        :options="periodOptions"
                        placeholder="Zeitraum auswählen"
                        search-placeholder="Zeitraum suchen"
                        empty-text="Kein Zeitraum gefunden"
                        aria-label="Zeitraumvorlage auswählen"
                        trigger-class="h-9 w-full rounded-md border border-input bg-background px-3"
                    />
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
                    ><SearchableDropdown
                        id="amount-mode"
                        v-model="createForm.amount_mode"
                        :options="amountModeOptions"
                        placeholder="Betragsquelle auswählen"
                        search-placeholder="Betragsquelle suchen"
                        empty-text="Keine Betragsquelle gefunden"
                        aria-label="Betragsquelle auswählen"
                        trigger-class="h-9 w-full rounded-md border border-input bg-background px-3"
                    /><InputError :message="createForm.errors.amount_mode" />
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
                        placeholder="Alle Mitgliedschaften"
                        search-placeholder="Mitgliedschaft suchen"
                        empty-text="Keine Mitgliedschaft gefunden"
                        aria-label="Mitgliedschaft filtern"
                        trigger-class="h-9 w-full rounded-md border border-input bg-background px-3"
                    /><InputError
                        :message="createForm.errors.membership_type"
                    />
                </div>
                <div class="space-y-2">
                    <Label for="payment-method">Zahlungsart</Label
                    ><SearchableDropdown
                        id="payment-method"
                        v-model="createForm.payment_method"
                        :options="paymentMethodOptions"
                        placeholder="Alle Zahlungsarten"
                        search-placeholder="Zahlungsart suchen"
                        empty-text="Keine Zahlungsart gefunden"
                        aria-label="Zahlungsart filtern"
                        trigger-class="h-9 w-full rounded-md border border-input bg-background px-3"
                    /><InputError :message="createForm.errors.payment_method" />
                </div>
                <div class="space-y-2">
                    <Label for="honorary">Ehrenmitglieder</Label
                    ><SearchableDropdown
                        id="honorary"
                        v-model="createForm.honorary"
                        :options="honoraryOptions"
                        placeholder="Ehrenmitglieder berücksichtigen"
                        search-placeholder="Auswahl suchen"
                        empty-text="Keine Auswahl gefunden"
                        aria-label="Ehrenmitglieder berücksichtigen"
                        trigger-class="h-9 w-full rounded-md border border-input bg-background px-3"
                    /><InputError :message="createForm.errors.honorary" />
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
                        v-for="(filterItem, filterIndex) in createForm.filters"
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
                            placeholder="Wert auswählen"
                            search-placeholder="Wert suchen"
                            empty-text="Kein Wert gefunden"
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
                        <InputError
                            :message="
                                firstError(
                                    createForm.errors,
                                    `filters.${filterIndex}`,
                                )
                            "
                        />
                    </div>
                </div>
                <InputError :message="createForm.errors.filters" />
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
                        placeholder="Feld auswählen"
                        search-placeholder="Feld suchen"
                        empty-text="Kein Feld gefunden"
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
            <InputError :message="createForm.errors.tax_deductible" />
            <Button type="submit" :disabled="createForm.processing"
                ><Spinner v-if="createForm.processing" /><CalendarPlus
                    v-else
                    class="size-4"
                />Beiträge anlegen</Button
            >
        </form>
    </PaymentsPage>
</template>
