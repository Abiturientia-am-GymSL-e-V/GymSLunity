<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import InputError from '@/components/InputError.vue';
import SearchableDropdown from '@/components/SearchableDropdown.vue';
import StatusAlert from '@/components/StatusAlert.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import type { DepreciationMethod, InventoryOptions } from '@/types/inventory';

const props = defineProps<{ options: InventoryOptions }>();

const categoryOptions = computed(() =>
    Object.entries(props.options.categories).map(([value, label]) => ({
        value,
        label,
    })),
);
const acquisitionTypeOptions = computed(() =>
    Object.entries(props.options.acquisitionTypes).map(([value, label]) => ({
        value,
        label,
    })),
);
const depreciationOptions = computed(() =>
    Object.entries(props.options.depreciationMethods).map(([value, label]) => ({
        value,
        label,
    })),
);

const today = new Intl.DateTimeFormat('sv-SE').format(new Date());
const createForm = useForm<{
    name: string;
    category: string;
    description: string;
    manufacturer: string;
    model: string;
    serial_number: string;
    location: string;
    responsible_person: string;
    acquisition_type: string;
    acquisition_date: string;
    acquisition_cost: string;
    document_reference: string;
    document: File | null;
    depreciation_method: DepreciationMethod;
    useful_life_years: string | number;
}>({
    name: '',
    category: 'sports_equipment',
    description: '',
    manufacturer: '',
    model: '',
    serial_number: '',
    location: '',
    responsible_person: '',
    acquisition_type: 'purchase',
    acquisition_date: today,
    acquisition_cost: '',
    document_reference: '',
    document: null,
    depreciation_method: 'linear' as DepreciationMethod,
    useful_life_years: '' as string | number,
});
function selectDocument(event: Event) {
    const input = event.target as HTMLInputElement;
    createForm.document = input.files?.[0] ?? null;
}
function selectDepreciationMethod(value: string) {
    createForm.depreciation_method = value as DepreciationMethod;
}
function store() {
    createForm.post('/inventar', {
        preserveScroll: true,
        forceFormData: true,
        onSuccess: () => {
            createForm.reset();
        },
    });
}
</script>

<template>
    <form class="space-y-6" @submit.prevent="store">
        <section class="rounded-xl border bg-card">
            <div class="border-b px-5 py-4">
                <h2 class="font-semibold">Gegenstand</h2>
                <p class="mt-1 text-sm text-muted-foreground">
                    Die eindeutige Inventarnummer wird beim Speichern
                    automatisch vergeben.
                </p>
            </div>
            <div class="grid gap-5 p-5 md:grid-cols-2">
                <div class="space-y-2">
                    <Label for="inventory-name">Bezeichnung *</Label>
                    <Input
                        id="inventory-name"
                        v-model="createForm.name"
                        required
                        maxlength="255"
                        placeholder="z. B. Wettkampf-Trampolin"
                    />
                    <InputError :message="createForm.errors.name" />
                </div>
                <div class="space-y-2">
                    <Label for="inventory-category">Kategorie *</Label>
                    <SearchableDropdown
                        id="inventory-category"
                        :model-value="createForm.category"
                        :options="categoryOptions"
                        aria-label="Kategorie auswählen"
                        search-placeholder="Kategorie suchen"
                        empty-text="Keine Kategorie gefunden"
                        trigger-class="h-9 w-full rounded-md border border-input bg-background px-3 shadow-xs"
                        @update:model-value="createForm.category = $event"
                    />
                    <InputError :message="createForm.errors.category" />
                </div>
                <div class="space-y-2">
                    <Label for="inventory-manufacturer">Hersteller</Label>
                    <Input
                        id="inventory-manufacturer"
                        v-model="createForm.manufacturer"
                        maxlength="255"
                    />
                    <InputError :message="createForm.errors.manufacturer" />
                </div>
                <div class="space-y-2">
                    <Label for="inventory-model">Modell/Typ</Label>
                    <Input
                        id="inventory-model"
                        v-model="createForm.model"
                        maxlength="255"
                    />
                    <InputError :message="createForm.errors.model" />
                </div>
                <div class="space-y-2">
                    <Label for="inventory-serial">Seriennummer</Label>
                    <Input
                        id="inventory-serial"
                        v-model="createForm.serial_number"
                        maxlength="255"
                    />
                    <InputError :message="createForm.errors.serial_number" />
                </div>
                <div class="space-y-2">
                    <Label for="inventory-location">Standort *</Label>
                    <Input
                        id="inventory-location"
                        v-model="createForm.location"
                        required
                        maxlength="255"
                        placeholder="z. B. Turnhalle, Geräteraum 1"
                    />
                    <InputError :message="createForm.errors.location" />
                </div>
                <div class="space-y-2 md:col-span-2">
                    <Label for="inventory-responsible"
                        >Verantwortliche Person/Bereich</Label
                    >
                    <Input
                        id="inventory-responsible"
                        v-model="createForm.responsible_person"
                        maxlength="255"
                    />
                    <InputError
                        :message="createForm.errors.responsible_person"
                    />
                </div>
                <div class="space-y-2 md:col-span-2">
                    <Label for="inventory-description"
                        >Beschreibung/Zustand</Label
                    >
                    <textarea
                        id="inventory-description"
                        v-model="createForm.description"
                        rows="3"
                        maxlength="2000"
                        class="w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                        placeholder="Merkmale und Zustand bei Aufnahme"
                    ></textarea>
                    <InputError :message="createForm.errors.description" />
                </div>
            </div>
        </section>

        <section class="rounded-xl border bg-card">
            <div class="border-b px-5 py-4">
                <h2 class="font-semibold">Anschaffung & Bewertung</h2>
                <p class="mt-1 text-sm text-muted-foreground">
                    Grundlage für Anschaffungswert, Abschreibung und aktuellen
                    Restwert.
                </p>
            </div>
            <div class="grid gap-5 p-5 md:grid-cols-2">
                <div class="space-y-2">
                    <Label for="inventory-acquisition-type">Zugangsart *</Label>
                    <SearchableDropdown
                        id="inventory-acquisition-type"
                        :model-value="createForm.acquisition_type"
                        :options="acquisitionTypeOptions"
                        aria-label="Zugangsart auswählen"
                        search-placeholder="Zugangsart suchen"
                        empty-text="Keine Zugangsart gefunden"
                        trigger-class="h-9 w-full rounded-md border border-input bg-background px-3 shadow-xs"
                        @update:model-value="
                            createForm.acquisition_type = $event
                        "
                    />
                    <InputError :message="createForm.errors.acquisition_type" />
                </div>
                <div class="space-y-2">
                    <Label for="inventory-acquisition-date"
                        >Anschaffungs-/Herstellungsdatum *</Label
                    >
                    <Input
                        id="inventory-acquisition-date"
                        v-model="createForm.acquisition_date"
                        type="date"
                        :max="today"
                        required
                    />
                    <InputError :message="createForm.errors.acquisition_date" />
                </div>
                <div class="space-y-2">
                    <Label for="inventory-cost"
                        >Anschaffungs-/Herstellungskosten *</Label
                    >
                    <div class="relative">
                        <Input
                            id="inventory-cost"
                            v-model="createForm.acquisition_cost"
                            required
                            inputmode="decimal"
                            maxlength="12"
                            class="pr-10"
                            placeholder="0,00"
                        />
                        <span
                            class="absolute top-2 right-3 text-sm text-muted-foreground"
                            >€</span
                        >
                    </div>
                    <InputError :message="createForm.errors.acquisition_cost" />
                </div>
                <div class="space-y-2">
                    <Label for="inventory-document">Beleg/Referenz</Label>
                    <Input
                        id="inventory-document"
                        v-model="createForm.document_reference"
                        maxlength="255"
                        placeholder="z. B. Rechnung RE-2026-184"
                    />
                    <InputError
                        :message="createForm.errors.document_reference"
                    />
                </div>
                <div class="space-y-2">
                    <Label for="inventory-document-upload">
                        Rechnung / Beleg (PDF, max. 10 MB)
                    </Label>
                    <Input
                        id="inventory-document-upload"
                        type="file"
                        accept="application/pdf,.pdf"
                        @change="selectDocument"
                    />
                    <InputError :message="createForm.errors.document" />
                </div>
                <div class="space-y-2">
                    <Label for="inventory-depreciation">Abschreibung *</Label>
                    <SearchableDropdown
                        id="inventory-depreciation"
                        :model-value="createForm.depreciation_method"
                        :options="depreciationOptions"
                        aria-label="Abschreibung auswählen"
                        search-placeholder="Abschreibung suchen"
                        empty-text="Keine Abschreibungsart gefunden"
                        trigger-class="h-9 w-full rounded-md border border-input bg-background px-3 shadow-xs"
                        @update:model-value="selectDepreciationMethod"
                    />
                    <InputError
                        :message="createForm.errors.depreciation_method"
                    />
                </div>
                <div
                    v-if="createForm.depreciation_method === 'linear'"
                    class="space-y-2"
                >
                    <Label for="inventory-life"
                        >Nutzungsdauer in Jahren *</Label
                    >
                    <Input
                        id="inventory-life"
                        v-model="createForm.useful_life_years"
                        type="number"
                        min="1"
                        max="100"
                        step="1"
                        required
                    />
                    <InputError
                        :message="createForm.errors.useful_life_years"
                    />
                </div>
                <StatusAlert
                    type="info"
                    title="Hinweis zur Bewertung"
                    class="md:col-span-2"
                >
                    Die lineare Abschreibung wird ab dem Anschaffungsmonat
                    monatsgenau berechnet. Die Nutzungsdauer ist nach den
                    tatsächlichen Verhältnissen festzulegen; die amtlichen
                    <a
                        class="underline underline-offset-2"
                        href="https://www.bundesfinanzministerium.de/Web/DE/Themen/Steuern/Steuerverwaltungu-Steuerrecht/Betriebspruefung/AfA_Tabellen/afa_tabellen.html"
                        target="_blank"
                        rel="noopener noreferrer"
                        >AfA-Tabellen</a
                    >
                    dienen als Schätzhilfe. Eine Sofortabschreibung sollte nur
                    verwendet werden, wenn die jeweiligen steuerlichen
                    Voraussetzungen erfüllt sind.
                </StatusAlert>
            </div>
        </section>

        <div class="flex justify-end gap-3">
            <Button type="button" variant="outline" as-child
                ><Link href="/inventar">Abbrechen</Link></Button
            >
            <Button type="submit" :disabled="createForm.processing">
                <Spinner v-if="createForm.processing" />Inventarisieren
            </Button>
        </div>
    </form>
</template>
