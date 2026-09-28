<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { PackagePlus, PackageSearch } from '@lucide/vue';
import { ref } from 'vue';
import InputError from '@/components/InputError.vue';
import InventoryCreateForm from '@/components/inventory/InventoryCreateForm.vue';
import InventoryOverview from '@/components/inventory/InventoryOverview.vue';
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
import type {
    InventoryItem,
    InventoryOptions,
    InventoryStatus,
    InventorySummary,
} from '@/types/inventory';

const props = defineProps<{
    activeTab: 'overview' | 'create';
    items: InventoryItem[];
    options: InventoryOptions;
    summary: InventorySummary;
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Inventar', href: '/inventar' }] },
});

const tabs = [
    ['overview', 'Übersicht', PackageSearch, '/inventar'],
    ['create', 'Inventarisieren', PackagePlus, '/inventar/inventarisieren'],
] as const;

const today = new Intl.DateTimeFormat('sv-SE').format(new Date());
const disposalOpen = ref(false);
const retiring = ref<InventoryItem | null>(null);
const disposalForm = useForm({
    status: 'sold' as Exclude<InventoryStatus, 'active'>,
    disposed_at: today,
    disposal_proceeds: '',
    disposal_note: '',
});
function openDisposal(item: InventoryItem) {
    retiring.value = item;
    disposalForm.defaults({
        status: 'sold',
        disposed_at: today,
        disposal_proceeds: '',
        disposal_note: '',
    });
    disposalForm.reset();
    disposalForm.clearErrors();
    disposalOpen.value = true;
}
function dispose() {
    if (!retiring.value) return;
    disposalForm.patch(
        `/inventar/${encodeURIComponent(retiring.value.inventory_number)}/abgang`,
        {
            preserveScroll: true,
            onSuccess: () => {
                disposalOpen.value = false;
                retiring.value = null;
            },
        },
    );
}
function closeDisposal(value: boolean) {
    if (!value && disposalForm.processing) return;
    if (
        !value &&
        disposalForm.isDirty &&
        !window.confirm('Ungespeicherte Abgangsdaten verwerfen?')
    )
        return;
    disposalOpen.value = value;
}
</script>

<template>
    <Head title="Inventar" />

    <div class="mx-auto w-full max-w-[1200px] space-y-6 p-4 sm:p-6">
        <header>
            <h1 class="text-2xl font-semibold tracking-tight">Inventar</h1>
            <p class="mt-1 text-sm text-muted-foreground">
                Vereinsvermögen eindeutig erfassen, bewerten und Abgänge
                nachvollziehbar dokumentieren.
            </p>
        </header>

        <nav
            class="flex flex-wrap gap-2 border-b pb-4"
            aria-label="Inventarbereiche"
        >
            <Link
                v-for="tab in tabs"
                :key="tab[0]"
                :href="tab[3]"
                :aria-current="activeTab === tab[0] ? 'page' : undefined"
                prefetch
                class="flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-medium transition-colors hover:bg-muted"
                :class="
                    activeTab === tab[0]
                        ? 'bg-muted text-foreground'
                        : 'text-muted-foreground'
                "
            >
                <component :is="tab[2]" class="size-4" />{{ tab[1] }}
            </Link>
        </nav>

        <template v-if="activeTab === 'overview'">
            <InventoryOverview
                :items="items"
                :options="options"
                :summary="summary"
                @dispose="openDisposal"
            />
        </template>

        <template v-else>
            <InventoryCreateForm :options="options" />
        </template>
    </div>

    <Dialog :open="disposalOpen" @update:open="closeDisposal">
        <DialogContent class="sm:max-w-lg">
            <DialogHeader>
                <DialogTitle>Abgang erfassen</DialogTitle>
                <DialogDescription v-if="retiring">
                    {{ retiring.inventory_number }} · {{ retiring.name }} bleibt
                    mit seinen Abgangsdaten im Inventar erhalten.
                </DialogDescription>
            </DialogHeader>
            <form class="space-y-4" @submit.prevent="dispose">
                <InputError
                    :message="disposalForm.errors.status"
                    role="alert"
                />
                <div class="space-y-2">
                    <Label for="disposal-status">Art des Abgangs *</Label>
                    <select
                        id="disposal-status"
                        v-model="disposalForm.status"
                        required
                        class="h-9 w-full rounded-md border border-input bg-background px-3 text-sm shadow-xs"
                    >
                        <option value="sold">Verkauft</option>
                        <option value="lost">Verlust</option>
                        <option value="disposed">Entsorgt</option>
                    </select>
                </div>
                <div class="space-y-2">
                    <Label for="disposal-date">Abgangsdatum *</Label>
                    <Input
                        id="disposal-date"
                        v-model="disposalForm.disposed_at"
                        type="date"
                        :min="retiring?.acquisition_date"
                        :max="today"
                        required
                    />
                    <InputError :message="disposalForm.errors.disposed_at" />
                </div>
                <div v-if="disposalForm.status === 'sold'" class="space-y-2">
                    <Label for="disposal-proceeds">Verkaufserlös *</Label>
                    <div class="relative">
                        <Input
                            id="disposal-proceeds"
                            v-model="disposalForm.disposal_proceeds"
                            inputmode="decimal"
                            maxlength="12"
                            required
                            class="pr-10"
                            placeholder="0,00"
                        />
                        <span
                            class="absolute top-2 right-3 text-sm text-muted-foreground"
                            >€</span
                        >
                    </div>
                    <InputError
                        :message="disposalForm.errors.disposal_proceeds"
                    />
                </div>
                <div class="space-y-2">
                    <Label for="disposal-note">Vermerk</Label>
                    <textarea
                        id="disposal-note"
                        v-model="disposalForm.disposal_note"
                        rows="3"
                        maxlength="2000"
                        class="w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                        placeholder="z. B. Käufer, Schadenshergang oder Entsorgungsnachweis"
                    ></textarea>
                    <InputError :message="disposalForm.errors.disposal_note" />
                </div>
                <DialogFooter>
                    <Button
                        type="button"
                        variant="outline"
                        @click="closeDisposal(false)"
                        >Abbrechen</Button
                    >
                    <Button type="submit" :disabled="disposalForm.processing">
                        <Spinner v-if="disposalForm.processing" />Abgang
                        speichern
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
