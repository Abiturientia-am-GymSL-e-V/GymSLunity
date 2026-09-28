<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ArrowLeft, FileText, Pencil, Printer, Upload } from '@lucide/vue';
import InputError from '@/components/InputError.vue';
import StatusAlert from '@/components/StatusAlert.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { formatDate, formatDateTime, formatMoney } from '@/lib/format';
import type {
    InventoryDocument,
    InventoryItem,
    InventoryOptions,
} from '@/types/inventory';

const props = defineProps<{
    item: InventoryItem;
    documents: InventoryDocument[];
    options: InventoryOptions;
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Inventar', href: '/inventar' }] },
});

const uploadForm = useForm<{ document: File | null }>({ document: null });

function selectDocument(event: Event) {
    const input = event.target as HTMLInputElement;
    uploadForm.document = input.files?.[0] ?? null;
}

function uploadDocument() {
    uploadForm.post(
        `/inventar/${encodeURIComponent(props.item.inventory_number)}/belege`,
        {
            preserveScroll: true,
            forceFormData: true,
            onSuccess: () => uploadForm.reset(),
        },
    );
}
</script>

<template>
    <Head :title="`${item.inventory_number} · ${item.name}`" />

    <div class="mx-auto w-full max-w-[1200px] space-y-6 p-4 sm:p-6">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <Button
                variant="ghost"
                class="-ml-3 text-muted-foreground"
                as-child
            >
                <Link href="/inventar">
                    <ArrowLeft class="size-4" />Zur Inventarliste
                </Link>
            </Button>
            <Button variant="outline" size="sm" as-child>
                <a
                    :href="`/inventar/${encodeURIComponent(item.inventory_number)}/inventarblatt.pdf`"
                    target="_blank"
                    rel="noopener noreferrer"
                    title="Inventarblatt mit dem gespeicherten Datenstand öffnen"
                >
                    <Printer class="size-4" />Inventarblatt / Ausdruck
                </a>
            </Button>
        </div>

        <header
            class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between"
        >
            <div class="min-w-0">
                <p class="font-mono text-sm text-muted-foreground">
                    {{ item.inventory_number }}
                </p>
                <div class="mt-1 flex flex-wrap items-center gap-3">
                    <h1 class="text-2xl font-semibold tracking-tight">
                        {{ item.name }}
                    </h1>
                    <Badge
                        :variant="
                            item.status === 'active' ? 'outline' : 'secondary'
                        "
                    >
                        {{ options.statuses[item.status] }}
                    </Badge>
                </div>
                <p class="mt-1 text-sm text-muted-foreground">
                    Stammdaten, Bewertung, Zuordnung und hinterlegte Belege.
                </p>
            </div>
            <Button as-child>
                <Link
                    :href="`/inventar/${encodeURIComponent(item.inventory_number)}/bearbeiten`"
                >
                    <Pencil class="size-4" />Bearbeiten
                </Link>
            </Button>
        </header>

        <div class="grid gap-6 lg:grid-cols-2">
            <section class="rounded-xl border bg-card">
                <div class="border-b px-5 py-4">
                    <h2 class="font-semibold">Gegenstand und Zuordnung</h2>
                </div>
                <dl class="grid gap-x-6 gap-y-5 p-5 text-sm sm:grid-cols-2">
                    <div>
                        <dt class="text-muted-foreground">Kategorie</dt>
                        <dd class="mt-1 font-medium">
                            {{ options.categories[item.category] }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground">
                            Hersteller / Modell
                        </dt>
                        <dd class="mt-1 font-medium">
                            {{
                                [item.manufacturer, item.model]
                                    .filter(Boolean)
                                    .join(' · ') || '–'
                            }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground">Seriennummer</dt>
                        <dd class="mt-1 font-medium">
                            {{ item.serial_number || '–' }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground">Standort</dt>
                        <dd class="mt-1 font-medium">{{ item.location }}</dd>
                    </div>
                    <div class="sm:col-span-2">
                        <dt class="text-muted-foreground">Verantwortlich</dt>
                        <dd class="mt-1 font-medium">
                            {{ item.responsible_person || '–' }}
                        </dd>
                    </div>
                    <div v-if="item.description" class="sm:col-span-2">
                        <dt class="text-muted-foreground">
                            Beschreibung / Zustand bei Aufnahme
                        </dt>
                        <dd class="mt-1 whitespace-pre-wrap">
                            {{ item.description }}
                        </dd>
                    </div>
                </dl>
            </section>

            <section class="rounded-xl border bg-card">
                <div class="border-b px-5 py-4">
                    <h2 class="font-semibold">Anschaffung und Bewertung</h2>
                </div>
                <dl class="grid gap-x-6 gap-y-5 p-5 text-sm sm:grid-cols-2">
                    <div>
                        <dt class="text-muted-foreground">Zugangsart</dt>
                        <dd class="mt-1 font-medium">
                            {{
                                options.acquisitionTypes[item.acquisition_type]
                            }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground">Anschaffung am</dt>
                        <dd class="mt-1 font-medium">
                            {{ formatDate(item.acquisition_date) }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground">Anschaffungswert</dt>
                        <dd class="mt-1 font-medium">
                            {{ formatMoney(item.acquisition_cost_cents) }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground">
                            {{
                                item.status === 'active'
                                    ? 'Aktueller Restwert'
                                    : 'Restwert bei Abgang'
                            }}
                        </dt>
                        <dd class="mt-1 font-medium">
                            {{ formatMoney(item.book_value_cents) }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground">Abschreibung</dt>
                        <dd class="mt-1 font-medium">
                            {{
                                options.depreciationMethods[
                                    item.depreciation_method
                                ]
                            }}
                            <template v-if="item.useful_life_years">
                                · {{ item.useful_life_years }} Jahre
                            </template>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground">Beleg / Referenz</dt>
                        <dd class="mt-1 font-medium">
                            {{ item.document_reference || '–' }}
                        </dd>
                    </div>
                </dl>
            </section>
        </div>

        <section
            v-if="item.status !== 'active'"
            class="rounded-xl border bg-card"
        >
            <div class="border-b px-5 py-4">
                <h2 class="font-semibold">Dokumentierter Abgang</h2>
            </div>
            <dl class="grid gap-x-6 gap-y-5 p-5 text-sm sm:grid-cols-3">
                <div>
                    <dt class="text-muted-foreground">Art</dt>
                    <dd class="mt-1 font-medium">
                        {{ options.statuses[item.status] }}
                    </dd>
                </div>
                <div>
                    <dt class="text-muted-foreground">Datum</dt>
                    <dd class="mt-1 font-medium">
                        {{ formatDate(item.disposed_at) }}
                    </dd>
                </div>
                <div>
                    <dt class="text-muted-foreground">Verkaufserlös</dt>
                    <dd class="mt-1 font-medium">
                        {{
                            item.disposal_proceeds_cents === null
                                ? '–'
                                : formatMoney(item.disposal_proceeds_cents)
                        }}
                    </dd>
                </div>
                <div v-if="item.disposal_note" class="sm:col-span-3">
                    <dt class="text-muted-foreground">Vermerk</dt>
                    <dd class="mt-1 whitespace-pre-wrap">
                        {{ item.disposal_note }}
                    </dd>
                </div>
            </dl>
        </section>

        <section class="rounded-xl border bg-card">
            <div class="border-b px-5 py-4">
                <h2 class="font-semibold">Belege</h2>
                <p class="mt-1 text-sm text-muted-foreground">
                    Rechnungen und weitere Nachweise werden verschlüsselt als
                    PDF gespeichert.
                </p>
            </div>
            <div class="space-y-5 p-5">
                <StatusAlert
                    v-if="!documents.length"
                    type="info"
                    title="Noch kein Beleg hinterlegt"
                >
                    Über das Formular kann eine Rechnung oder ein anderer
                    PDF-Beleg ergänzt werden.
                </StatusAlert>
                <ul v-else class="divide-y rounded-lg border">
                    <li
                        v-for="document in documents"
                        :key="document.id"
                        class="flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between"
                    >
                        <div class="min-w-0">
                            <p class="flex items-center gap-2 font-medium">
                                <FileText class="size-4 shrink-0" />
                                <span class="truncate">{{
                                    document.original_name
                                }}</span>
                            </p>
                            <p class="mt-1 text-sm text-muted-foreground">
                                {{ formatDateTime(document.created_at) }} ·
                                {{ document.uploaded_by_name }}
                            </p>
                        </div>
                        <Button variant="outline" size="sm" as-child>
                            <a :href="document.url" target="_blank">
                                <FileText class="size-4" />Öffnen
                            </a>
                        </Button>
                    </li>
                </ul>

                <form class="space-y-2" @submit.prevent="uploadDocument">
                    <Label for="inventory-document-upload">
                        Beleg hinzufügen (PDF, max. 10 MB)
                    </Label>
                    <div class="grid gap-3 sm:grid-cols-[minmax(0,1fr)_auto]">
                        <Input
                            id="inventory-document-upload"
                            type="file"
                            accept="application/pdf,.pdf"
                            required
                            @change="selectDocument"
                        />
                        <Button
                            type="submit"
                            class="h-9"
                            :disabled="
                                uploadForm.processing || !uploadForm.document
                            "
                        >
                            <Spinner v-if="uploadForm.processing" />
                            <Upload v-else class="size-4" />Beleg hochladen
                        </Button>
                    </div>
                    <InputError :message="uploadForm.errors.document" />
                </form>
            </div>
        </section>
    </div>
</template>
