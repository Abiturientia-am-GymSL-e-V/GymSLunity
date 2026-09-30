<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { ArrowDown, ArrowUp, Pencil, Plus, Save, Trash2 } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import ConfigurationNav from '@/components/configuration/ConfigurationNav.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { index, store, update, reorder } from '@/routes/configuration/fields';

type Choice = {
    value: string;
    label: string;
    active: boolean;
    /** Office options only. */
    board?: boolean;
    mandatory?: boolean;
    /** Empty means no limit. */
    max_holders?: number | string | null;
    /** Honor options only. */
    repeatable?: boolean;
};
type Definition = {
    id: number;
    key: string;
    label: string;
    type: string;
    section: string;
    position: number;
    is_active: boolean;
    is_custom: boolean;
    required: boolean;
    filterable: boolean;
    show_in_table: boolean;
    selfservice_visible: boolean;
    selfservice_editable: boolean;
    allow_multiple: boolean;
    options: Choice[];
};
const props = defineProps<{
    fields: Definition[];
    sections: Record<string, string>;
    types: Record<string, string>;
    version: number;
}>();
defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Konfiguration', href: index() },
            { title: 'Mitgliedsfelder' },
        ],
    },
});
const additionalTypes: Record<string, string> = {
    email: 'E-Mail',
    tel: 'Telefon',
};
const open = ref(false);
const selected = ref<Definition | null>(null);
const form = useForm({
    version: props.version,
    label: '',
    type: 'text',
    section: 'membership',
    is_active: true,
    required: false,
    filterable: false,
    show_in_table: false,
    selfservice_visible: false,
    selfservice_editable: false,
    allow_multiple: false,
    options: [] as Choice[],
    remove_options: [] as string[],
});
/** Department, office and honor fields record time-bound assignments. */
const TEMPORAL_TYPES = ['department', 'office', 'honor'];
const ASSIGNMENT_SECTION = 'assignments';
const temporal = computed(() => TEMPORAL_TYPES.includes(form.type));
const optionHeading = computed(
    () =>
        ({
            department: 'Abteilungen',
            office: 'Funktionen und Ämter',
            honor: 'Ereignisse und Ehrungen',
        })[form.type] ?? 'Auswahloptionen',
);
watch(
    () => form.type,
    () => {
        if (temporal.value) form.section = ASSIGNMENT_SECTION;
        else if (form.section === ASSIGNMENT_SECTION)
            form.section = 'membership';
    },
);
const orderForm = useForm({ version: props.version, ids: [] as number[] });
const locked = computed(() =>
    ['first_name', 'last_name', 'membership_type'].includes(
        selected.value?.key || '',
    ),
);
const selfserviceProtected = computed(() =>
    [
        'email',
        'deceased_at',
        'joined_at',
        'left_at',
        'department_role',
        'club_role',
        'iban',
        'mandate_reference',
        'mandate_signed_at',
        'mandate_type',
        'account_holder_first_name',
        'account_holder_last_name',
        'account_holder_street',
        'account_holder_postal_code',
        'account_holder_city',
        'account_holder_country',
    ].includes(selected.value?.key || ''),
);
function edit(field: Definition | null) {
    selected.value = field;
    form.defaults({
        version: props.version,
        label: field?.label || '',
        type: field?.type || 'text',
        section: field?.section || 'membership',
        is_active: field?.is_active ?? true,
        required: field?.required ?? false,
        filterable: field?.filterable ?? false,
        show_in_table: field?.show_in_table ?? false,
        selfservice_visible: field?.selfservice_visible ?? false,
        selfservice_editable: field?.selfservice_editable ?? false,
        allow_multiple: field?.allow_multiple ?? false,
        options: (field?.options || []).map((option) => ({
            board: false,
            mandatory: false,
            repeatable: false,
            ...option,
            max_holders: option.max_holders ?? '',
        })),
        remove_options: [],
    });
    form.reset();
    form.clearErrors();
    open.value = true;
}
function addOption() {
    form.options.push({
        value: 'option_' + crypto.randomUUID().replaceAll('-', ''),
        label: '',
        active: true,
        board: false,
        mandatory: false,
        max_holders: '',
        repeatable: false,
    });
}
function removeOption(index: number) {
    const option = form.options[index];
    if (
        selected.value?.options.some(
            (original) => original.value === option.value,
        )
    ) {
        form.remove_options.push(option.value);
    }
    form.options.splice(index, 1);
}
function save() {
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
        },
    };
    if (selected.value) form.patch(update.url(selected.value.id), options);
    else form.post(store.url(), options);
}
function updateSelfserviceVisibility() {
    if (!form.selfservice_visible) {
        form.selfservice_editable = false;
    }
}
function move(field: Definition, direction: number) {
    const group = props.fields.filter((item) => item.section === field.section);
    const other =
        group[group.findIndex((item) => item.id === field.id) + direction];
    if (!other) return;
    const ids = props.fields.map((item) => item.id);
    const a = ids.indexOf(field.id),
        b = ids.indexOf(other.id);
    [ids[a], ids[b]] = [ids[b], ids[a]];
    orderForm.version = props.version;
    orderForm.ids = ids;
    orderForm.patch(reorder.url(), { preserveScroll: true });
}
function close(value: boolean) {
    if (!value && form.processing) return;
    if (
        !value &&
        form.isDirty &&
        !window.confirm('Ungespeicherte Feldänderungen verwerfen?')
    )
        return;
    open.value = value;
}
</script>

<template>
    <Head title="Mitgliedsfelder" />
    <div class="mx-auto w-full max-w-[1200px] space-y-6 p-4 sm:p-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h1 class="text-2xl font-semibold tracking-tight">
                    Konfiguration
                </h1>
                <p class="mt-1 text-sm text-muted-foreground">
                    {{
                        $address(
                            'Passe Mitgliedsfelder und Auswahlmöglichkeiten an deinen Verein an.',
                            'Passen Sie Mitgliedsfelder und Auswahlmöglichkeiten an Ihren Verein an.',
                        )
                    }}
                </p>
            </div>
            <Button data-test="new-member-field" @click="edit(null)"
                ><Plus class="size-4" />Feld hinzufügen</Button
            >
        </div>
        <ConfigurationNav />
        <p class="text-sm leading-relaxed text-muted-foreground">
            Die Pfeile ändern die Reihenfolge innerhalb einer Gruppe. Eigene
            Felder können als Spalte und Filter in der Mitgliederliste verwendet
            werden. Deaktivierte Felder und Optionen bleiben für bereits
            gespeicherte Angaben nachvollziehbar.
        </p>
        <InputError
            :message="orderForm.errors.version || orderForm.errors.ids"
            role="alert"
        />
        <section
            v-for="(title, section) in sections"
            :key="section"
            class="overflow-hidden rounded-xl border bg-card"
        >
            <h2 class="border-b px-5 py-4 text-sm font-semibold">
                {{ title }}
            </h2>
            <p
                v-if="!fields.some((field) => field.section === section)"
                class="p-5 text-sm text-muted-foreground"
            >
                Noch keine Felder in dieser Gruppe.
            </p>
            <ul class="divide-y">
                <li
                    v-for="(field, position) in fields.filter(
                        (field) => field.section === section,
                    )"
                    :key="field.id"
                    class="flex flex-wrap items-center gap-3 p-4"
                    :data-field-key="field.key"
                >
                    <div class="min-w-0 flex-1">
                        <p class="font-medium break-words">{{ field.label }}</p>
                        <div
                            class="mt-1 flex flex-wrap gap-2 text-xs text-muted-foreground"
                        >
                            <span>{{
                                types[field.type] ||
                                additionalTypes[field.type] ||
                                field.type
                            }}</span
                            ><span v-if="field.required">Pflichtfeld</span
                            ><span v-if="field.is_custom">Zusatzfeld</span
                            ><span v-if="field.filterable">Filter</span
                            ><span v-if="field.selfservice_visible"
                                >Portal: sichtbar</span
                            ><span v-if="field.selfservice_editable"
                                >Portal: änderbar</span
                            ><span v-if="field.allow_multiple"
                                >Mehrere Ämter gleichzeitig</span
                            ><span
                                v-if="
                                    field.type === 'select' ||
                                    TEMPORAL_TYPES.includes(field.type)
                                "
                                >{{
                                    `${field.options.filter((option) => option.active).length} aktive Optionen`
                                }}</span
                            >
                        </div>
                    </div>
                    <Badge v-if="!field.is_active" variant="outline"
                        >Deaktiviert</Badge
                    >
                    <div class="flex gap-1">
                        <Button
                            variant="ghost"
                            size="icon-sm"
                            :aria-label="`${field.label} nach oben`"
                            :disabled="position === 0 || orderForm.processing"
                            @click="move(field, -1)"
                            ><ArrowUp class="size-4"
                        /></Button>
                        <Button
                            variant="ghost"
                            size="icon-sm"
                            :aria-label="`${field.label} nach unten`"
                            :disabled="
                                position ===
                                    fields.filter(
                                        (item) => item.section === section,
                                    ).length -
                                        1 || orderForm.processing
                            "
                            @click="move(field, 1)"
                            ><ArrowDown class="size-4"
                        /></Button>
                        <Button
                            variant="outline"
                            size="sm"
                            :aria-label="`${field.label} bearbeiten`"
                            @click="edit(field)"
                            ><Pencil class="size-3.5" /><span
                                class="hidden sm:inline"
                                >Bearbeiten</span
                            ></Button
                        >
                    </div>
                </li>
            </ul>
        </section>
    </div>
    <Dialog :open="open" @update:open="close">
        <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
            <DialogHeader
                ><DialogTitle>{{
                    selected ? 'Feld bearbeiten' : 'Neues Mitgliedsfeld'
                }}</DialogTitle
                ><DialogDescription
                    >Bezeichnung, Datentyp, Gruppe und Auswahloptionen
                    festlegen.</DialogDescription
                ></DialogHeader
            >
            <form
                class="space-y-4"
                data-test="member-field-form"
                @submit.prevent="save"
            >
                <InputError :message="form.errors.version" role="alert" />
                <div class="space-y-2">
                    <Label for="field-label">Bezeichnung</Label
                    ><Input
                        id="field-label"
                        v-model="form.label"
                        required
                        maxlength="120"
                    /><InputError :message="form.errors.label" />
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="space-y-2">
                        <Label for="field-type">Datentyp</Label
                        ><select
                            id="field-type"
                            v-model="form.type"
                            class="h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
                            :disabled="selected !== null && !selected.is_custom"
                        >
                            <option v-if="!types[form.type]" :value="form.type">
                                {{ form.type }}
                            </option>
                            <option
                                v-for="(label, value) in types"
                                :key="value"
                                :value="value"
                            >
                                {{ label }}
                            </option></select
                        ><InputError :message="form.errors.type" />
                    </div>
                    <div v-if="!temporal" class="space-y-2">
                        <Label for="field-section">Gruppe</Label
                        ><select
                            id="field-section"
                            v-model="form.section"
                            class="h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
                        >
                            <template
                                v-for="(title, key) in sections"
                                :key="key"
                                ><option
                                    v-if="key !== ASSIGNMENT_SECTION"
                                    :value="key"
                                >
                                    {{ title }}
                                </option></template
                            ></select
                        ><InputError :message="form.errors.section" />
                    </div>
                    <p v-else class="text-sm text-muted-foreground sm:pt-7">
                        Zuordnungen werden mit Zeitraum bzw. Datum in der
                        Mitgliederakte unter „{{
                            sections[ASSIGNMENT_SECTION]
                        }}“ gepflegt.
                    </p>
                </div>
                <div class="grid gap-3 text-sm sm:grid-cols-2">
                    <label class="flex items-center gap-2"
                        ><input
                            v-model="form.is_active"
                            type="checkbox"
                            :disabled="locked"
                            class="size-4 accent-primary"
                        />Feld aktiv</label
                    >
                    <label
                        v-if="form.type === 'office'"
                        class="flex items-center gap-2"
                        ><input
                            v-model="form.allow_multiple"
                            type="checkbox"
                            class="size-4 accent-primary"
                        />Mehrere Ämter gleichzeitig erlaubt</label
                    >
                    <template v-if="!temporal">
                        <label class="flex items-center gap-2"
                            ><input
                                v-model="form.required"
                                type="checkbox"
                                :disabled="locked"
                                class="size-4 accent-primary"
                            />Pflichtfeld</label
                        >
                        <label class="flex items-center gap-2"
                            ><input
                                v-model="form.selfservice_visible"
                                type="checkbox"
                                class="size-4 accent-primary"
                                @change="updateSelfserviceVisibility"
                            />Im Mitgliederportal anzeigen</label
                        >
                        <label class="flex items-center gap-2"
                            ><input
                                v-model="form.selfservice_editable"
                                type="checkbox"
                                :disabled="
                                    !form.selfservice_visible ||
                                    selfserviceProtected
                                "
                                class="size-4 accent-primary"
                            />Durch Mitglied änderbar</label
                        >
                        <template v-if="!selected || selected.is_custom"
                            ><label class="flex items-center gap-2"
                                ><input
                                    v-model="form.filterable"
                                    type="checkbox"
                                    class="size-4 accent-primary"
                                />Als Filter anzeigen</label
                            ><label class="flex items-center gap-2"
                                ><input
                                    v-model="form.show_in_table"
                                    type="checkbox"
                                    class="size-4 accent-primary"
                                />Spalte standardmäßig anzeigen</label
                            ></template
                        >
                    </template>
                </div>
                <InputError
                    :message="
                        form.errors.is_active ||
                        form.errors.required ||
                        form.errors.selfservice_visible ||
                        form.errors.selfservice_editable
                    "
                />
                <p
                    v-if="selfserviceProtected"
                    class="text-xs text-muted-foreground"
                >
                    Dieses Feld darf im Mitgliederportal angezeigt, aber
                    ausschließlich über den vorgesehenen Verwaltungs- oder
                    Bestätigungsweg geändert werden.
                </p>
                <div
                    v-if="form.type === 'select' || temporal"
                    class="space-y-3 rounded-lg border p-4"
                >
                    <div class="flex items-center justify-between gap-2">
                        <h3 class="text-sm font-medium">{{ optionHeading }}</h3>
                        <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            @click="addOption"
                            ><Plus class="size-3.5" />Option</Button
                        >
                    </div>
                    <p class="text-xs text-muted-foreground">
                        Deaktivierte Optionen können nicht neu gewählt werden.
                        Bisherige Werte bleiben erhalten.
                        <template v-if="form.type === 'office'"
                            >„Vorstand“ kennzeichnet Vorstandsämter, ein
                            Pflichtamt wird als unbesetzt hervorgehoben, und bei
                            mehr gleichzeitigen Inhabern als vorgesehen
                            erscheint eine Warnung.</template
                        >
                    </p>
                    <div
                        v-for="(option, i) in form.options"
                        :key="option.value"
                        class="space-y-1"
                    >
                        <div class="flex items-center gap-3">
                            <Input
                                v-model="option.label"
                                :aria-label="`Bezeichnung Option ${i + 1}`"
                                maxlength="120"
                            /><label
                                class="flex shrink-0 items-center gap-2 text-sm"
                                ><input
                                    v-model="option.active"
                                    type="checkbox"
                                    class="size-4 accent-primary"
                                    :aria-label="`Option ${i + 1} aktiv`"
                                />Aktiv</label
                            ><Button
                                type="button"
                                size="icon-sm"
                                variant="ghost"
                                :aria-label="`Option ${i + 1} löschen`"
                                @click="removeOption(i)"
                                ><Trash2 class="size-4"
                            /></Button>
                        </div>
                        <div
                            v-if="form.type === 'office'"
                            class="flex flex-wrap items-center gap-x-4 gap-y-2 text-sm"
                        >
                            <label class="flex items-center gap-2"
                                ><input
                                    v-model="option.board"
                                    type="checkbox"
                                    class="size-4 accent-primary"
                                />Vorstand</label
                            ><label class="flex items-center gap-2"
                                ><input
                                    v-model="option.mandatory"
                                    type="checkbox"
                                    class="size-4 accent-primary"
                                />Pflichtamt</label
                            ><span class="flex items-center gap-2"
                                ><Label
                                    :for="`option-max-${i}`"
                                    class="font-normal"
                                    >Höchstens gleichzeitig</Label
                                ><Input
                                    :id="`option-max-${i}`"
                                    :model-value="option.max_holders ?? ''"
                                    @update:model-value="
                                        option.max_holders = $event
                                    "
                                    type="number"
                                    min="1"
                                    max="999"
                                    class="w-20"
                                    placeholder="–"
                            /></span>
                        </div>
                        <label
                            v-else-if="form.type === 'honor'"
                            class="flex items-center gap-2 text-sm"
                            ><input
                                v-model="option.repeatable"
                                type="checkbox"
                                class="size-4 accent-primary"
                            />Mehrfach vergebbar</label
                        >
                        <InputError
                            :message="
                                form.errors[`options.${i}.label`] ||
                                form.errors[`options.${i}.value`] ||
                                form.errors[`options.${i}.max_holders`]
                            "
                        />
                    </div>
                    <InputError :message="form.errors.options" />
                </div>
                <DialogFooter
                    ><Button
                        type="button"
                        variant="outline"
                        :disabled="form.processing"
                        @click="close(false)"
                        >Abbrechen</Button
                    ><Button
                        :disabled="form.processing"
                        data-test="save-member-field"
                        ><Spinner v-if="form.processing" /><Save
                            v-else
                            class="size-4"
                        />Speichern</Button
                    ></DialogFooter
                >
            </form>
        </DialogContent>
    </Dialog>
</template>
