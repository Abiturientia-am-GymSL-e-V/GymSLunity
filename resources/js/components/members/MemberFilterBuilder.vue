<script setup lang="ts">
import { Plus, X } from '@lucide/vue';
import { computed, ref } from 'vue';
import SearchableDropdown from '@/components/SearchableDropdown.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { assignmentFilterOptions, isTemporal } from '@/lib/memberFormatting';
import type { MemberFilter, MemberFilterField } from '@/types/members';

/**
 * Filters added field by field, as used for signature lists and
 * communication recipients. Matching happens in the parent (in the browser
 * or on the server).
 */
const props = withDefaults(
    defineProps<{
        fields: MemberFilterField[];
        idPrefix: string;
        /** Offer "any value" and "no value" for choice fields. */
        presenceOptions?: boolean;
    }>(),
    { presenceOptions: false },
);
const filters = defineModel<MemberFilter[]>({ required: true });

const fieldToAdd = ref('');
const rangeTypes = ['date', 'number', 'decimal'];
const choiceTypes = ['select', 'boolean', 'status'];
const field = (key: string) => props.fields.find((item) => item.key === key);
const fieldsToAdd = computed(() =>
    props.fields.filter(
        (item) => !filters.value.some((filter) => filter.key === item.key),
    ),
);
const fieldOptions = computed(() => [
    { value: '', label: 'Feld auswählen' },
    ...fieldsToAdd.value.map((item) => ({
        value: item.key,
        label: item.label,
    })),
]);
function valueOptions(key: string) {
    const item = field(key);
    if (!item) return [];
    if (isTemporal(item)) return assignmentFilterOptions(item);
    const options =
        item.type === 'boolean'
            ? [
                  { value: '1', label: 'Ja' },
                  { value: '0', label: 'Nein' },
              ]
            : Object.entries(item.options).map(([value, label]) => ({
                  value,
                  label,
              }));
    return props.presenceOptions && item.type === 'select'
        ? [
              ...options,
              { value: '__any__', label: 'Beliebiger Wert' },
              { value: '__none__', label: 'Keine Angabe' },
          ]
        : options;
}
function nextId() {
    return Math.max(0, ...filters.value.map((filter) => filter.id)) + 1;
}
function add() {
    const item = field(fieldToAdd.value);
    if (!item || !fieldsToAdd.value.includes(item)) return;
    filters.value = [
        ...filters.value,
        {
            id: nextId(),
            key: item.key,
            value:
                item.type === 'status'
                    ? (Object.keys(item.options)[0] ?? '')
                    : '',
            valueTo: '',
        },
    ];
    fieldToAdd.value = '';
}
function remove(id: number) {
    filters.value = filters.value.filter((filter) => filter.id !== id);
}
</script>

<template>
    <div class="space-y-4">
        <div
            v-if="filters.length"
            class="grid min-w-0 gap-4 md:grid-cols-2 xl:grid-cols-3"
        >
            <div
                v-for="filterItem in filters"
                :key="filterItem.id"
                class="min-w-0 space-y-3 rounded-lg border bg-background p-4"
            >
                <div class="flex min-w-0 items-center justify-between gap-2">
                    <Label
                        v-if="
                            !rangeTypes.includes(
                                field(filterItem.key)?.type || '',
                            )
                        "
                        :for="`${idPrefix}-${filterItem.id}`"
                        class="min-w-0 truncate"
                    >
                        {{ field(filterItem.key)?.label }}
                    </Label>
                    <span v-else class="min-w-0 truncate text-sm font-medium">
                        {{ field(filterItem.key)?.label }}
                    </span>
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon-sm"
                        :aria-label="`${field(filterItem.key)?.label} entfernen`"
                        @click="remove(filterItem.id)"
                    >
                        <X class="size-4" />
                    </Button>
                </div>
                <SearchableDropdown
                    v-if="
                        choiceTypes.includes(
                            field(filterItem.key)?.type || '',
                        ) || isTemporal(field(filterItem.key))
                    "
                    :id="`${idPrefix}-${filterItem.id}`"
                    v-model="filterItem.value"
                    :options="valueOptions(filterItem.key)"
                    placeholder="Wert auswählen"
                    search-placeholder="Wert suchen"
                    empty-text="Kein Wert gefunden."
                    :aria-label="field(filterItem.key)?.label"
                    trigger-class="h-9 w-full rounded-md border border-input bg-background px-3"
                />
                <div
                    v-else-if="
                        rangeTypes.includes(field(filterItem.key)?.type || '')
                    "
                    class="grid min-w-0 gap-3 sm:grid-cols-2"
                >
                    <div class="min-w-0 space-y-2">
                        <Label :for="`${idPrefix}-${filterItem.id}-from`"
                            >Von</Label
                        >
                        <Input
                            :id="`${idPrefix}-${filterItem.id}-from`"
                            v-model="filterItem.value"
                            :type="
                                field(filterItem.key)?.type === 'date'
                                    ? 'date'
                                    : 'number'
                            "
                            :step="
                                field(filterItem.key)?.type === 'decimal'
                                    ? '0.01'
                                    : undefined
                            "
                            :max="filterItem.valueTo || undefined"
                        />
                    </div>
                    <div class="min-w-0 space-y-2">
                        <Label :for="`${idPrefix}-${filterItem.id}-to`"
                            >Bis</Label
                        >
                        <Input
                            :id="`${idPrefix}-${filterItem.id}-to`"
                            v-model="filterItem.valueTo"
                            :type="
                                field(filterItem.key)?.type === 'date'
                                    ? 'date'
                                    : 'number'
                            "
                            :step="
                                field(filterItem.key)?.type === 'decimal'
                                    ? '0.01'
                                    : undefined
                            "
                            :min="filterItem.value || undefined"
                        />
                    </div>
                </div>
                <Input
                    v-else
                    :id="`${idPrefix}-${filterItem.id}`"
                    v-model="filterItem.value"
                    type="search"
                    placeholder="Suchwert eingeben"
                />
            </div>
        </div>
        <p v-else class="text-sm text-muted-foreground">
            Es ist kein Filter aktiv.
        </p>
        <div
            v-if="fieldsToAdd.length"
            class="grid min-w-0 gap-3 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-end"
        >
            <div class="min-w-0 space-y-2">
                <Label :for="`${idPrefix}-to-add`">Filterfeld</Label>
                <SearchableDropdown
                    :id="`${idPrefix}-to-add`"
                    v-model="fieldToAdd"
                    :options="fieldOptions"
                    placeholder="Feld auswählen"
                    search-placeholder="Mitgliedsfeld suchen"
                    empty-text="Kein weiteres Feld gefunden."
                    aria-label="Filterfeld auswählen"
                    trigger-class="h-9 w-full rounded-md border border-input bg-background px-3"
                />
            </div>
            <Button
                type="button"
                variant="outline"
                :disabled="!fieldToAdd"
                @click="add"
            >
                <Plus class="size-4" />
                Filter hinzufügen
            </Button>
        </div>
        <p v-else class="text-xs text-muted-foreground">
            Alle verfügbaren Filter wurden hinzugefügt.
        </p>
    </div>
</template>
