<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { Check, Plus, Trash2 } from '@lucide/vue';
import { computed } from 'vue';
import InputError from '@/components/InputError.vue';
import SearchableDropdown from '@/components/SearchableDropdown.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { resourceLabel } from '@/lib/bookings';
import type {
    BookableInventoryItem,
    BookingMemberField,
    BookingResource,
    BookingRule,
} from '@/types/bookings';

const props = defineProps<{
    resources: BookingResource[];
    inventoryItems: BookableInventoryItem[];
    memberFields: BookingMemberField[];
    editingResourceId: number | null;
}>();
const editing = computed(() =>
    props.resources.find((resource) => resource.id === props.editingResourceId),
);
const emptyPricingRule = () => ({
    from_value: 0,
    from_unit: 'hours' as const,
    unit_value: 1,
    unit: 'hours' as const,
    price: '',
});
function initialPricingRules() {
    if (editing.value?.pricing_rules.length) {
        return editing.value.pricing_rules.map((rule) => ({
            from_value: rule.from_value,
            from_unit: rule.from_unit,
            unit_value: rule.unit_value,
            unit: rule.unit,
            price: (rule.price_cents / 100).toFixed(2),
        }));
    }
    if (editing.value && ['hour', 'day'].includes(editing.value.price_mode)) {
        const unit = editing.value.price_mode === 'day' ? 'days' : 'hours';
        return [
            {
                from_value: 0,
                from_unit: unit,
                unit_value: 1,
                unit,
                price: (editing.value.price_cents / 100).toFixed(2),
            },
        ];
    }

    return [emptyPricingRule()];
}
const form = useForm({
    name: editing.value?.name ?? '',
    description: editing.value?.description ?? '',
    location: editing.value?.location ?? '',
    parent_id: editing.value?.parent_id ? String(editing.value.parent_id) : '',
    inventory_item_id: editing.value?.inventory_item_id
        ? String(editing.value.inventory_item_id)
        : '',
    access_rules: (editing.value?.access_rules ?? []).map((rule) => ({
        ...rule,
    })),
    auto_approve_rules: (editing.value?.auto_approve_rules ?? []).map(
        (rule) => ({ ...rule }),
    ),
    price_mode: (['hour', 'day'].includes(editing.value?.price_mode ?? '')
        ? 'duration'
        : (editing.value?.price_mode ?? 'free')) as
        | 'free'
        | 'once'
        | 'duration',
    price:
        editing.value?.price_mode === 'once'
            ? (editing.value.price_cents / 100).toFixed(2)
            : '',
    pricing_rules: initialPricingRules(),
    is_active: editing.value?.is_active ?? true,
});

const inventoryOptions = computed(() => [
    { value: '', label: 'Kein Inventargegenstand' },
    ...props.inventoryItems.map((item) => ({
        value: String(item.id),
        label: item.number + ' · ' + item.name,
        search: item.number + ' ' + item.name + ' ' + item.location,
    })),
]);
const parentOptions = computed(() => [
    { value: '', label: 'Keine übergeordnete Ressource' },
    ...props.resources
        .filter((resource) => resource.id !== props.editingResourceId)
        .map((resource) => ({
            value: String(resource.id),
            label: resourceLabel(resource, props.resources),
        })),
]);
const fieldOptions = computed(() =>
    props.memberFields.map((field) => ({
        value: field.key,
        label: field.label,
    })),
);
const priceModeOptions = [
    { value: 'free', label: 'Kostenlos' },
    { value: 'once', label: 'Einmaliger Preis' },
    { value: 'duration', label: 'Preis nach Dauer / Staffel' },
];
const unitOptions = [
    { value: 'minutes', label: 'Minuten' },
    { value: 'hours', label: 'Stunden' },
    { value: 'days', label: 'Tage' },
];

function field(key: string) {
    return props.memberFields.find((item) => item.key === key);
}
function valueOptions(rule: BookingRule) {
    return field(rule.field_key)?.options ?? [];
}
function addAccessRule() {
    const first = props.memberFields[0];
    if (first?.options[0])
        form.access_rules.push({
            field_key: first.key,
            value: first.options[0].value,
        });
}
function selectRuleField(rule: BookingRule, value: string) {
    const previousKey = ruleKey(rule);
    form.auto_approve_rules = form.auto_approve_rules.filter(
        (item) => ruleKey(item) !== previousKey,
    );
    rule.field_key = value;
    rule.value = field(value)?.options[0]?.value ?? '';
}
const ruleKey = (rule: BookingRule) => rule.field_key + ':' + rule.value;
function selectRuleValue(rule: BookingRule, value: string) {
    const previousKey = ruleKey(rule);
    form.auto_approve_rules = form.auto_approve_rules.filter(
        (item) => ruleKey(item) !== previousKey,
    );
    rule.value = value;
}
function automatic(rule: BookingRule) {
    return form.auto_approve_rules.some(
        (item) => ruleKey(item) === ruleKey(rule),
    );
}
function toggleAutomatic(rule: BookingRule, checked: boolean) {
    if (checked && !automatic(rule)) form.auto_approve_rules.push({ ...rule });
    if (!checked)
        form.auto_approve_rules = form.auto_approve_rules.filter(
            (item) => ruleKey(item) !== ruleKey(rule),
        );
}
function removeAccessRule(index: number) {
    const [removed] = form.access_rules.splice(index, 1);
    if (removed)
        form.auto_approve_rules = form.auto_approve_rules.filter(
            (item) => ruleKey(item) !== ruleKey(removed),
        );
}
function useInventoryItem() {
    const item = props.inventoryItems.find(
        (entry) => String(entry.id) === form.inventory_item_id,
    );
    if (!item) return;
    form.name = item.name;
    form.description = item.description ?? '';
    form.location = item.location;
}
function submit() {
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            if (!editing.value) form.reset();
        },
    };
    if (editing.value)
        form.patch('/buchungen/ressourcen/' + editing.value.id, options);
    else form.post('/buchungen/ressourcen', options);
}
</script>

<template>
    <section class="space-y-5">
        <header class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h2 class="text-lg font-semibold">
                    {{ editing ? 'Ressource bearbeiten' : 'Ressource anlegen' }}
                </h2>
                <p class="mt-1 text-sm text-muted-foreground">
                    Berechtigungen, automatische Freigabe und Preisstaffeln
                    werden je Ressource festgelegt.
                </p>
            </div>
            <Button
                v-if="editing"
                type="button"
                variant="outline"
                @click="router.get('/buchungen/ressourcen')"
                >Abbrechen</Button
            >
        </header>

        <form class="space-y-6" @submit.prevent="submit">
            <section class="rounded-xl border bg-card p-5">
                <div class="grid gap-5 sm:grid-cols-2">
                    <div
                        v-if="inventoryItems.length"
                        class="min-w-0 space-y-2 sm:col-span-2"
                    >
                        <Label for="resource-inventory"
                            >Daten aus dem Inventar übernehmen</Label
                        >
                        <div class="flex flex-col gap-2 sm:flex-row">
                            <SearchableDropdown
                                id="resource-inventory"
                                :model-value="form.inventory_item_id"
                                :options="inventoryOptions"
                                root-class="min-w-0 flex-1"
                                trigger-class="h-9 w-full rounded-md border border-input bg-background px-3"
                                aria-label="Inventargegenstand auswählen"
                                search-placeholder="Inventarnummer oder Name suchen"
                                empty-text="Kein Inventargegenstand gefunden"
                                @update:model-value="
                                    form.inventory_item_id = $event
                                "
                            />
                            <Button
                                type="button"
                                variant="outline"
                                :disabled="!form.inventory_item_id"
                                @click="useInventoryItem"
                                >Angaben übernehmen</Button
                            >
                        </div>
                        <InputError :message="form.errors.inventory_item_id" />
                    </div>
                    <div class="min-w-0 space-y-2">
                        <Label for="resource-name">Name *</Label>
                        <Input
                            id="resource-name"
                            v-model="form.name"
                            required
                        />
                        <InputError :message="form.errors.name" />
                    </div>
                    <div class="min-w-0 space-y-2">
                        <Label for="resource-location">Ort</Label>
                        <Input id="resource-location" v-model="form.location" />
                        <InputError :message="form.errors.location" />
                    </div>
                    <div class="min-w-0 space-y-2 sm:col-span-2">
                        <Label for="resource-description">Beschreibung</Label>
                        <Textarea
                            id="resource-description"
                            v-model="form.description"
                        />
                        <InputError :message="form.errors.description" />
                    </div>
                    <div class="min-w-0 space-y-2">
                        <Label for="resource-parent"
                            >Übergeordnete Ressource</Label
                        >
                        <SearchableDropdown
                            id="resource-parent"
                            :model-value="form.parent_id"
                            :options="parentOptions"
                            trigger-class="h-9 w-full rounded-md border border-input bg-background px-3"
                            aria-label="Übergeordnete Ressource auswählen"
                            search-placeholder="Ressource suchen"
                            empty-text="Keine Ressource gefunden"
                            @update:model-value="form.parent_id = $event"
                        />
                        <InputError :message="form.errors.parent_id" />
                    </div>
                    <label
                        class="flex items-center gap-3 self-end rounded-lg border p-3 text-sm"
                    >
                        <Checkbox v-model="form.is_active" />
                        Ressource ist buchbar
                    </label>
                </div>
            </section>

            <section class="space-y-4 rounded-xl border bg-card p-5">
                <div>
                    <h3 class="font-semibold">Buchungsberechtigung</h3>
                    <p class="mt-1 text-sm text-muted-foreground">
                        Ein Mitglied ist berechtigt, wenn mindestens eine Regel
                        zutrifft. Ohne Regel dürfen alle Mitglieder anfragen.
                    </p>
                </div>
                <div
                    v-for="(rule, index) in form.access_rules"
                    :key="index"
                    class="grid gap-2 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto]"
                >
                    <SearchableDropdown
                        :id="'resource-rule-field-' + index"
                        :model-value="rule.field_key"
                        :options="fieldOptions"
                        trigger-class="h-9 w-full rounded-md border border-input bg-background px-3"
                        aria-label="Mitgliedseigenschaft auswählen"
                        search-placeholder="Eigenschaft suchen"
                        empty-text="Keine Eigenschaft gefunden"
                        @update:model-value="selectRuleField(rule, $event)"
                    />
                    <SearchableDropdown
                        :id="'resource-rule-value-' + index"
                        :model-value="rule.value"
                        :options="valueOptions(rule)"
                        trigger-class="h-9 w-full rounded-md border border-input bg-background px-3"
                        aria-label="Feldwert auswählen"
                        search-placeholder="Feldwert suchen"
                        empty-text="Kein Feldwert gefunden"
                        @update:model-value="selectRuleValue(rule, $event)"
                    />
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        aria-label="Berechtigungsregel entfernen"
                        @click="removeAccessRule(index)"
                        ><Trash2 class="size-4"
                    /></Button>
                </div>
                <Button
                    type="button"
                    variant="outline"
                    :disabled="!memberFields.length"
                    @click="addAccessRule"
                    ><Plus class="size-4" />Berechtigung hinzufügen</Button
                >
                <InputError :message="form.errors.access_rules" />

                <div
                    v-if="form.access_rules.length"
                    class="space-y-3 border-t pt-4"
                >
                    <h3 class="font-semibold">Automatische Bestätigung</h3>
                    <p class="text-sm text-muted-foreground">
                        Nur zuvor zugelassene Gruppen können automatisch
                        bestätigt werden.
                    </p>
                    <label
                        v-for="rule in form.access_rules"
                        :key="ruleKey(rule)"
                        class="flex items-center gap-3 text-sm"
                    >
                        <Checkbox
                            :model-value="automatic(rule)"
                            @update:model-value="
                                toggleAutomatic(rule, Boolean($event))
                            "
                        />
                        {{ field(rule.field_key)?.label }}:
                        {{
                            valueOptions(rule).find(
                                (option) => option.value === rule.value,
                            )?.label
                        }}
                    </label>
                    <InputError :message="form.errors.auto_approve_rules" />
                </div>
            </section>

            <section class="space-y-5 rounded-xl border bg-card p-5">
                <div class="min-w-0 space-y-2">
                    <Label for="resource-price-mode">Preisberechnung</Label>
                    <SearchableDropdown
                        id="resource-price-mode"
                        :model-value="form.price_mode"
                        :options="priceModeOptions"
                        trigger-class="h-9 w-full rounded-md border border-input bg-background px-3"
                        aria-label="Preisberechnung auswählen"
                        search-placeholder="Preisberechnung suchen"
                        empty-text="Keine Preisberechnung gefunden"
                        @update:model-value="
                            form.price_mode = $event as typeof form.price_mode
                        "
                    />
                </div>
                <div
                    v-if="form.price_mode === 'once'"
                    class="min-w-0 space-y-2"
                >
                    <Label for="resource-price">Einmaliger Preis in Euro</Label>
                    <Input
                        id="resource-price"
                        v-model="form.price"
                        type="number"
                        min="0"
                        step="0.01"
                        required
                    />
                    <InputError :message="form.errors.price" />
                </div>
                <template v-if="form.price_mode === 'duration'">
                    <div
                        v-for="(rule, index) in form.pricing_rules"
                        :key="index"
                        class="grid gap-3 rounded-lg border p-4 sm:grid-cols-2 xl:grid-cols-[6rem_minmax(0,1fr)_6rem_minmax(0,1fr)_8rem_auto] xl:items-end"
                    >
                        <div class="min-w-0 space-y-2">
                            <Label :for="'price-from-' + index">Ab</Label>
                            <Input
                                :id="'price-from-' + index"
                                v-model="rule.from_value"
                                type="number"
                                min="0"
                                required
                            />
                        </div>
                        <SearchableDropdown
                            :id="'price-from-unit-' + index"
                            :model-value="rule.from_unit"
                            :options="unitOptions"
                            trigger-class="h-9 w-full rounded-md border border-input bg-background px-3"
                            aria-label="Schwellenwert-Einheit auswählen"
                            @update:model-value="
                                rule.from_unit = $event as typeof rule.from_unit
                            "
                        />
                        <div class="min-w-0 space-y-2">
                            <Label :for="'price-unit-' + index">Je</Label>
                            <Input
                                :id="'price-unit-' + index"
                                v-model="rule.unit_value"
                                type="number"
                                min="1"
                                required
                            />
                        </div>
                        <SearchableDropdown
                            :id="'price-unit-name-' + index"
                            :model-value="rule.unit"
                            :options="unitOptions"
                            trigger-class="h-9 w-full rounded-md border border-input bg-background px-3"
                            aria-label="Abrechnungseinheit auswählen"
                            @update:model-value="
                                rule.unit = $event as typeof rule.unit
                            "
                        />
                        <div class="min-w-0 space-y-2">
                            <Label :for="'price-value-' + index">Euro</Label>
                            <Input
                                :id="'price-value-' + index"
                                v-model="rule.price"
                                type="number"
                                min="0"
                                step="0.01"
                                required
                            />
                        </div>
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            :disabled="form.pricing_rules.length === 1"
                            aria-label="Preisstaffel entfernen"
                            @click="form.pricing_rules.splice(index, 1)"
                            ><Trash2 class="size-4"
                        /></Button>
                    </div>
                    <Button
                        type="button"
                        variant="outline"
                        @click="form.pricing_rules.push(emptyPricingRule())"
                        ><Plus class="size-4" />Preisstaffel hinzufügen</Button
                    >
                    <InputError :message="form.errors.pricing_rules" />
                </template>
            </section>

            <Button :disabled="form.processing">
                <Check class="size-4" />
                {{ editing ? 'Änderungen speichern' : 'Ressource anlegen' }}
            </Button>
        </form>
    </section>
</template>
