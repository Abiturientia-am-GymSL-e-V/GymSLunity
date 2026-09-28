<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ArrowLeft, Save } from '@lucide/vue';
import InputError from '@/components/InputError.vue';
import StatusAlert from '@/components/StatusAlert.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import type { InventoryItem } from '@/types/inventory';

const props = defineProps<{ item: InventoryItem }>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Inventar', href: '/inventar' }] },
});

const form = useForm({
    location: props.item.location,
    responsible_person: props.item.responsible_person ?? '',
});

function update() {
    form.patch(`/inventar/${encodeURIComponent(props.item.inventory_number)}`);
}
</script>

<template>
    <Head :title="`${item.inventory_number} bearbeiten`" />

    <div class="mx-auto w-full max-w-[1200px] space-y-6 p-4 sm:p-6">
        <header>
            <p class="font-mono text-sm text-muted-foreground">
                {{ item.inventory_number }}
            </p>
            <h1 class="mt-1 text-2xl font-semibold tracking-tight">
                Zuordnung bearbeiten
            </h1>
            <p class="mt-1 text-sm text-muted-foreground">
                Standort und Verantwortlichkeit von {{ item.name }} ändern.
            </p>
        </header>

        <StatusAlert type="info" title="Bewertungsdaten bleiben unverändert">
            Anschaffungswert, Anschaffungsdatum und Abschreibung können hier
            nicht neu bewertet oder erneut gestartet werden.
        </StatusAlert>

        <form class="space-y-6" @submit.prevent="update">
            <section class="rounded-xl border bg-card">
                <div class="border-b px-5 py-4">
                    <h2 class="font-semibold">Aktuelle Zuordnung</h2>
                </div>
                <div class="grid gap-5 p-5 sm:grid-cols-2">
                    <div class="min-w-0 space-y-2">
                        <Label for="inventory-edit-location">Standort *</Label>
                        <Input
                            id="inventory-edit-location"
                            v-model="form.location"
                            maxlength="255"
                            required
                        />
                        <InputError :message="form.errors.location" />
                    </div>
                    <div class="min-w-0 space-y-2">
                        <Label for="inventory-edit-responsible">
                            Verantwortliche Person / Bereich
                        </Label>
                        <Input
                            id="inventory-edit-responsible"
                            v-model="form.responsible_person"
                            maxlength="255"
                        />
                        <InputError :message="form.errors.responsible_person" />
                    </div>
                </div>
            </section>

            <div class="flex flex-wrap justify-end gap-3">
                <Button variant="outline" type="button" as-child>
                    <Link
                        :href="`/inventar/${encodeURIComponent(item.inventory_number)}`"
                    >
                        <ArrowLeft class="size-4" />Abbrechen
                    </Link>
                </Button>
                <Button type="submit" :disabled="form.processing">
                    <Spinner v-if="form.processing" />
                    <Save v-else class="size-4" />Speichern
                </Button>
            </div>
        </form>
    </div>
</template>
